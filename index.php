<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$config = require __DIR__ . '/config.php';

// Validasi autentikasi SSO jika diaktifkan di konfigurasi
if (!empty($config['sso_enabled']) && empty($_SESSION['drive_user'])) {
    header('Location: login.php');
    exit;
}

$currentUser = $_SESSION['drive_user'] ?? [
    'id' => 1,
    'name' => 'Admin Mahaghora',
    'email' => 'admin@beasiswamahaghora.com',
    'status' => 'admin'
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Local Drive Manager - Web File Explorer untuk Flashdisk USB, Harddisk Eksternal dan Internal dengan fitur Upload & Download">
  <title>Local Drive - Beasiswa Mahaghora</title>
  
  <link rel="shortcut icon" href="assets/images/logo-white.png">
  <link rel="icon" type="image/png" href="assets/images/logo-white.png">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

  <div class="app-layout">
    <!-- Sidebar: Drive list -->
    <aside class="sidebar">
      <div class="sidebar-header">
        <div class="brand">
          <img src="assets/images/logo-blue-ui.svg" alt="Logo Beasiswa Mahaghora" class="brand-bmhg-logo">
        </div>
        <div class="usb-status-indicator" title="Sistem mendeteksi USB & Flashdisk secara real-time">
          <span class="pulse-dot"></span>
          <span>Live Sync</span>
        </div>
      </div>

      <div class="sidebar-content">
        <div>
          <div class="section-label-row">
            <span class="section-label">Drive & Penyimpanan</span>
            <div style="display:flex; align-items:center; gap:6px;">
              <span id="driveCountBadge" style="font-size:0.7rem; color:var(--text-muted); font-weight:600;">Memuat...</span>
              <button id="btnRefreshDrives" class="btn-icon-mini" title="Pindai ulang drive">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="23 4 23 10 17 10"/>
                  <polyline points="1 20 1 14 7 14"/>
                  <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                </svg>
              </button>
            </div>
          </div>

          <div id="driveList" class="drive-list">
            <!-- Drives injected here via JS -->
          </div>
        </div>
      </div>

      <div class="sidebar-footer">
        <div class="usb-guide-box">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--accent-cyan)" stroke-width="2" style="flex-shrink:0;">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="16" x2="12" y2="12"/>
            <line x1="12" y1="8" x2="12.01" y2="8"/>
          </svg>
          <div>
            Colokkan <strong>Flashdisk / Harddisk</strong> ke port USB, drive akan otomatis muncul di atas.
          </div>
        </div>
      </div>
    </aside>

    <!-- Main Workspace -->
    <main class="main-wrapper">
      <!-- Topbar Header -->
      <header class="topbar">
        <div class="topbar-left">
          <div class="nav-buttons">
            <button id="btnNavUp" class="btn-nav" title="Naik satu tingkat (Parent Folder)">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="19" x2="12" y2="5"/>
                <polyline points="5 12 12 5 19 12"/>
              </svg>
            </button>
            <button id="btnRefreshFolder" class="btn-nav" title="Muat ulang folder saat ini">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="23 4 23 10 17 10"/>
                <polyline points="1 20 1 14 7 14"/>
                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
              </svg>
            </button>
          </div>

          <!-- Breadcrumbs -->
          <nav id="breadcrumbs" class="breadcrumbs-bar" aria-label="Breadcrumb">
            <!-- Breadcrumbs injected via JS -->
          </nav>
        </div>

        <div class="topbar-right">
          <!-- Search Box -->
          <div class="search-box">
            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"/>
              <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" id="searchInput" class="search-input" placeholder="Cari di folder ini...">
          </div>

          <!-- Actions -->
          <button id="btnNewFolder" class="btn btn-secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
              <line x1="12" y1="11" x2="12" y2="17"/>
              <line x1="9" y1="14" x2="15" y2="14"/>
            </svg>
            <span>Folder Baru</span>
          </button>

          <button id="btnUploadFile" class="btn btn-primary" title="Upload satu atau beberapa file">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
              <polyline points="17 8 12 3 7 8"/>
              <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <span>Upload File</span>
          </button>

          <button id="btnUploadFolder" class="btn btn-secondary" title="Upload satu folder beserta seluruh isinya">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
              <polyline points="12 11 12 17 15 14"/>
            </svg>
            <span>Upload Folder</span>
          </button>

          <button id="btnDownloadZip" class="btn btn-secondary" title="Unduh semua file dalam folder ini sebagai ZIP">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="21 8 21 21 3 21 3 8"/>
              <rect x="1" y="3" width="22" height="5"/>
              <line x1="10" y1="12" x2="14" y2="12"/>
            </svg>
            <span>ZIP Folder</span>
          </button>

          <!-- View Toggle -->
          <div class="view-toggle-group">
            <button id="btnViewGrid" class="btn-view-toggle active" title="Grid View (Ikon Standar)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"/>
                <rect x="14" y="3" width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/>
                <rect x="3" y="14" width="7" height="7"/>
              </svg>
            </button>
            <button id="btnViewGallery" class="btn-view-toggle" title="Gallery / Thumbnail View (Kotak Preview Gambar)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                <circle cx="8.5" cy="8.5" r="1.5"/>
                <polyline points="21 15 16 10 5 21"/>
              </svg>
            </button>
            <button id="btnViewList" class="btn-view-toggle" title="List View (Tabel Rinci)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="8" y1="6" x2="21" y2="6"/>
                <line x1="8" y1="12" x2="21" y2="12"/>
                <line x1="8" y1="18" x2="21" y2="18"/>
                <line x1="3" y1="6" x2="3.01" y2="6"/>
                <line x1="3" y1="12" x2="3.01" y2="12"/>
                <line x1="3" y1="18" x2="3.01" y2="18"/>
              </svg>
            </button>
          </div>

          <!-- User SSO Profile Badge -->
          <div class="user-profile-badge" title="Login via SSO BMHG (<?= htmlspecialchars($userRole) ?>)">
            <div class="user-avatar-circle">
              <?= strtoupper(substr($userName, 0, 1)) ?>
            </div>
            <div class="user-meta-info">
              <span class="user-meta-name"><?= htmlspecialchars($userName) ?></span>
              <span class="user-meta-role"><?= htmlspecialchars($userRole) ?></span>
            </div>
            <a href="logout.php" class="btn-logout-badge" title="Keluar / Logout">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
              </svg>
            </a>
          </div>
        </div>
      </header>

      <!-- Main Explorer Body -->
      <div class="explorer-body">
        <!-- Breadcrumbs bar -->
        <div class="breadcrumbs-container">
          <div id="breadcrumbs" class="breadcrumbs-list">
            <!-- Breadcrumbs injected via JS -->
          </div>
        </div>

        <!-- Folder Content Area -->
        <div class="content-view-area">
          <div class="content-header-meta">
            <div>
              <h2 id="folderTitle" class="folder-main-title">Memuat berkas...</h2>
              <p id="folderStats" class="folder-sub-stats">-</p>
            </div>
          </div>

          <!-- Drop Overlay -->
          <div id="dropOverlay" class="drop-zone-overlay">
            <div class="drop-icon-box">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
              </svg>
            </div>
            <div style="font-size: 1.15rem; font-weight: 700; color: var(--bmhg-navy);">Lepaskan file atau folder untuk mengunggah</div>
            <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 4px;">Mendukung multi-file & folder upload secara otomatis</div>
          </div>

          <!-- Grid View -->
          <div id="fileGrid" class="file-grid">
            <!-- Injected via JS -->
          </div>

          <!-- Gallery / Thumbnail View (Kotak-kotak Preview Gambar & Media) -->
          <div id="fileGallery" class="file-gallery" style="display:none;">
            <!-- Injected via JS -->
          </div>

          <!-- List View -->
          <table id="fileListTable" class="file-list-table" style="display:none;">
            <thead>
              <tr>
                <th style="width:36px; text-align:center;">
                  <input type="checkbox" id="selectAllCheckbox" class="file-select-checkbox" title="Pilih Semua">
                </th>
                <th>Nama</th>
                <th style="width:130px;">Ukuran</th>
                <th style="width:180px;">Tanggal Modifikasi</th>
                <th style="width:140px; text-align:right;">Aksi</th>
              </tr>
            </thead>
            <tbody id="fileListTbody">
              <!-- Injected via JS -->
            </tbody>
          </table>

          <!-- Empty State -->
          <div id="emptyState" class="empty-state" style="display:none;">
            <div class="empty-icon-wrap">
              <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
              </svg>
            </div>
            <div class="empty-title">Folder ini Kosong</div>
            <div class="empty-subtitle">Tarik dan letakkan file ke sini atau klik tombol Upload untuk menambahkan berkas baru.</div>
            <button class="btn btn-primary" onclick="document.getElementById('fileInput').click()">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
              </svg>
              Upload File Sekarang
            </button>
          </div>
        </div>
      </section>
    </main>
  </div>

  <!-- Hidden File Inputs for Picker -->
  <input type="file" id="fileInput" multiple style="display:none;">
  <input type="file" id="folderInput" webkitdirectory directory multiple style="display:none;">

  <!-- Floating Upload Drawer -->
  <div id="uploadDrawer" class="upload-drawer">
    <div class="upload-drawer-header">
      <div class="upload-drawer-title">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
          <polyline points="17 8 12 3 7 8"/>
          <line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        <span>Proses Pengunggahan</span>
      </div>
      <button id="btnCloseUploadDrawer" class="btn-icon-mini" title="Tutup">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div id="uploadQueueList" class="upload-list">
      <!-- Upload items here -->
    </div>
  </div>

  <!-- Modal: New Folder -->
  <div id="newFolderModal" class="modal-overlay">
    <div class="modal-box">
      <div class="modal-header">
        <div class="modal-title">Buat Folder Baru</div>
        <button class="btn-icon-mini btn-close-modal">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" for="newFolderNameInput">Nama Folder</label>
          <input type="text" id="newFolderNameInput" class="form-input" placeholder="Masukkan nama folder baru...">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-close-modal">Batal</button>
        <button id="btnConfirmNewFolder" class="btn btn-primary">Buat Folder</button>
      </div>
    </div>
  </div>

  <!-- Modal: Rename -->
  <div id="renameModal" class="modal-overlay">
    <div class="modal-box">
      <div class="modal-header">
        <div class="modal-title">Ganti Nama Berkas</div>
        <button class="btn-icon-mini btn-close-modal">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" for="renameInput">Nama Baru</label>
          <input type="text" id="renameInput" class="form-input">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-close-modal">Batal</button>
        <button id="btnConfirmRename" class="btn btn-primary">Simpan Nama</button>
      </div>
    </div>
  </div>

  <!-- Modal: Delete Confirmation -->
  <div id="deleteModal" class="modal-overlay">
    <div class="modal-box">
      <div class="modal-header">
        <div class="modal-title" style="color:var(--accent-rose);">Konfirmasi Penghapusan</div>
        <button class="btn-icon-mini btn-close-modal">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="modal-body">
        <p style="font-size:0.9rem; line-height:1.5; color:var(--text-secondary);">
          Apakah Anda yakin ingin menghapus <strong id="deleteItemName" style="color:var(--text-primary);"></strong> secara permanen?
        </p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-close-modal">Batal</button>
        <button id="btnConfirmDelete" class="btn btn-danger">Hapus Permanen</button>
      </div>
    </div>
  </div>

  <!-- Modal: Compress to ZIP -->
  <div id="compressModal" class="modal-overlay">
    <div class="modal-box">
      <div class="modal-header">
        <div class="modal-title">Kompres ke ZIP</div>
        <button class="btn-icon-mini btn-close-modal">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" for="compressZipNameInput">Nama File ZIP</label>
          <input type="text" id="compressZipNameInput" class="form-input" placeholder="arsip.zip">
        </div>
        <p style="font-size:0.8rem; color:var(--text-muted);">
          Item <strong id="compressItemName" style="color:var(--text-primary);"></strong> akan dikompresi ke format .ZIP di folder yang sama.
        </p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-close-modal">Batal</button>
        <button id="btnConfirmCompress" class="btn btn-primary">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="21 8 21 21 3 21 3 8"/>
            <rect x="1" y="3" width="22" height="5"/>
            <line x1="10" y1="12" x2="14" y2="12"/>
          </svg>
          Kompres Sekarang
        </button>
      </div>
    </div>
  </div>

  <!-- Modal: Extract Archive -->
  <div id="extractModal" class="modal-overlay">
    <div class="modal-box">
      <div class="modal-header">
        <div class="modal-title">Ekstrak Arsip File</div>
        <button class="btn-icon-mini btn-close-modal">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" for="extractFolderNameInput">Nama Folder Hasil Ekstraksi</label>
          <input type="text" id="extractFolderNameInput" class="form-input" placeholder="nama_folder">
        </div>
        <p style="font-size:0.8rem; color:var(--text-muted);">
          Seluruh isi file arsip <strong id="extractItemName" style="color:var(--text-primary);"></strong> akan diekstrak ke folder tersebut.
        </p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-close-modal">Batal</button>
        <button id="btnConfirmExtract" class="btn btn-primary">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="21 8 21 21 3 21 3 8"/>
            <polyline points="10 12 15 12 15 7"/>
            <line x1="15" y1="12" x2="9" y2="18"/>
          </svg>
          Ekstrak File
        </button>
      </div>
    </div>
  </div>

  <!-- Modal: Media & Code Preview -->
  <div id="previewModal" class="modal-overlay">
    <div class="modal-box preview-modal-box">
      <div class="modal-header">
        <div id="previewTitle" class="modal-title" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:600px;">Preview</div>
        <div style="display:flex; align-items:center; gap:8px;">
          <button id="btnDownloadPreview" class="btn btn-secondary" style="padding:4px 10px; font-size:0.8rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Unduh
          </button>
          <button class="btn-icon-mini btn-close-modal">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
      </div>
      <div id="previewContainer" class="preview-content">
        <!-- Media / Code injected here -->
      </div>
    </div>
  </div>

  <!-- Floating Bulk Selection Action Bar -->
  <div id="bulkActionBar" class="bulk-action-bar">
    <div class="bulk-info">
      <div class="bulk-badge-count" id="bulkCount">0</div>
      <span class="bulk-label">Item Dipilih</span>
    </div>
    <div class="bulk-actions">
      <button id="btnSelectAll" class="btn-bulk btn-bulk-secondary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        <span>Pilih Semua</span>
      </button>
      <button id="btnBulkDownload" class="btn-bulk btn-bulk-primary" title="Unduh semua item yang dipilih dalam 1 file ZIP">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span>Unduh (ZIP)</span>
      </button>
      <button id="btnBulkCompress" class="btn-bulk btn-bulk-secondary" title="Kompres item terpilih ke arsip ZIP di folder ini">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
        <span>Kompres ZIP</span>
      </button>
      <button id="btnBulkDelete" class="btn-bulk btn-bulk-danger" title="Hapus semua item terpilih">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        <span>Hapus</span>
      </button>
      <button id="btnDeselectAll" class="btn-bulk btn-bulk-ghost" title="Batalkan pilihan">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        <span>Batal</span>
      </button>
    </div>
  </div>

  <!-- Toast Container -->
  <div id="toastContainer" class="toast-container"></div>

  <script src="assets/js/app.js"></script>
</body>
</html>
