<?php
/**
 * Handler Callback SSO Beasiswa Mahaghora
 * Menerima token dari websitebmhg dan memverifikasi data user
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$config = require __DIR__ . '/config.php';
$token = $_GET['token'] ?? '';

if (empty($token)) {
    header('Location: login.php?error=' . urlencode('Token SSO tidak ditemukan.'));
    exit;
}

$verifyUrl = rtrim($config['sso_verify_url'] ?? 'http://websitebmhg.test/sso/verify', '/') . '?token=' . urlencode($token);

// Request verifikasi token ke Website BMHG
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $verifyUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    // Fallback coba jika verifyUrl menggunakan localhost
    $fallbackUrl = 'http://127.0.0.1/websitebmhg/public/sso/verify?token=' . urlencode($token);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fallbackUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
}

if ($response === false || $httpCode !== 200) {
    header('Location: login.php?error=' . urlencode('Verifikasi SSO gagal (' . ($curlError ?: 'Kode HTTP: ' . $httpCode) . '). Pastikan Website BMHG sedang aktif.'));
    exit;
}

$data = json_decode($response, true);
$user = $data['user'] ?? null;

if (!$user || empty($user['id'])) {
    header('Location: login.php?error=' . urlencode('Data pengguna SSO tidak valid.'));
    exit;
}

// Cek hak akses role
$allowedRoles = $config['allowed_roles'] ?? ['admin', 'beswan'];
$userStatus = strtolower($user['status'] ?? '');

if (!empty($allowedRoles) && !in_array($userStatus, array_map('strtolower', $allowedRoles), true)) {
    header('Location: login.php?error=' . urlencode("Akun Anda ({$user['email']}) dengan status '{$userStatus}' tidak memiliki izin mengakses Drive. Hubungi Admin."));
    exit;
}

// Simpan data sesi login
$_SESSION['drive_user'] = [
    'id' => $user['id'],
    'name' => $user['name'] ?? 'User BMHG',
    'email' => $user['email'] ?? '',
    'status' => $user['status'] ?? 'user',
    'login_time' => time()
];

header('Location: index.php');
exit;
