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
              img.src = src;
              img.removeAttribute('data-src');
              img.onload = () => img.classList.add('loaded');
            }
            observer.unobserve(img);
          }
        });
      }, {
        rootMargin: '350px 0px',
        threshold: 0.01
      });
    }
  }

  observeImages(container) {
    if (!container) return;
    const lazyImages = container.querySelectorAll('img[data-src]');
    lazyImages.forEach(img => {
      if (this.imageObserver) {
        this.imageObserver.observe(img);
      } else {
        img.src = img.getAttribute('data-src');
        img.removeAttribute('data-src');
        img.onload = () => img.classList.add('loaded');
      }
    });
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
      dropOverlay: document.getElementById('dropOverlay'),
      uploadDrawer: document.getElementById('uploadDrawer'),
      uploadQueueList: document.getElementById('uploadQueueList'),
      btnCloseUploadDrawer: document.getElementById('btnCloseUploadDrawer'),
      toastContainer: document.getElementById('toastContainer'),

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

    // Upload button
    this.el.btnUploadFile?.addEventListener('click', () => this.el.fileInput.click());
    this.el.fileInput?.addEventListener('change', (e) => {
      if (e.target.files && e.target.files.length > 0) {
        this.handleFilesUpload(Array.from(e.target.files));
        this.el.fileInput.value = '';
      }
    });

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

    // Drag & drop on window
    window.addEventListener('dragover', (e) => {
      e.preventDefault();
      this.el.dropOverlay.classList.add('active');
    });

    this.el.dropOverlay.addEventListener('dragleave', (e) => {
      e.preventDefault();
      this.el.dropOverlay.classList.remove('active');
    });

    window.addEventListener('drop', (e) => {
      e.preventDefault();
      this.el.dropOverlay.classList.remove('active');
      if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
        this.handleFilesUpload(Array.from(e.dataTransfer.files));
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
    setInterval(() => {
      this.loadDrives(false, true);
    }, 4500);
  }

  async loadDrives(isInitial = false, isBackground = false) {
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
            this.el.folderTitle.textContent = 'Belum Ada Drive Terhubung';
            this.el.folderStats.textContent = '0 item';
            this.el.emptyState.style.display = 'flex';
            this.el.fileGrid.style.display = 'none';
            if (this.el.fileGallery) this.el.fileGallery.style.display = 'none';
            this.el.fileListTable.style.display = 'none';
          }
        }
      }
    } catch (err) {
      if (!isBackground) {
        this.showToast('Gagal memuat daftar drive: ' + err.message, 'error');
      }
    }
  }

  renderDriveList() {
    this.el.driveList.innerHTML = '';
    this.el.driveCountBadge.textContent = `${this.drives.length} Terhubung`;

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
      this.el.folderTitle.textContent = 'Memuat berkas...';
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
      }
    }
  }

  updateBreadcrumbs() {
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
    this.el.folderTitle.textContent = folderName;
    this.el.folderStats.textContent = `${data.totalItems} item (${data.folderSizeFormatted}) • Sisa Drive: ${data.driveFreeFormatted}`;
    this.el.btnNavUp.disabled = !this.parentPath;
  }

  renderItems() {
    if (this.filteredItems.length === 0) {
      this.el.emptyState.style.display = 'flex';
      this.el.fileGrid.style.display = 'none';
      if (this.el.fileGallery) this.el.fileGallery.style.display = 'none';
      this.el.fileListTable.style.display = 'none';
      return;
    }

    this.el.emptyState.style.display = 'none';

    if (this.viewMode === 'gallery') {
      this.el.fileGrid.style.display = 'none';
      if (this.el.fileGallery) this.el.fileGallery.style.display = 'grid';
      this.el.fileListTable.style.display = 'none';
      this.renderGalleryView();
    } else if (this.viewMode === 'list') {
      this.el.fileGrid.style.display = 'none';
      if (this.el.fileGallery) this.el.fileGallery.style.display = 'none';
      this.el.fileListTable.style.display = 'table';
      this.renderListView();
    } else {
      this.el.fileGrid.style.display = 'grid';
      if (this.el.fileGallery) this.el.fileGallery.style.display = 'none';
      this.el.fileListTable.style.display = 'none';
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
      const thumbUrl = `api.php?action=thumb&path=${encodeURIComponent(item.path)}`;

      const card = document.createElement('div');
      card.className = `file-card ${item.isDir ? 'is-dir' : `type-${type}`}`;

      card.innerHTML = `
        <div class="file-actions-hover">
          ${!item.isDir ? `<button class="btn-action-icon preview-btn" title="Lihat Preview"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>` : ''}
          ${isArchive ? `<button class="btn-action-icon extract-btn" title="Ekstrak Arsip (ZIP/RAR)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><polyline points="10 12 15 12 15 7"/><line x1="15" y1="12" x2="9" y2="18"/></svg></button>` : ''}
          <button class="btn-action-icon compress-btn" title="Kompres ke ZIP"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg></button>
          <button class="btn-action-icon download-btn" title="Unduh ${item.isDir ? 'sebagai ZIP' : ''}"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></button>
          <button class="btn-action-icon rename-btn" title="Ganti Nama"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></button>
          <button class="btn-action-icon delete-btn" title="Hapus"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
        </div>
        <div class="file-thumb">
          ${isImage ? `<img data-src="${thumbUrl}" class="file-card-img-thumb" decoding="async" alt="${item.name}" onerror="this.outerHTML='${this.getFileIconSvg('image', false)}'">` : this.getFileIconSvg(type, item.isDir)}
        </div>
        <div class="file-name" title="${item.name}">${item.name}</div>
        <div class="file-details">${item.isDir ? 'Folder' : item.sizeFormatted}</div>
      `;

      card.addEventListener('click', (e) => {
        if (e.target.closest('.file-actions-hover')) return;
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
      const thumbUrl = `api.php?action=thumb&path=${encodeURIComponent(item.path)}`;

      const card = document.createElement('div');
      card.className = `gallery-card ${item.isDir ? 'is-dir' : `type-${type}`}`;

      card.innerHTML = `
        <div class="gallery-thumb">
          ${isImage ? `
            <img data-src="${thumbUrl}" class="gallery-img" decoding="async" alt="${item.name}" onerror="this.outerHTML='<div class=\\'gallery-icon-wrap\\'>${this.getFileIconSvg('image', false)}</div>'">
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

      card.addEventListener('click', (e) => {
        if (e.target.closest('.gallery-actions-hover')) return;
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

      this.el.fileGallery.appendChild(card);
    });

    this.observeImages(this.el.fileGallery);
  }

  renderListView() {
    this.el.fileListTbody.innerHTML = '';
    this.filteredItems.forEach(item => {
      const type = this.getFileTypeCategory(item);
      const isArchive = type === 'archive';
      const tr = document.createElement('tr');

      tr.innerHTML = `
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

      tr.addEventListener('click', (e) => {
        if (e.target.closest('.btn-action-icon')) return;
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

      this.el.fileListTbody.appendChild(tr);
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

  /* Chunked File Upload Engine */
  async handleFilesUpload(files) {
    if (!files || files.length === 0) return;

    this.el.uploadDrawer.classList.add('show');

    for (const file of files) {
      await this.uploadSingleFileChunked(file);
    }

    this.loadDirectory(this.currentPath);
  }

  async uploadSingleFileChunked(file) {
    const uploadId = 'upl_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
    const chunkSize = 2 * 1024 * 1024; // 2MB chunk
    const totalChunks = Math.ceil(file.size / chunkSize);

    const itemEl = document.createElement('div');
    itemEl.className = 'upload-item';
    itemEl.id = uploadId;
    itemEl.innerHTML = `
      <div class="upload-item-header">
        <span class="upload-item-name" title="${file.name}">${file.name}</span>
        <span class="upload-item-pct">0%</span>
      </div>
      <div class="upload-progress-bg">
        <div class="upload-progress-bar" style="width: 0%"></div>
      </div>
    `;
    this.el.uploadQueueList.prepend(itemEl);

    const bar = itemEl.querySelector('.upload-progress-bar');
    const pct = itemEl.querySelector('.upload-item-pct');

    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
      const start = chunkIndex * chunkSize;
      const end = Math.min(file.size, start + chunkSize);
      const chunkBlob = file.slice(start, end);

      const formData = new FormData();
      formData.append('action', 'upload_chunk');
      formData.append('targetDir', this.currentPath);
      formData.append('fileName', file.name);
      formData.append('chunkIndex', chunkIndex);
      formData.append('totalChunks', totalChunks);
      formData.append('uploadId', uploadId);
      formData.append('chunk', chunkBlob);

      try {
        const res = await fetch('api.php', {
          method: 'POST',
          body: formData
        });
        const result = await res.json();

        if (!result.success) {
          pct.textContent = 'Gagal';
          pct.style.color = 'var(--accent-rose)';
          this.showToast(`Upload gagal: ${file.name} (${result.message})`, 'error');
          return;
        }

        const progressPercent = Math.round(((chunkIndex + 1) / totalChunks) * 100);
        bar.style.width = progressPercent + '%';
        pct.textContent = progressPercent + '%';
      } catch (err) {
        pct.textContent = 'Error';
        pct.style.color = 'var(--accent-rose)';
        this.showToast(`Gagal koneksi saat upload ${file.name}`, 'error');
        return;
      }
    }

    pct.textContent = 'Selesai';
    pct.style.color = 'var(--accent-emerald)';
    this.showToast(`Berhasil mengunggah ${file.name}`, 'success');
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
    const type = this.getFileTypeCategory(item);
    const previewUrl = `api.php?action=preview&path=${encodeURIComponent(item.path)}`;

    this.el.previewTitle.textContent = item.name;
    this.el.previewContainer.innerHTML = '<div style="color:var(--text-muted);">Memuat berkas...</div>';
    this.el.previewModal.classList.add('active');

    this.el.btnDownloadPreview.onclick = () => this.downloadItem(item.path, false);

    if (type === 'image') {
      const img = document.createElement('img');
      img.src = previewUrl;
      img.className = 'preview-img';
      img.onload = () => {
        this.el.previewContainer.innerHTML = '';
        this.el.previewContainer.appendChild(img);
      };
      img.onerror = () => {
        this.el.previewContainer.innerHTML = '<div style="color:var(--accent-rose);">Gagal memuat gambar.</div>';
      };
    } else if (type === 'video') {
      const videoEl = document.createElement('video');
      videoEl.className = 'preview-video';
      videoEl.controls = true;
      videoEl.autoplay = true;
      videoEl.preload = 'metadata';
      videoEl.playsInline = true;
      videoEl.src = previewUrl;
      videoEl.onerror = () => {
        this.el.previewContainer.innerHTML = `
          <div style="text-align:center; color:#E2E8F0; padding:24px;">
            <div style="font-size:3rem; margin-bottom:12px;">🎬</div>
            <p style="margin-bottom:8px; font-weight:600;">Format video tidak dapat diputar langsung di browser.</p>
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
      try {
        const res = await fetch(previewUrl);
        const text = await res.text();
        const pre = document.createElement('pre');
        pre.className = 'preview-code-box';
        pre.textContent = text;
        this.el.previewContainer.innerHTML = '';
        this.el.previewContainer.appendChild(pre);
      } catch (err) {
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
    const media = this.el.previewContainer.querySelector('video, audio');
    if (media) media.pause();
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
