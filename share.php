<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/share-links.php';
$config = require __DIR__ . '/config.php';
header('Cache-Control: private, no-store');
header('Referrer-Policy: strict-origin');
$token = (string)($_GET['token'] ?? $_SESSION['active_share_token'] ?? $_SESSION['pending_share_token'] ?? '');
$share = $token !== '' ? findShareLink($token) : null;
if (!$share || empty($share['active']) || !is_dir((string)($share['path'] ?? ''))) {
    http_response_code(404);
    $pageError = 'Tautan berbagi tidak ditemukan atau sudah dicabut.';
} elseif (!empty($config['sso_enabled']) && empty($_SESSION['drive_user'])) {
    $_SESSION['pending_share_token'] = $token;
    session_write_close();
    header('Location: login.php');
    exit;
} else {
    $_SESSION['active_share_token'] = $token;
    unset($_SESSION['pending_share_token']);
    $pageError = '';
}
$user = $_SESSION['drive_user'] ?? ['name'=>'Tamu','email'=>''];
$rootName = $share ? basename((string)$share['path']) : 'Folder berbagi';
$permission = $share['permission'] ?? 'viewer';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($rootName, ENT_QUOTES, 'UTF-8') ?> · Drive BMHG</title>
  <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
  <style>
    .shared-shell{max-width:1180px;margin:32px auto;padding:0 20px}.shared-top{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px 22px;background:#fff;border:1px solid var(--border-color);border-radius:16px}.shared-heading h1{font-size:1.25rem;margin:0}.shared-heading p{margin:5px 0 0;color:var(--text-muted);font-size:.85rem}.shared-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.shared-breadcrumbs{display:flex;gap:8px;align-items:center;margin:20px 0;color:var(--text-muted);flex-wrap:wrap}.shared-breadcrumbs button{border:0;background:transparent;color:var(--bmhg-navy);cursor:pointer;font-weight:600}.shared-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px}.shared-card{background:#fff;border:1px solid var(--border-color);border-radius:12px;overflow:hidden;min-width:0}.shared-thumb{height:160px;display:flex;align-items:center;justify-content:center;background:#f1f5f9;overflow:hidden}.shared-thumb img{width:100%;height:100%;object-fit:cover}.shared-card-main{padding:11px 12px}.shared-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.shared-meta{color:var(--text-muted);font-size:.75rem;margin-top:3px}.shared-card-actions{display:flex;gap:6px;padding:0 12px 12px}.shared-card-actions .btn{padding:6px 9px;font-size:.78rem}.shared-drop{border:1px dashed #94a3b8;border-radius:12px;padding:18px;color:var(--text-muted);text-align:center;margin-bottom:16px}.shared-drop.active{border-color:var(--bmhg-navy);background:#eff6ff}.shared-error{margin:20px auto;max-width:640px;background:#fff;padding:26px;border-radius:14px;text-align:center}
    @media(max-width:680px){.shared-shell{margin:12px auto;padding:0 12px}.shared-top{align-items:flex-start;flex-direction:column}.shared-grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}.shared-thumb{height:130px}}
  </style>
</head>
<body>
<?php if ($pageError !== ''): ?>
  <div class="shared-error"><h2>Tautan tidak tersedia</h2><p><?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?></p></div>
<?php else: ?>
  <main class="shared-shell" id="sharedApp" data-root="<?= htmlspecialchars($rootName, ENT_QUOTES, 'UTF-8') ?>" data-permission="<?= htmlspecialchars($permission, ENT_QUOTES, 'UTF-8') ?>">
    <header class="shared-top">
      <div class="shared-heading"><h1 id="sharedFolderTitle"><?= htmlspecialchars($rootName, ENT_QUOTES, 'UTF-8') ?></h1><p>Folder dibagikan · Akses <?= $permission === 'editor' ? 'Editor' : 'Pelihat' ?> · Login SSO Beasiswa Mahaghora</p></div>
      <div class="shared-actions">
        <?php if ($permission === 'editor'): ?>
        <button id="sharedNewFolder" class="btn btn-secondary">Folder Baru</button>
        <button id="sharedUploadButton" class="btn btn-primary">Upload File</button>
        <input id="sharedUploadInput" type="file" multiple hidden>
        <?php endif; ?>
        <span><?= htmlspecialchars((string)($user['name'] ?? 'Tamu'), ENT_QUOTES, 'UTF-8') ?></span>
        <a class="btn btn-secondary" href="logout.php">Keluar</a>
      </div>
    </header>
    <nav id="sharedBreadcrumbs" class="shared-breadcrumbs" aria-label="Lokasi folder"></nav>
    <?php if ($permission === 'editor'): ?><div id="sharedDropZone" class="shared-drop">Tarik file ke sini untuk mengunggah ke folder yang dibagikan</div><?php endif; ?>
    <div id="sharedMessage" role="status" style="margin:10px 0;color:var(--text-muted);"></div>
    <section id="sharedGrid" class="shared-grid" aria-live="polite"></section>
  </main>
  <script src="assets/js/share.js?v=<?= filemtime(__DIR__ . '/assets/js/share.js') ?>"></script>
<?php endif; ?>
</body>
</html>
