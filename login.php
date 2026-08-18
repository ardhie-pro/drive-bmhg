<?php
/**
 * Halaman Login SSO Beasiswa Mahaghora
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$config = require __DIR__ . '/config.php';

// Jika sudah login, langsung alihkan ke dashboard drive
if (!empty($_SESSION['drive_user'])) {
    header('Location: index.php');
    exit;
}

// Tentukan base URL aplikasi drive saat ini
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$currentBaseUrl = $protocol . $host . ($scriptDir ? $scriptDir : '');

$ssoServerUrl = rtrim($config['sso_server_url'] ?? 'http://websitebmhg.test', '/');
$ssoLoginUrl = $ssoServerUrl . '/login?redirect=' . urlencode($currentBaseUrl);
$ssoGoogleUrl = $ssoServerUrl . '/google?redirect=' . urlencode($currentBaseUrl);

$errorMessage = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Portal Login SSO Local Drive - Beasiswa Mahaghora">
  <title>Masuk SSO - Beasiswa Mahaghora Drive</title>
  
  <link rel="shortcut icon" href="assets/images/logo-white.png">
  <link rel="icon" type="image/png" href="assets/images/logo-white.png">
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Archivo:wght@500;600;700&display=swap" rel="stylesheet">

  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    body {
      background-color: #EBF5FA;
      color: #1E293B;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
      background-image: 
        radial-gradient(circle at 10% 20%, rgba(26, 33, 133, 0.05) 0%, transparent 40%),
        radial-gradient(circle at 90% 80%, rgba(2, 132, 199, 0.06) 0%, transparent 40%);
    }

    .login-container {
      width: 100%;
      max-width: 440px;
      animation: fadeIn 0.3s ease-out;
    }

    @keyframes fadeIn {
      from { transform: translateY(10px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }

    .login-card {
      background: #FFFFFF;
      border: 1px solid rgba(26, 33, 133, 0.12);
      border-radius: 16px;
      padding: 36px 32px;
      box-shadow: 0 10px 30px -5px rgba(26, 33, 133, 0.08), 0 4px 12px -2px rgba(0, 0, 0, 0.03);
    }

    .brand-header {
      text-align: center;
      margin-bottom: 28px;
    }

    .brand-logo {
      height: 42px;
      width: auto;
      max-width: 220px;
      object-fit: contain;
      margin-bottom: 16px;
    }

    .login-title {
      font-size: 1.25rem;
      font-weight: 800;
      color: #1A2185;
      margin-bottom: 6px;
      letter-spacing: -0.3px;
    }

    .login-desc {
      font-size: 0.85rem;
      color: #64748B;
      line-height: 1.5;
    }

    .alert-error {
      background: #FFF1F2;
      border: 1px solid #FECDD3;
      color: #E11D48;
      border-radius: 10px;
      padding: 12px 14px;
      font-size: 0.82rem;
      font-weight: 600;
      margin-bottom: 20px;
      display: flex;
      align-items: flex-start;
      gap: 10px;
      line-height: 1.4;
    }

    .btn-sso {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      width: 100%;
      padding: 13px 20px;
      border-radius: 10px;
      font-size: 0.9rem;
      font-weight: 700;
      text-decoration: none;
      transition: all 0.2s ease;
      cursor: pointer;
      border: none;
      margin-bottom: 12px;
    }

    .btn-sso-primary {
      background: #1A2185;
      color: #FFFFFF;
      box-shadow: 0 4px 14px rgba(26, 33, 133, 0.25);
    }

    .btn-sso-primary:hover {
      background: #121763;
      box-shadow: 0 6px 20px rgba(26, 33, 133, 0.35);
      transform: translateY(-1px);
    }

    .btn-sso-google {
      background: #FFFFFF;
      color: #334155;
      border: 1px solid #CBD5E1;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }

    .btn-sso-google:hover {
      background: #F8FAFC;
      border-color: #94A3B8;
      color: #1A2185;
      transform: translateY(-1px);
    }

    .divider {
      display: flex;
      align-items: center;
      margin: 22px 0;
      color: #94A3B8;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .divider::before, .divider::after {
      content: '';
      flex: 1;
      height: 1px;
      background: #E2E8F0;
    }

    .divider span {
      padding: 0 12px;
    }

    .login-footer-info {
      background: #F8FAFC;
      border: 1px solid #E2E8F0;
      border-radius: 10px;
      padding: 12px 14px;
      font-size: 0.76rem;
      color: #64748B;
      line-height: 1.45;
      text-align: center;
      margin-top: 20px;
    }

    .login-footer-info strong {
      color: #1A2185;
    }
  </style>
</head>
<body>

  <div class="login-container">
    <div class="login-card">
      <div class="brand-header">
        <img src="assets/images/logo-blue-ui.svg" alt="Logo Beasiswa Mahaghora" class="brand-logo">
        <h1 class="login-title">Local Drive Storage</h1>
        <p class="login-desc">Silakan masuk menggunakan akun Single Sign-On (SSO) <strong>Website Beasiswa Mahaghora</strong> untuk mengakses penyimpanan drive.</p>
      </div>

      <?php if ($errorMessage): ?>
        <div class="alert-error">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0; margin-top:2px;">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          <span><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      <?php endif; ?>

      <!-- Tombol Login SSO Utama -->
      <a href="<?= htmlspecialchars($ssoLoginUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-sso btn-sso-primary">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
          <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
          <polyline points="10 17 15 12 10 7"/>
          <line x1="15" y1="12" x2="3" y2="12"/>
        </svg>
        <span>Masuk dengan Akun BMHG</span>
      </a>

      <!-- Tombol Login Google SSO -->
      <a href="<?= htmlspecialchars($ssoGoogleUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-sso btn-sso-google">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="18" height="18">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>
        <span>Masuk dengan Google SSO</span>
      </a>

      <div class="login-footer-info">
        🔒 Hanya akun berstatus <strong>Admin</strong> atau <strong>Beswan</strong> yang memiliki hak akses membuka dan mengelola penyimpanan drive.
      </div>
    </div>
  </div>

</body>
</html>
