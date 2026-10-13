/**
 * Local Drive Manager - Client Script
 * Handles Drive Auto-Detection, Explorer Navigation, Chunked Upload,
 * Zip Downloads, In-browser Previews & File Operations.
 */

class DriveApp {
  constructor() {
    this.currentPath = '';
    this.currentDriveId = '';
    this.drives = [];
    this.items = [];
    this.filteredItems = [];
    this.viewMode = localStorage.getItem('drive_view_mode') || 'grid'; // 'grid' | 'gallery' | 'list'
    this.activeUploads = new Map();
    this.selectedItem = null;
    this.historyStack = [];
    this.historyIndex = -1;
    this.dirCache = new Map();
    this.imageObserver = null;
    this.thumbnailQueue = [];
    this.thumbnailRequests = new Map();
    this.thumbnailUrls = new Set();
    this.uploadChain = Promise.resolve();
    this.selectedPaths = new Set();

    this.initImageObserver();
    this.initElements();
    this.initEventListeners();
    this.initDrivePolling();
    this.loadDrives(true);
  }

  initImageObserver() {
    if ('IntersectionObserver' in window) {
      this.imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            const img = entry.target;
            const src = img.getAttribute('data-src');
            if (src) {
              this.enqueueThumbnail(img, src);
            }
            observer.unobserve(img);
          }
        });
      }, {
        rootMargin: '100px 0px',
        threshold: 0.01
      });
    }
  }

  observeImages(container) {
    if (!container) return;
    const lazyImages = container.querySelectorAll('img[data-src]');
    lazyImages.forEach(img => {
      img.onerror = () => { img.outerHTML = this.getFileIconSvg('image', false); };
      if (this.imageObserver) {
        this.imageObserver.observe(img);
      } else {
        this.enqueueThumbnail(img, img.getAttribute('data-src'));
      }
    });
  }

  enqueueThumbnail(img, src) {
    img.removeAttribute('data-src');
    this.thumbnailQueue.push({ img, src });
    this.pumpThumbnails();
  }

  cancelThumbnails() {
    this.imageObserver?.disconnect();
    this.thumbnailQueue = [];
    this.thumbnailRequests.forEach(controller => controller.abort());
    this.thumbnailUrls.forEach(url => URL.revokeObjectURL(url));
    this.thumbnailUrls.clear();
  }

  pumpThumbnails() {
    while (this.thumbnailRequests.size < 4 && this.thumbnailQueue.length) {
      const { img, src } = this.thumbnailQueue.shift();
      if (!img.isConnected) continue;
      const controller = new AbortController();
      this.thumbnailRequests.set(img, controller);
      this.fetchWithRetry(src, { signal: controller.signal }).then(response => response.blob()).then(blob => {
        if (!img.isConnected || controller.signal.aborted) return;
        const url = URL.createObjectURL(blob);
        this.thumbnailUrls.add(url);
        img.onload = () => img.classList.add('loaded');
        img.src = url;
      }).catch(error => {
        if (error.name !== 'AbortError' && img.isConnected) img.dispatchEvent(new Event('error'));
      }).finally(() => {
        this.thumbnailRequests.delete(img);
        this.pumpThumbnails();
      });
    }
  }

  async fetchWithRetry(url, options = {}) {
    for (let attempt = 0; attempt < 4; attempt++) {
      const timeout = AbortSignal.timeout(30000);
      const controller = new AbortController();
      const abort = () => controller.abort();
      options.signal?.addEventListener('abort', abort, { once: true });
      timeout.addEventListener('abort', abort, { once: true });
      if (options.signal?.aborted) controller.abort();
      let retryDelay = 500 * (2 ** attempt);
      try {
        const response = await fetch(url, { ...options, signal: controller.signal });
        if (response.status === 401) { window.location.href = 'login.php'; throw new Error('Silakan login kembali.'); }
        if (response.status !== 503 && response.status !== 429) {
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          // Keep timeout and cancellation active until the small image/JSON body is received.
          const body = await response.arrayBuffer();
          return new Response(body, { status: response.status, statusText: response.statusText, headers: response.headers });
        }
        retryDelay = Math.min(5000, (Number(response.headers.get('Retry-After')) || 1) * 1000);
        await response.body?.cancel();
      } catch (error) {
        if (options.signal?.aborted || (error.name !== 'TypeError' && !timeout.aborted)) throw error;
        if (attempt === 3) throw error;
      } finally {
        options.signal?.removeEventListener('abort', abort);
        timeout.removeEventListener('abort', abort);
      }
      if (attempt === 3) throw new Error('Server sedang sibuk. Coba lagi sebentar.');
      await new Promise(resolve => setTimeout(resolve, retryDelay + Math.random() * 250));
      if (options.signal?.aborted) throw new DOMException('Dibatalkan', 'AbortError');
    }
  }

  initElements() {
    this.el = {
      driveList: document.getElementById('driveList'),
      driveCountBadge: document.getElementById('driveCountBadge'),
      btnRefreshDrives: document.getElementById('btnRefreshDrives'),
      breadcrumbs: document.getElementById('breadcrumbs'),
      folderTitle: document.getElementById('folderTitle'),
      folderStats: document.getElementById('folderStats'),
      fileGrid: document.getElementById('fileGrid'),
      fileGallery: document.getElementById('fileGallery'),
      fileListTable: document.getElementById('fileListTable'),
      fileListTbody: document.getElementById('fileListTbody'),
      emptyState: document.getElementById('emptyState'),
      searchInput: document.getElementById('searchInput'),
      btnUploadFile: document.getElementById('btnUploadFile'),
      btnUploadFolder: document.getElementById('btnUploadFolder'),
      btnNewFolder: document.getElementById('btnNewFolder'),
      btnDownloadZip: document.getElementById('btnDownloadZip'),
      btnRefreshFolder: document.getElementById('btnRefreshFolder'),
      btnViewGrid: document.getElementById('btnViewGrid'),
      btnViewGallery: document.getElementById('btnViewGallery'),
      btnViewList: document.getElementById('btnViewList'),
      btnNavBack: document.getElementById('btnNavBack'),
      btnNavForward: document.getElementById('btnNavForward'),
      btnNavUp: document.getElementById('btnNavUp'),
      fileInput: document.getElementById('fileInput'),
      folderInput: document.getElementById('folderInput'),
      dropOverlay: document.getElementById('dropOverlay'),
      uploadDrawer: document.getElementById('uploadDrawer'),
      uploadQueueList: document.getElementById('uploadQueueList'),
      btnCloseUploadDrawer: document.getElementById('btnCloseUploadDrawer'),
      toastContainer: document.getElementById('toastContainer'),

      // Bulk Action Elements
      bulkActionBar: document.getElementById('bulkActionBar'),
      bulkCount: document.getElementById('bulkCount'),
      btnSelectAll: document.getElementById('btnSelectAll'),
      btnBulkDownload: document.getElementById('btnBulkDownload'),
      btnBulkCompress: document.getElementById('btnBulkCompress'),
      btnBulkDelete: document.getElementById('btnBulkDelete'),
      btnDeselectAll: document.getElementById('btnDeselectAll'),
      selectAllCheckbox: document.getElementById('selectAllCheckbox'),

      // Modals
      newFolderModal: document.getElementById('newFolderModal'),
      newFolderNameInput: document.getElementById('newFolderNameInput'),
      btnConfirmNewFolder: document.getElementById('btnConfirmNewFolder'),

      renameModal: document.getElementById('renameModal'),
      renameInput: document.getElementById('renameInput'),
      btnConfirmRename: document.getElementById('btnConfirmRename'),

      deleteModal: document.getElementById('deleteModal'),
      deleteItemName: document.getElementById('deleteItemName'),
      btnConfirmDelete: document.getElementById('btnConfirmDelete'),

      compressModal: document.getElementById('compressModal'),
      compressZipNameInput: document.getElementById('compressZipNameInput'),
      compressItemName: document.getElementById('compressItemName'),
      btnConfirmCompress: document.getElementById('btnConfirmCompress'),

      extractModal: document.getElementById('extractModal'),
      extractFolderNameInput: document.getElementById('extractFolderNameInput'),
      extractItemName: document.getElementById('extractItemName'),
      btnConfirmExtract: document.getElementById('btnConfirmExtract'),

      previewModal: document.getElementById('previewModal'),
      previewTitle: document.getElementById('previewTitle'),
      previewContainer: document.getElementById('previewContainer'),
      btnDownloadPreview: document.getElementById('btnDownloadPreview'),
    };
  }

  initEventListeners() {
    // Refresh drives
    this.el.btnRefreshDrives?.addEventListener('click', () => {
      this.el.btnRefreshDrives.classList.add('rotating');
      this.loadDrives(false).finally(() => {
        setTimeout(() => this.el.btnRefreshDrives.classList.remove('rotating'), 600);
      });
    });

    // Refresh current folder
    this.el.btnRefreshFolder?.addEventListener('click', () => this.loadDirectory(this.currentPath));

    // Nav Up
    this.el.btnNavUp?.addEventListener('click', () => {
      if (this.parentPath) this.navigate(this.parentPath);
    });

    // Search input
    this.el.searchInput?.addEventListener('input', (e) => this.handleSearch(e.target.value));

    // View switchers
    this.el.btnViewGrid?.addEventListener('click', () => this.setViewMode('grid'));
    this.el.btnViewGallery?.addEventListener('click', () => this.setViewMode('gallery'));
    this.el.btnViewList?.addEventListener('click', () => this.setViewMode('list'));

    // Upload File button
    this.el.btnUploadFile?.addEventListener('click', () => this.el.fileInput?.click());
    this.el.fileInput?.addEventListener('change', (e) => {
      if (e.target.files && e.target.files.length > 0) {
        this.handleFilesUpload(Array.from(e.target.files));
        this.el.fileInput.value = '';
      }
    });

    // Upload Folder button
    this.el.btnUploadFolder?.addEventListener('click', () => this.el.folderInput?.click());
    this.el.folderInput?.addEventListener('change', (e) => {
      if (e.target.files && e.target.files.length > 0) {
        const entries = Array.from(e.target.files).map(f => ({
          file: f,
          relativePath: f.webkitRelativePath || f.name
        }));
        this.handleFilesUpload(entries);
        this.el.folderInput.value = '';
      }
    });

    // Bulk Action Events
    this.el.btnSelectAll?.addEventListener('click', () => this.selectAllItems());
    this.el.selectAllCheckbox?.addEventListener('change', (e) => {
      if (e.target.checked) this.selectAllItems();
      else this.deselectAllItems();
    });
    this.el.btnDeselectAll?.addEventListener('click', () => this.deselectAllItems());
    this.el.btnBulkDownload?.addEventListener('click', () => this.downloadBulk());
    this.el.btnBulkCompress?.addEventListener('click', () => this.compressBulk());
    this.el.btnBulkDelete?.addEventListener('click', () => this.deleteBulk());

    // New Folder
    this.el.btnNewFolder?.addEventListener('click', () => this.openNewFolderModal());
    this.el.btnConfirmNewFolder?.addEventListener('click', () => this.submitNewFolder());
    this.el.newFolderNameInput?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') this.submitNewFolder();
    });

    // Rename
    this.el.btnConfirmRename?.addEventListener('click', () => this.submitRename());
    this.el.renameInput?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') this.submitRename();
    });

    // Delete
    this.el.btnConfirmDelete?.addEventListener('click', () => this.submitDelete());

    // Compress to ZIP
    this.el.btnConfirmCompress?.addEventListener('click', () => this.submitCompress());
    this.el.compressZipNameInput?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') this.submitCompress();
    });

    // Extract Archive
    this.el.btnConfirmExtract?.addEventListener('click', () => this.submitExtract());
    this.el.extractFolderNameInput?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') this.submitExtract();
    });

    // Download Folder as ZIP
    this.el.btnDownloadZip?.addEventListener('click', () => {
      this.downloadItem(this.currentPath, true);
    });

    // Close upload drawer
    this.el.btnCloseUploadDrawer?.addEventListener('click', () => {
      this.el.uploadDrawer.classList.remove('show');
    });

    // Drag & drop on window with recursive folder extraction
    window.addEventListener('dragover', (e) => {
      if (this.internalDrag || !Array.from(e.dataTransfer?.types || []).includes('Files')) return;
      e.preventDefault();
      this.el.dropOverlay.classList.add('active');
    });

    this.el.dropOverlay.addEventListener('dragleave', (e) => {
      e.preventDefault();
      this.el.dropOverlay.classList.remove('active');
    });
    window.addEventListener('dragleave', (event) => {
      if (!event.relatedTarget) this.el.dropOverlay.classList.remove('active');
    });

    window.addEventListener('drop', async (e) => {
      e.preventDefault();
      this.el.dropOverlay.classList.remove('active');
      if (this.internalDrag) return;
      if (e.dataTransfer) {
        const entries = await this.parseDropItems(e.dataTransfer);
        if (entries && entries.length > 0) {
          this.handleFilesUpload(entries);
        }
      }
    });

    // Close modals on click overlay or Esc
    document.querySelectorAll('.modal-overlay').forEach(modal => {
      modal.addEventListener('click', (e) => {
        if (e.target === modal) this.closeModals();
      });
    });

    document.querySelectorAll('.btn-close-modal').forEach(btn => {
      btn.addEventListener('click', () => this.closeModals());
    });

    window.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') this.closeModals();
    });
  }

  /**
   * Periodically poll for drive changes (USB Flashdisk plugged / unplugged)
   */
  initDrivePolling() {
    this.isPollingDrives = false;
    setInterval(() => {
      if (!document.hidden && !this.isPollingDrives) {
        this.loadDrives(false, true);
      }
    }, 15000);
  }

  async loadDrives(isInitial = false, isBackground = false) {
    if (this.isPollingDrives && isBackground) return;
    this.isPollingDrives = true;

    try {
      const res = await fetch('api.php?action=get_drives');
      if (res.status === 401) {
        window.location.href = 'login.php';
        return;
      }
      const data = await res.json();
      if (data.auth_required) {
        window.location.href = 'login.php';
        return;
      }

      if (data.success && Array.isArray(data.drives)) {
        const oldDriveIds = new Set(this.drives.map(d => d.id));
        const newDrives = data.drives;

        // Detect newly plugged flashdisk or removed drive
        if (!isInitial && isBackground) {
          newDrives.forEach(d => {
            if (!oldDriveIds.has(d.id)) {
              if (d.isRemovable) {
                this.showToast(`Flashdisk Baru Terdeteksi: ${d.label} (${d.id})`, 'info');
              } else {
                this.showToast(`Drive Baru Terhubung: ${d.id}`, 'info');
              }
            }
          });
        }

        this.drives = newDrives;
        this.renderDriveList();

        if (isInitial) {
          if (newDrives.length > 0) {
            // Pilih flashdisk jika ada, jika tidak buka drive pertama
            const usbDrive = newDrives.find(d => d.isRemovable);
            const targetDrive = usbDrive || newDrives[0];
            this.navigate(targetDrive.path);
          } else {
            if (this.el.folderTitle) this.el.folderTitle.textContent = 'Belum Ada Drive Terhubung';
            if (this.el.folderStats) this.el.folderStats.textContent = '0 item';
            if (this.el.emptyState) this.el.emptyState.style.display = 'flex';
            if (this.el.fileGrid) this.el.fileGrid.style.display = 'none';
            if (this.el.fileGallery) this.el.fileGallery.style.display = 'none';
            if (this.el.fileListTable) this.el.fileListTable.style.display = 'none';
          }
        }
      }
    } catch (err) {
      if (!isBackground) {
        this.showToast('Gagal memuat daftar drive: ' + err.message, 'error');
      }
      if (isInitial) {
        if (this.el.driveCountBadge) this.el.driveCountBadge.textContent = '0 Terhubung';
        if (this.el.folderTitle) this.el.folderTitle.textContent = 'Belum Ada Drive Terhubung';
        if (this.el.folderStats) this.el.folderStats.textContent = 'Gagal memuat drive';
        if (this.el.emptyState) this.el.emptyState.style.display = 'flex';
      }
    } finally {
      this.isPollingDrives = false;
    }
  }

  renderDriveList() {
    if (!this.el.driveList) return;
    this.el.driveList.innerHTML = '';
    if (this.el.driveCountBadge) this.el.driveCountBadge.textContent = `${this.drives.length} Terhubung`;

    if (this.drives.length === 0) {
      this.el.driveList.innerHTML = `
        <div style="padding: 24px 16px; text-align: center; color: var(--text-muted); font-size: 0.85rem; line-height: 1.5;">
          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin: 0 auto 10px; display: block; opacity: 0.5;">
            <path d="M10 13v-3a2 2 0 0 1 4 0v3"/><path d="M12 7V2"/><path d="M6 13h12a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2z"/>
          </svg>
          <strong style="color: var(--text-primary); display: block; margin-bottom: 4px;">Flashdisk Belum Terdeteksi</strong>
          Drive C: telah disembunyikan. Silakan colokkan Flashdisk (USB) ke laptop.
        </div>
      `;
      return;
    }

    this.drives.forEach(drive => {
      const isUsb = drive.isRemovable;
      const isCurrentDrive = this.currentPath.toUpperCase().startsWith(drive.id.toUpperCase());

      const card = document.createElement('div');
      card.className = `drive-card ${isUsb ? 'usb-drive' : ''} ${isCurrentDrive ? 'active' : ''}`;
      
      const percent = drive.usedPercentage;
      const isWarning = percent > 85;

      const driveSvg = isUsb 
        ? `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13v-3a2 2 0 0 1 4 0v3"/><path d="M12 7V2"/><path d="M6 13h12a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2z"/></svg>`
        : `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" y1="16" x2="6.01" y2="16"/><line x1="10" y1="16" x2="10.01" y2="16"/></svg>`;

      card.innerHTML = `
        <div class="drive-card-header">
          <div class="drive-info-left">
            <div class="drive-icon-badge">${driveSvg}</div>
            <div>
              <div class="drive-name" title="${drive.label}">${drive.label}</div>
              <div class="drive-letter">${drive.id} ${drive.fileSystem ? `(${drive.fileSystem})` : ''}</div>
            </div>
          </div>
          ${isUsb ? `<span class="usb-tag">USB</span>` : ''}
        </div>
        <div class="drive-progress-bg">
          <div class="drive-progress-fill ${isWarning ? 'warning' : ''}" style="width: ${percent}%"></div>
        </div>
        <div class="drive-capacity-text">
          <span>${drive.freeFormatted} Bebas</span>
          <span>${drive.totalFormatted}</span>
        </div>
      `;

      card.addEventListener('click', () => {
        this.navigate(drive.path);
      });

      this.bindDragItem(card, { isDir: true, path: drive.path }, false);
      this.el.driveList.appendChild(card);
    });
  }

  async navigate(path) {
    if (!path) return;
    this.currentPath = path;
    await this.loadDirectory(path);
    this.renderDriveList();
  }

  async loadDirectory(path, forceRefresh = false) {
    if (!path) return;
    const cacheKey = path.toUpperCase();

    // Tampilkan data dari memori cache secara instan (0ms) jika ada
    if (!forceRefresh && this.dirCache.has(cacheKey)) {
      const cached = this.dirCache.get(cacheKey);
      this.currentPath = cached.currentPath;
      this.parentPath = cached.parentPath;
      this.items = cached.items || [];
      this.filteredItems = [...this.items];

      this.updateBreadcrumbs();
      this.updateMetaHeader(cached);
      this.renderItems();
    } else {
      if (this.el.folderTitle) this.el.folderTitle.textContent = 'Memuat berkas...';
    }

    try {
      const res = await fetch(`api.php?action=list_files&path=${encodeURIComponent(path)}`);
      if (res.status === 401) {
        window.location.href = 'login.php';
        return;
      }
      const data = await res.json();
      if (data.auth_required) {
        window.location.href = 'login.php';
        return;
      }

      if (!data.success) {
        this.showToast(data.message || 'Gagal membuka direktori', 'error');
        const folderName = path.replace(/[/\\]$/, '').split(/[/\\]/).pop() || path;
        if (this.el.folderTitle) this.el.folderTitle.textContent = folderName;
        if (this.el.folderStats) this.el.folderStats.textContent = data.message || 'Akses ditolak';
        this.items = [];
        this.filteredItems = [];
        this.renderItems();
        return;
      }

      this.dirCache.set(cacheKey, data);
      this.currentPath = data.currentPath;
      this.parentPath = data.parentPath;
      this.items = data.items || [];
      this.filteredItems = [...this.items];

      this.updateBreadcrumbs();
      this.updateMetaHeader(data);
      this.renderItems();
    } catch (err) {
      if (!this.dirCache.has(cacheKey)) {
        this.showToast('Gagal memuat isi folder: ' + err.message, 'error');
        const folderName = path.replace(/[/\\]$/, '').split(/[/\\]/).pop() || path;
        if (this.el.folderTitle) this.el.folderTitle.textContent = folderName;
        if (this.el.folderStats) this.el.folderStats.textContent = 'Gagal memuat folder';
        this.items = [];
        this.filteredItems = [];
        this.renderItems();
      }
    }
  }

  updateBreadcrumbs() {
    if (!this.el.breadcrumbs) return;
    this.el.breadcrumbs.innerHTML = '';
    const cleanPath = this.currentPath.replace(/[/\\]+/g, '\\');
    const parts = cleanPath.split('\\').filter(p => p.length > 0);

    let cumulativePath = '';
    parts.forEach((part, index) => {
      if (index === 0) {
        cumulativePath = part + '\\';
      } else {
        cumulativePath += (cumulativePath.endsWith('\\') ? '' : '\\') + part;
      }

      const itemEl = document.createElement('span');
      itemEl.className = `breadcrumb-item ${index === parts.length - 1 ? 'active' : ''}`;
      itemEl.textContent = part;

      const pathTarget = cumulativePath;
      this.bindDragItem(itemEl, { isDir: true, path: pathTarget }, false);
      if (index !== parts.length - 1) {
        itemEl.addEventListener('click', () => this.navigate(pathTarget));
      }

      this.el.breadcrumbs.appendChild(itemEl);

      if (index < parts.length - 1) {
        const sep = document.createElement('span');
        sep.className = 'breadcrumb-separator';
        sep.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>`;
        this.el.breadcrumbs.appendChild(sep);
      }
    });
  }

  updateMetaHeader(data) {
    const folderName = this.currentPath.replace(/[/\\]$/, '').split(/[/\\]/).pop() || this.currentPath;
    if (this.el.folderTitle) this.el.folderTitle.textContent = folderName;
    if (this.el.folderStats) this.el.folderStats.textContent = `${data.totalItems || 0} item (${data.folderSizeFormatted || '0 B'}) • Sisa Drive: ${data.driveFreeFormatted || '-'}`;
    if (this.el.btnNavUp) this.el.btnNavUp.disabled = !this.parentPath;
  }

  renderItems() {
    this.cancelThumbnails();
    if (this.filteredItems.length === 0) {
      if (this.el.emptyState) this.el.emptyState.style.display = 'flex';
      if (this.el.fileGrid) this.el.fileGrid.style.display = 'none';
      if (this.el.fileGallery) this.el.fileGallery.style.display = 'none';
      if (this.el.fileListTable) this.el.fileListTable.style.display = 'none';
      return;
    }

    if (this.el.emptyState) this.el.emptyState.style.display = 'none';

    if (this.viewMode === 'gallery') {
      if (this.el.fileGrid) this.el.fileGrid.style.display = 'none';
      if (this.el.fileGallery) this.el.fileGallery.style.display = 'grid';
      if (this.el.fileListTable) this.el.fileListTable.style.display = 'none';
      this.renderGalleryView();
    } else if (this.viewMode === 'list') {
      if (this.el.fileGrid) this.el.fileGrid.style.display = 'none';
      if (this.el.fileGallery) this.el.fileGallery.style.display = 'none';
      if (this.el.fileListTable) this.el.fileListTable.style.display = 'table';
      this.renderListView();
    } else {
      if (this.el.fileGrid) this.el.fileGrid.style.display = 'grid';
      if (this.el.fileGallery) this.el.fileGallery.style.display = 'none';
      if (this.el.fileListTable) this.el.fileListTable.style.display = 'none';
      this.renderGridView();
    }
  }

  getFileTypeCategory(item) {
    if (item.isDir) return 'dir';
    const ext = item.extension;
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'].includes(ext)) return 'image';
    if (['mp4', 'mkv', 'webm', 'mov', 'avi'].includes(ext)) return 'video';
    if (['mp3', 'wav', 'flac', 'aac', 'ogg'].includes(ext)) return 'audio';
    if (['pdf'].includes(ext)) return 'pdf';
    if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) return 'archive';
    if (['php', 'js', 'json', 'html', 'css', 'ts', 'py', 'sql', 'c', 'cpp', 'java', 'xml', 'md'].includes(ext)) return 'code';
    if (['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'].includes(ext)) return 'doc';
    return 'other';
  }

  getFileIconSvg(category, isDir = false) {
    if (isDir) {
      return `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>`;
    }
    switch (category) {
      case 'image':
        return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>`;
      case 'video':
        return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>`;
      case 'audio':
        return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>`;
      case 'pdf':
        return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>`;
      case 'archive':
        return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>`;
      case 'code':
        return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>`;
      default:
        return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>`;
    }
  }

  renderGridView() {
    this.el.fileGrid.innerHTML = '';
    this.filteredItems.forEach(item => {
      const type = this.getFileTypeCategory(item);
      const isImage = type === 'image';
      const isArchive = type === 'archive';
      const isSelected = this.selectedPaths.has(item.path);
      const thumbUrl = `api.php?action=thumb&v=2&path=${encodeURIComponent(item.path)}`;

      const card = document.createElement('div');
      card.className = `file-card ${item.isDir ? 'is-dir' : `type-${type}`} ${isSelected ? 'selected' : ''}`;

      card.innerHTML = `
        <div class="file-card-checkbox-wrap">
          <input type="checkbox" class="file-select-checkbox" ${isSelected ? 'checked' : ''}>
        </div>
        <div class="file-actions-hover">
          ${!item.isDir ? `<button class="btn-action-icon preview-btn" title="Lihat Preview"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>` : ''}
          ${isArchive ? `<button class="btn-action-icon extract-btn" title="Ekstrak Arsip (ZIP/RAR)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><polyline points="10 12 15 12 15 7"/><line x1="15" y1="12" x2="9" y2="18"/></svg></button>` : ''}
          <button class="btn-action-icon compress-btn" title="Kompres ke ZIP"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg></button>
          <button class="btn-action-icon download-btn" title="Unduh ${item.isDir ? 'sebagai ZIP' : ''}"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></button>
          <button class="btn-action-icon rename-btn" title="Ganti Nama"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></button>
          <button class="btn-action-icon delete-btn" title="Hapus"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
        </div>
        <div class="file-thumb">
          ${isImage ? `<img data-src="${thumbUrl}" class="file-card-img-thumb" decoding="async" alt="${item.name}">` : this.getFileIconSvg(type, item.isDir)}
        </div>
        <div class="file-name" title="${item.name}">${item.name}</div>
        <div class="file-details">${item.isDir ? 'Folder' : item.sizeFormatted}</div>
      `;

      // Checkbox event
      const chk = card.querySelector('.file-select-checkbox');
      chk?.addEventListener('change', (e) => {
        e.stopPropagation();
        this.toggleSelect(item.path, e.target.checked);
      });
      chk?.addEventListener('click', (e) => e.stopPropagation());

      card.addEventListener('click', (e) => {
        if (e.target.closest('.file-actions-hover') || e.target.closest('.file-card-checkbox-wrap')) return;
        if (e.ctrlKey || e.metaKey || e.shiftKey) {
          this.toggleSelect(item.path);
          return;
        }
        if (item.isDir) {
          this.navigate(item.path);
        } else {
          this.openPreview(item);
        }
      });

      // Actions
      card.querySelector('.preview-btn')?.addEventListener('click', () => this.openPreview(item));
      card.querySelector('.extract-btn')?.addEventListener('click', () => this.openExtractModal(item));
      card.querySelector('.compress-btn')?.addEventListener('click', () => this.openCompressModal(item));
      card.querySelector('.download-btn')?.addEventListener('click', () => this.downloadItem(item.path, item.isDir));
      card.querySelector('.rename-btn')?.addEventListener('click', () => this.openRenameModal(item));
      card.querySelector('.delete-btn')?.addEventListener('click', () => this.openDeleteModal(item));

      this.bindDragItem(card, item);
      this.el.fileGrid.appendChild(card);
    });

    this.observeImages(this.el.fileGrid);
  }

  renderGalleryView() {
    if (!this.el.fileGallery) return;
    this.el.fileGallery.innerHTML = '';

    this.filteredItems.forEach(item => {
      const type = this.getFileTypeCategory(item);
      const isImage = type === 'image';
      const isArchive = type === 'archive';
      const isSelected = this.selectedPaths.has(item.path);
      const thumbUrl = `api.php?action=thumb&v=2&path=${encodeURIComponent(item.path)}`;

      const card = document.createElement('div');
      card.className = `gallery-card ${item.isDir ? 'is-dir' : `type-${type}`} ${isSelected ? 'selected' : ''}`;

      card.innerHTML = `
        <div class="file-card-checkbox-wrap">
          <input type="checkbox" class="file-select-checkbox" ${isSelected ? 'checked' : ''}>
        </div>
        <div class="gallery-thumb">
          ${isImage ? `
            <img data-src="${thumbUrl}" class="gallery-img" decoding="async" alt="${item.name}">
            <span class="gallery-badge-type">${item.extension.toUpperCase()}</span>
          ` : `
            <div class="gallery-icon-wrap">${this.getFileIconSvg(type, item.isDir)}</div>
            ${!item.isDir ? `<span class="gallery-badge-type">${item.extension ? item.extension.toUpperCase() : 'FILE'}</span>` : ''}
          `}

          <div class="gallery-actions-hover">
            ${!item.isDir ? `<button class="btn-action-icon preview-btn" title="Lihat Preview"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>` : ''}
            ${isArchive ? `<button class="btn-action-icon extract-btn" title="Ekstrak Arsip (ZIP/RAR)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><polyline points="10 12 15 12 15 7"/><line x1="15" y1="12" x2="9" y2="18"/></svg></button>` : ''}
            <button class="btn-action-icon compress-btn" title="Kompres ke ZIP"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg></button>
            <button class="btn-action-icon download-btn" title="Unduh ${item.isDir ? 'sebagai ZIP' : ''}"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></button>
            <button class="btn-action-icon rename-btn" title="Ganti Nama"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></button>
            <button class="btn-action-icon delete-btn" title="Hapus"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
          </div>
        </div>
        <div class="gallery-info">
          <div class="gallery-name" title="${item.name}">${item.name}</div>
          <div class="gallery-meta">
            <span>${item.isDir ? 'Folder' : item.sizeFormatted}</span>
            <span>${item.modifiedTime.split(' ')[0] || ''}</span>
          </div>
        </div>
      `;

      const chk = card.querySelector('.file-select-checkbox');
      chk?.addEventListener('change', (e) => {
        e.stopPropagation();
        this.toggleSelect(item.path, e.target.checked);
      });
      chk?.addEventListener('click', (e) => e.stopPropagation());

      card.addEventListener('click', (e) => {
        if (e.target.closest('.gallery-actions-hover') || e.target.closest('.file-card-checkbox-wrap')) return;
        if (e.ctrlKey || e.metaKey || e.shiftKey) {
          this.toggleSelect(item.path);
          return;
        }
        if (item.isDir) {
          this.navigate(item.path);
        } else {
          this.openPreview(item);
        }
      });

      card.querySelector('.preview-btn')?.addEventListener('click', () => this.openPreview(item));
      card.querySelector('.extract-btn')?.addEventListener('click', () => this.openExtractModal(item));
      card.querySelector('.compress-btn')?.addEventListener('click', () => this.openCompressModal(item));
      card.querySelector('.download-btn')?.addEventListener('click', () => this.downloadItem(item.path, item.isDir));
      card.querySelector('.rename-btn')?.addEventListener('click', () => this.openRenameModal(item));
      card.querySelector('.delete-btn')?.addEventListener('click', () => this.openDeleteModal(item));

      this.bindDragItem(card, item);
      this.el.fileGallery.appendChild(card);
    });

    this.observeImages(this.el.fileGallery);
  }

  renderListView() {
    this.el.fileListTbody.innerHTML = '';
    this.filteredItems.forEach(item => {
      const type = this.getFileTypeCategory(item);
      const isArchive = type === 'archive';
      const isSelected = this.selectedPaths.has(item.path);
      const tr = document.createElement('tr');
      if (isSelected) tr.className = 'selected';

      tr.innerHTML = `
        <td style="text-align:center;">
          <input type="checkbox" class="file-select-checkbox" ${isSelected ? 'checked' : ''}>
        </td>
        <td>
          <div class="file-list-name-col">
            <div class="file-list-icon ${item.isDir ? 'is-dir' : `type-${type}`}">
              ${this.getFileIconSvg(type, item.isDir)}
            </div>
            <span style="font-weight:600;">${item.name}</span>
          </div>
        </td>
        <td>${item.sizeFormatted}</td>
        <td>${item.modifiedTime}</td>
        <td style="text-align:right;">
          <div style="display:inline-flex; gap:4px;">
            ${!item.isDir ? `<button class="btn-action-icon preview-btn" title="Preview"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>` : ''}
            ${isArchive ? `<button class="btn-action-icon extract-btn" title="Ekstrak Arsip"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><polyline points="10 12 15 12 15 7"/><line x1="15" y1="12" x2="9" y2="18"/></svg></button>` : ''}
            <button class="btn-action-icon compress-btn" title="Kompres ke ZIP"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg></button>
            <button class="btn-action-icon download-btn" title="Unduh"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></button>
            <button class="btn-action-icon rename-btn" title="Ganti Nama"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></button>
            <button class="btn-action-icon delete-btn" title="Hapus"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
          </div>
        </td>
      `;

      const chk = tr.querySelector('.file-select-checkbox');
      chk?.addEventListener('change', (e) => {
        e.stopPropagation();
        this.toggleSelect(item.path, e.target.checked);
      });
      chk?.addEventListener('click', (e) => e.stopPropagation());

      tr.addEventListener('click', (e) => {
        if (e.target.closest('.btn-action-icon') || e.target.closest('.file-select-checkbox')) return;
        if (e.ctrlKey || e.metaKey || e.shiftKey) {
          this.toggleSelect(item.path);
          return;
        }
        if (item.isDir) {
          this.navigate(item.path);
        } else {
          this.openPreview(item);
        }
      });

      tr.querySelector('.preview-btn')?.addEventListener('click', () => this.openPreview(item));
      tr.querySelector('.extract-btn')?.addEventListener('click', () => this.openExtractModal(item));
      tr.querySelector('.compress-btn')?.addEventListener('click', () => this.openCompressModal(item));
      tr.querySelector('.download-btn')?.addEventListener('click', () => this.downloadItem(item.path, item.isDir));
      tr.querySelector('.rename-btn')?.addEventListener('click', () => this.openRenameModal(item));
      tr.querySelector('.delete-btn')?.addEventListener('click', () => this.openDeleteModal(item));

      this.bindDragItem(tr, item);
      this.el.fileListTbody.appendChild(tr);
    });
  }

  bindDragItem(element, item, draggable = true) {
    element.draggable = draggable;
    element.addEventListener('dragstart', (event) => {
      if (!draggable) return;
      this.internalDrag = this.selectedPaths.has(item.path) ? [...this.selectedPaths] : [item.path];
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('application/x-drive-paths', JSON.stringify(this.internalDrag));
    });
    element.addEventListener('dragend', () => {
      this.internalDrag = null;
      document.querySelectorAll('.drag-target').forEach(el => el.classList.remove('drag-target'));
      this.el.dropOverlay.classList.remove('active');
    });
    if (!item.isDir) {
      const badge = document.createElement('div');
      badge.className = 'upload-attribution';
      badge.textContent = item.uploadedBy ? `Diunggah oleh ${item.uploadedBy.name}` : 'Pengunggah belum tercatat';
      badge.title = item.uploadedBy?.uploadedAt || '';
      (element.querySelector('.file-name, .gallery-name, td') || element).appendChild(badge);
      return;
    }
    element.addEventListener('dragover', (event) => {
      if (!this.internalDrag) return;
      event.preventDefault();
      event.stopPropagation();
      event.dataTransfer.dropEffect = 'move';
      element.classList.add('drag-target');
    });
    element.addEventListener('dragleave', (event) => {
      if (!element.contains(event.relatedTarget)) element.classList.remove('drag-target');
    });
    element.addEventListener('drop', async (event) => {
      if (!this.internalDrag) return;
      event.preventDefault();
      event.stopPropagation();
      const paths = [...this.internalDrag];
      this.internalDrag = null;
      element.classList.remove('drag-target');
      for (const source of paths) {
        try {
          const response = await fetch('api.php', {
            method: 'POST',
            body: new URLSearchParams({ action: 'move', source, destination: item.path })
          });
          if (response.status === 401) { window.location.href = 'login.php'; return; }
          const result = await response.json();
          this.showToast(result.message, result.success ? 'success' : 'error');
        } catch {
          this.showToast('Koneksi gagal saat memindahkan file.', 'error');
        }
      }
      this.selectedPaths.clear();
      this.dirCache.clear();
      this.loadDirectory(this.currentPath);
    });
  }

  setViewMode(mode) {
    this.viewMode = mode;
    localStorage.setItem('drive_view_mode', mode);

    this.el.btnViewGrid?.classList.toggle('active', mode === 'grid');
    this.el.btnViewGallery?.classList.toggle('active', mode === 'gallery');
    this.el.btnViewList?.classList.toggle('active', mode === 'list');

    this.renderItems();
  }

  handleSearch(query) {
    const q = query.toLowerCase().trim();
    if (!q) {
      this.filteredItems = [...this.items];
    } else {
      this.filteredItems = this.items.filter(item => item.name.toLowerCase().includes(q));
    }
    this.renderItems();
  }

  downloadItem(path, isDir = false) {
    const url = `api.php?action=download&path=${encodeURIComponent(path)}`;
    if (isDir) {
      this.showToast('Menyiapkan file arsip ZIP...', 'info');
    }
    window.location.href = url;
  }

  /* Multi-Select & Bulk Operations */
  toggleSelect(path, isSelected) {
    if (isSelected === undefined) {
      isSelected = !this.selectedPaths.has(path);
    }

    if (isSelected) {
      this.selectedPaths.add(path);
    } else {
      this.selectedPaths.delete(path);
    }

    this.updateBulkActionBar();
    this.syncSelectionUI();
  }

  selectAllItems() {
    this.filteredItems.forEach(item => this.selectedPaths.add(item.path));
    this.updateBulkActionBar();
    this.syncSelectionUI();
  }

  deselectAllItems() {
    this.selectedPaths.clear();
    this.updateBulkActionBar();
    this.syncSelectionUI();
  }

  syncSelectionUI() {
    // Sync Grid & Gallery cards
    document.querySelectorAll('.file-card, .gallery-card').forEach(card => {
      const path = card.querySelector('.file-actions-hover .download-btn')?.dataset?.path;
      // We can also find by checkbox
    });

    // Re-render quickly or toggle classes
    this.renderItems();
  }

  updateBulkActionBar() {
    const count = this.selectedPaths.size;
    if (this.el.bulkActionBar) {
      if (count > 0) {
        this.el.bulkActionBar.classList.add('show');
        if (this.el.bulkCount) this.el.bulkCount.textContent = count;
      } else {
        this.el.bulkActionBar.classList.remove('show');
      }
    }

    if (this.el.selectAllCheckbox) {
      this.el.selectAllCheckbox.checked = (count > 0 && count === this.filteredItems.length);
      this.el.selectAllCheckbox.indeterminate = (count > 0 && count < this.filteredItems.length);
    }
  }

  downloadBulk() {
    const paths = Array.from(this.selectedPaths);
    if (paths.length === 0) return;

    this.showToast(`Menyiapkan ${paths.length} item untuk diunduh sebagai ZIP...`, 'info');
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'api.php?action=download_bulk';
    form.target = '_blank';

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'paths';
    input.value = JSON.stringify(paths);
    form.appendChild(input);

    document.body.appendChild(form);
    form.submit();
    setTimeout(() => form.remove(), 2000);
  }

  async compressBulk() {
    const paths = Array.from(this.selectedPaths);
    if (paths.length === 0) return;

    const defaultName = `arsip_${paths.length}_item_${new Date().toISOString().slice(0, 10)}.zip`;
    const zipName = prompt('Masukkan nama file ZIP hasil kompresi:', defaultName);
    if (!zipName) return;

    this.showToast(`Mengompres ${paths.length} item ke ZIP...`, 'info');
    const formData = new FormData();
    formData.append('action', 'compress_bulk');
    formData.append('zipName', zipName);
    formData.append('paths', JSON.stringify(paths));

    try {
      const res = await fetch('api.php', { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        this.showToast(data.message || 'Berhasil mengompres item terpilih.', 'success');
        this.deselectAllItems();
        this.loadDirectory(this.currentPath);
      } else {
        this.showToast(data.message || 'Gagal mengompres item.', 'error');
      }
    } catch (e) {
      this.showToast('Gagal koneksi saat kompresi: ' + e.message, 'error');
    }
  }

  async deleteBulk() {
    const paths = Array.from(this.selectedPaths);
    if (paths.length === 0) return;

    const ok = confirm(`Apakah Anda yakin ingin menghapus ${paths.length} item yang dipilih secara permanen?`);
    if (!ok) return;

    this.showToast(`Menghapus ${paths.length} item terpilih...`, 'info');
    const formData = new FormData();
    formData.append('action', 'delete_bulk');
    formData.append('paths', JSON.stringify(paths));

    try {
      const res = await fetch('api.php', { method: 'POST', body: formData });
      const data = await res.json();
      if (data.success) {
        this.showToast(data.message || 'Item terpilih berhasil dihapus.', 'success');
        this.deselectAllItems();
        this.loadDirectory(this.currentPath);
      } else {
        this.showToast(data.message || 'Gagal menghapus item.', 'error');
      }
    } catch (e) {
      this.showToast('Gagal koneksi saat menghapus: ' + e.message, 'error');
    }
  }

  /* Recursive Drag & Drop Folder Parser */
  async parseDropItems(dataTransfer) {
    const items = dataTransfer.items;
    const fileEntries = [];

    if (items && items.length > 0 && items[0].webkitGetAsEntry) {
      const queue = [];
      for (let i = 0; i < items.length; i++) {
        const entry = items[i].webkitGetAsEntry();
        if (entry) queue.push(entry);
      }

      while (queue.length > 0) {
        const entry = queue.shift();
        if (entry.isFile) {
          try {
            const file = await new Promise((res, rej) => entry.file(res, rej));
            const relPath = entry.fullPath ? entry.fullPath.replace(/^\//, '') : file.name;
            fileEntries.push({ file, relativePath: relPath });
          } catch (e) {}
        } else if (entry.isDirectory) {
          try {
            const dirReader = entry.createReader();
            const childEntries = await this.readAllDirectoryEntries(dirReader);
            for (const child of childEntries) {
              queue.push(child);
            }
          } catch (e) {}
        }
      }
    } else if (dataTransfer.files) {
      for (let i = 0; i < dataTransfer.files.length; i++) {
        const file = dataTransfer.files[i];
        fileEntries.push({ file, relativePath: file.name });
      }
    }

    return fileEntries;
  }

  async readAllDirectoryEntries(dirReader) {
    const entries = [];
    let readBatch = await new Promise((res, rej) => dirReader.readEntries(res, rej));
    while (readBatch && readBatch.length > 0) {
      entries.push(...readBatch);
      readBatch = await new Promise((res, rej) => dirReader.readEntries(res, rej));
    }
    return entries;
  }

  /* Chunked File & Folder Upload Engine */
  async handleFilesUpload(files) {
    if (!files || files.length === 0) return;
    if (!this.currentPath) {
      this.showToast('Buka drive atau folder tujuan sebelum mengunggah.', 'error');
      return;
    }
    const targetDir = this.currentPath;
    this.uploadChain = this.uploadChain.catch(() => {}).then(async () => {
      this.el.uploadDrawer.classList.add('show');
      for (const item of files) await this.uploadSingleFileChunked(item, targetDir);
      this.dirCache.clear();
      await this.loadDirectory(this.currentPath);
    });
    return this.uploadChain;
  }

  async uploadSingleFileChunked(item, targetDir = this.currentPath) {
    const file = item.file || item;
    const relativePath = item.relativePath || file.name;
    const uploadId = 'upl_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
    const chunkSize = 2 * 1024 * 1024; // 2MB chunk
    const totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));

    const itemEl = document.createElement('div');
    itemEl.className = 'upload-item';
    itemEl.id = uploadId;
    itemEl.innerHTML = `
      <div class="upload-item-header">
        <span class="upload-item-name"></span>
        <span class="upload-item-pct">0%</span>
      </div>
      <div class="upload-progress-bg">
        <div class="upload-progress-bar" style="width: 0%"></div>
      </div>
    `;
    this.el.uploadQueueList.prepend(itemEl);
    itemEl.querySelector('.upload-item-name').textContent = relativePath;
    itemEl.querySelector('.upload-item-name').title = relativePath;

    const bar = itemEl.querySelector('.upload-progress-bar');
    const pct = itemEl.querySelector('.upload-item-pct');

    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
      const start = chunkIndex * chunkSize;
      const end = Math.min(file.size, start + chunkSize);
      const chunkBlob = file.slice(start, end);

      const formData = new FormData();
      formData.append('action', 'upload_chunk');
      formData.append('targetDir', targetDir);
      formData.append('fileName', file.name);
      formData.append('relativePath', relativePath);
      formData.append('chunkIndex', chunkIndex);
      formData.append('totalChunks', totalChunks);
      formData.append('uploadId', uploadId);
      formData.append('chunk', chunkBlob);

      try {
        const res = await this.fetchWithRetry('api.php', {
          method: 'POST',
          body: formData
        });
        const result = await res.json();

        if (!result.success) {
          pct.textContent = 'Gagal';
          pct.style.color = 'var(--accent-rose)';
          this.showToast(`Upload gagal: ${relativePath} (${result.message})`, 'error');
          return;
        }

        const progressPercent = Math.round(((chunkIndex + 1) / totalChunks) * 100);
        bar.style.width = progressPercent + '%';
        pct.textContent = progressPercent + '%';
      } catch (err) {
        pct.textContent = 'Error';
        pct.style.color = 'var(--accent-rose)';
        this.showToast(`Upload ${relativePath} gagal: ${err.message}`, 'error');
        return;
      }
    }

    pct.textContent = 'Selesai';
    pct.style.color = 'var(--accent-emerald)';
    this.showToast(`Berhasil mengunggah ${relativePath}`, 'success');
  }

  /* File Operations */
  openNewFolderModal() {
    this.el.newFolderNameInput.value = '';
    this.el.newFolderModal.classList.add('active');
    setTimeout(() => this.el.newFolderNameInput.focus(), 100);
  }

  async submitNewFolder() {
    const name = this.el.newFolderNameInput.value.trim();
    if (!name) return;

    const formData = new FormData();
    formData.append('action', 'create_folder');
    formData.append('path', this.currentPath);
    formData.append('name', name);

    try {
      const res = await fetch('api.php', { method: 'POST', body: formData });
      const data = await res.json();

      if (data.success) {
        this.closeModals();
        this.showToast('Folder berhasil dibuat', 'success');
        this.loadDirectory(this.currentPath);
      } else {
        this.showToast(data.message || 'Gagal membuat folder', 'error');
      }
    } catch (err) {
      this.showToast('Terjadi kesalahan: ' + err.message, 'error');
    }
  }

  openRenameModal(item) {
    this.selectedItem = item;
    this.el.renameInput.value = item.name;
    this.el.renameModal.classList.add('active');
    setTimeout(() => this.el.renameInput.focus(), 100);
  }

  async submitRename() {
    if (!this.selectedItem) return;
    const newName = this.el.renameInput.value.trim();
    if (!newName || newName === this.selectedItem.name) {
      this.closeModals();
      return;
    }

    const formData = new FormData();
    formData.append('action', 'rename');
    formData.append('oldPath', this.selectedItem.path);
    formData.append('newName', newName);

    try {
      const res = await fetch('api.php', { method: 'POST', body: formData });
      const data = await res.json();

      if (data.success) {
        this.closeModals();
        this.showToast('Nama berhasil diubah', 'success');
        this.loadDirectory(this.currentPath);
      } else {
        this.showToast(data.message || 'Gagal mengubah nama', 'error');
      }
    } catch (err) {
      this.showToast('Terjadi kesalahan: ' + err.message, 'error');
    }
  }

  openDeleteModal(item) {
    this.selectedItem = item;
    this.el.deleteItemName.textContent = item.name;
    this.el.deleteModal.classList.add('active');
  }

  async submitDelete() {
    if (!this.selectedItem) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('path', this.selectedItem.path);

    try {
      const res = await fetch('api.php', { method: 'POST', body: formData });
      const data = await res.json();

      if (data.success) {
        this.closeModals();
        this.showToast('Item berhasil dihapus', 'success');
        this.loadDirectory(this.currentPath);
      } else {
        this.showToast(data.message || 'Gagal menghapus item', 'error');
      }
    } catch (err) {
      this.showToast('Terjadi kesalahan: ' + err.message, 'error');
    }
  }

  /* Compress to ZIP Modal */
  openCompressModal(item) {
    this.selectedItem = item;
    this.el.compressItemName.textContent = item.name;
    const baseName = item.name.replace(/\.[^/.]+$/, '');
    this.el.compressZipNameInput.value = `${baseName}.zip`;
    this.el.compressModal.classList.add('active');
    setTimeout(() => {
      this.el.compressZipNameInput.focus();
      this.el.compressZipNameInput.select();
    }, 100);
  }

  async submitCompress() {
    if (!this.selectedItem) return;
    const zipName = this.el.compressZipNameInput.value.trim();

    this.showToast('Sedang membuat arsip ZIP...', 'info');
    this.closeModals();

    const formData = new FormData();
    formData.append('action', 'compress_zip');
    formData.append('path', this.selectedItem.path);
    formData.append('zipName', zipName);

    try {
      const res = await fetch('api.php', { method: 'POST', body: formData });
      const data = await res.json();

      if (data.success) {
        this.showToast(data.message || 'Arsip ZIP berhasil dibuat', 'success');
        this.loadDirectory(this.currentPath);
      } else {
        this.showToast(data.message || 'Gagal membuat arsip ZIP', 'error');
      }
    } catch (err) {
      this.showToast('Terjadi kesalahan: ' + err.message, 'error');
    }
  }

  /* Extract Archive Modal */
  openExtractModal(item) {
    this.selectedItem = item;
    this.el.extractItemName.textContent = item.name;
    const baseFolder = item.name.replace(/\.[^/.]+$/, '');
    this.el.extractFolderNameInput.value = baseFolder;
    this.el.extractModal.classList.add('active');
    setTimeout(() => {
      this.el.extractFolderNameInput.focus();
      this.el.extractFolderNameInput.select();
    }, 100);
  }

  async submitExtract() {
    if (!this.selectedItem) return;
    const targetFolder = this.el.extractFolderNameInput.value.trim();

    this.showToast('Sedang mengekstrak arsip file...', 'info');
    this.closeModals();

    const formData = new FormData();
    formData.append('action', 'extract_archive');
    formData.append('path', this.selectedItem.path);
    formData.append('targetFolder', targetFolder);

    try {
      const res = await fetch('api.php', { method: 'POST', body: formData });
      const data = await res.json();

      if (data.success) {
        this.showToast(data.message || 'Arsip berhasil diekstrak', 'success');
        this.loadDirectory(this.currentPath);
      } else {
        this.showToast(data.message || 'Gagal mengekstrak arsip', 'error');
      }
    } catch (err) {
      this.showToast('Terjadi kesalahan: ' + err.message, 'error');
    }
  }

  /* File Preview Modal */
  async openPreview(item) {
    this.stopPreview();
    const previewGeneration = this.previewGeneration;
    const type = this.getFileTypeCategory(item);
    const previewUrl = `api.php?action=preview&v=2&path=${encodeURIComponent(item.path)}`;

    this.el.previewTitle.textContent = item.name;
    this.el.previewContainer.innerHTML = '<div style="color:var(--text-muted);">Memuat berkas...</div>';
    this.el.previewModal.classList.add('active');

    this.el.btnDownloadPreview.onclick = () => this.downloadItem(item.path, false);

    if (type === 'image') {
      const img = document.createElement('img');
      img.className = 'preview-img';
      this.el.previewContainer.innerHTML = '';
      this.el.previewContainer.appendChild(img);
      const original = document.createElement('a');
      original.href = previewUrl + '&original=1';
      original.target = '_blank';
      original.rel = 'noopener';
      original.textContent = 'Lihat gambar asli';
      original.className = 'btn btn-secondary preview-original-link';
      this.el.previewContainer.appendChild(original);
      const controller = new AbortController();
      this.previewController = controller;
      try {
        // Cached thumbnail appears first while the screen-sized preview is prepared.
        const thumbnail = await this.fetchWithRetry(`api.php?action=thumb&v=2&path=${encodeURIComponent(item.path)}`, { signal: controller.signal });
        const smallBlob = await thumbnail.blob();
        if (previewGeneration !== this.previewGeneration) return;
        this.previewObjectUrl = URL.createObjectURL(smallBlob);
        img.src = this.previewObjectUrl;
        const response = await this.fetchWithRetry(previewUrl, { signal: controller.signal });
        const blob = await response.blob();
        if (previewGeneration !== this.previewGeneration) return;
        URL.revokeObjectURL(this.previewObjectUrl);
        this.previewObjectUrl = URL.createObjectURL(blob);
        img.src = this.previewObjectUrl;
      } catch (error) {
        if (error.name !== 'AbortError' && previewGeneration === this.previewGeneration) this.showToast('Preview gagal dimuat. Gunakan gambar asli atau coba lagi.', 'error');
      }
    } else if (type === 'video') {
      const videoEl = document.createElement('video');
      videoEl.className = 'preview-video';
      videoEl.controls = true;
      videoEl.autoplay = true;
      videoEl.preload = 'metadata';
      videoEl.playsInline = true;
      videoEl.src = previewUrl;
      videoEl.addEventListener('waiting', () => {
        if (previewGeneration !== this.previewGeneration) return;
        if (!this.mediaWaitingNotice) {
          this.showToast('Video sedang buffering. Menunggu data dari drive/server.', 'info');
          this.mediaWaitingNotice = true;
        }
      });
      videoEl.addEventListener('playing', () => { this.mediaWaitingNotice = false; });
      videoEl.onerror = () => {
        if (previewGeneration !== this.previewGeneration) return;
        this.el.previewContainer.innerHTML = `
          <div style="text-align:center; color:#E2E8F0; padding:24px;">
            <div style="font-size:3rem; margin-bottom:12px;">🎬</div>
            <p style="margin-bottom:8px; font-weight:600;">Video gagal dimuat. Server mungkin sedang sibuk atau format belum didukung.</p>
            <p style="font-size:0.85rem; color:#94A3B8; margin-bottom:16px;">Silakan unduh file untuk memutarnya menggunakan aplikasi pemutar video lokal di komputer Anda.</p>
            <button class="btn btn-primary" onclick="driveApp.downloadItem('${encodeURIComponent(item.path)}', false)">Unduh File Video</button>
          </div>
        `;
      };
      this.el.previewContainer.innerHTML = '';
      this.el.previewContainer.appendChild(videoEl);
    } else if (type === 'audio') {
      const audioContainer = document.createElement('div');
      audioContainer.style.textAlign = 'center';
      audioContainer.innerHTML = `
        <div style="font-size:3rem; margin-bottom:16px; color:var(--accent-emerald);">🎵</div>
        <audio class="preview-audio" controls autoplay preload="metadata" src="${previewUrl}">
          Browser Anda tidak mendukung pemutaran audio ini.
        </audio>
      `;
      this.el.previewContainer.innerHTML = '';
      this.el.previewContainer.appendChild(audioContainer);
    } else if (type === 'pdf') {
      this.el.previewContainer.innerHTML = `
        <iframe class="preview-iframe" src="${previewUrl}"></iframe>
      `;
    } else if (type === 'code' || type === 'doc' && ['txt', 'md', 'json', 'log', 'csv', 'ini', 'env', 'sql', 'xml'].includes(item.extension)) {
      const controller = new AbortController();
      this.previewController = controller;
      try {
        const res = await this.fetchWithRetry(previewUrl, {
          signal: controller.signal,
          headers: item.size > 524288 ? { Range: 'bytes=0-524287' } : {}
        });
        const text = await res.text();
        if (previewGeneration !== this.previewGeneration) return;
        const pre = document.createElement('pre');
        pre.className = 'preview-code-box';
        pre.textContent = text + (item.size > 524288 ? '\n\n[Preview dibatasi 512 KB. Unduh untuk melihat file lengkap.]' : '');
        this.el.previewContainer.innerHTML = '';
        this.el.previewContainer.appendChild(pre);
      } catch (err) {
        if (previewGeneration !== this.previewGeneration) return;
        this.el.previewContainer.innerHTML = '<div style="color:var(--accent-rose);">Gagal memuat teks file.</div>';
      }
    } else {
      this.el.previewContainer.innerHTML = `
        <div style="text-align:center; color:var(--text-muted);">
          <div style="font-size:2.5rem; margin-bottom:12px;">📁</div>
          <div>Preview tidak didukung untuk tipe file ini.</div>
          <button class="btn btn-primary" style="margin-top:16px;" onclick="driveApp.downloadItem('${encodeURIComponent(item.path)}', false)">Unduh File</button>
        </div>
      `;
    }
  }

  closeModals() {
    document.querySelectorAll('.modal-overlay').forEach(modal => modal.classList.remove('active'));
    // Stop any playing audio/video
    this.stopPreview();
  }

  stopPreview() {
    this.previewGeneration = (this.previewGeneration || 0) + 1;
    this.previewController?.abort();
    this.previewController = null;
    if (this.previewObjectUrl) URL.revokeObjectURL(this.previewObjectUrl);
    this.previewObjectUrl = null;
    this.mediaWaitingNotice = false;
    const media = this.el.previewContainer.querySelector('video, audio');
    if (media) {
      media.pause();
      media.removeAttribute('src');
      media.querySelectorAll('source').forEach(source => source.removeAttribute('src'));
      media.load();
    }
    this.el.previewContainer.querySelector('iframe')?.removeAttribute('src');
  }

  showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    let iconSvg = '';
    if (type === 'success') {
      iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent-emerald)" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>`;
    } else if (type === 'error') {
      iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent-rose)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`;
    } else {
      iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent-cyan)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>`;
    }

    toast.innerHTML = `
      ${iconSvg}
      <span>${message}</span>
    `;

    this.el.toastContainer.appendChild(toast);

    setTimeout(() => {
      toast.style.animation = 'toastIn 0.3s reverse forwards';
      setTimeout(() => toast.remove(), 300);
    }, 3500);
  }
}

// Initialize Application
let driveApp;
document.addEventListener('DOMContentLoaded', () => {
  driveApp = new DriveApp();
});
