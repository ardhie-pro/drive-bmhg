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

          <button id="btnUploadFile" class="btn btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
              <polyline points="17 8 12 3 7 8"/>
              <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <span>Upload File</span>
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
            <button id="btnViewGrid" class="btn-view-toggle active" title="Grid View">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"/>
                <rect x="14" y="3" width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/>
                <rect x="3" y="14" width="7" height="7"/>
              </svg>
            </button>
            <button id="btnViewList" class="btn-view-toggle" title="List View">
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
          <div class="user-profile-badge">
            <div class="user-avatar-circle"><?= htmlspecialchars(strtoupper(substr($currentUser['name'] ?? 'U', 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="user-profile-info">
              <span class="user-name-text" title="<?= htmlspecialchars($currentUser['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($currentUser['name'], ENT_QUOTES, 'UTF-8') ?></span>
              <span class="user-role-tag"><?= htmlspecialchars(ucfirst($currentUser['status'] ?? 'user'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <a href="logout.php" class="btn-logout-icon" title="Keluar / Logout SSO">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
              </svg>
            </a>
          </div>
        </div>
      </header>

      <!-- Explorer View Content -->
      <section class="explorer-container">
        <!-- Subheader info -->
        <div class="explorer-meta-header">
          <div class="folder-title-wrap">
            <h1 id="folderTitle" class="folder-title">Memuat...</h1>
            <span id="folderStats" class="folder-stats-badge">0 item</span>
          </div>
        </div>

        <div class="content-board-card">
          <!-- Drop Overlay -->
          <div id="dropOverlay" class="drop-zone-overlay">
            <div class="drop-icon-box">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
              </svg>
            </div>
            <div style="font-size: 1.15rem; font-weight: 700; color: var(--bmhg-navy);">Lepaskan file untuk mengunggah ke folder ini</div>
            <div style="font-size: 0.85rem; color: var(--text-secondary);">Mendukung multi-file upload & file berukuran besar</div>
          </div>

          <!-- Grid View -->
          <div id="fileGrid" class="file-grid">
            <!-- Injected via JS -->
          </div>

          <!-- List View -->
          <table id="fileListTable" class="file-list-table" style="display:none;">
            <thead>
              <tr>
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

  <!-- Hidden File Input for Picker -->
  <input type="file" id="fileInput" multiple style="display:none;">

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

  <!-- Toast Container -->
  <div id="toastContainer" class="toast-container"></div>

  <script src="assets/js/app.js"></script>
</body>
</html>
