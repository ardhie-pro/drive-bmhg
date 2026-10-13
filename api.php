<?php
/**
 * Local Drive Manager - Backend API
 * Handles drive detection (USB Flashdisk, Harddisk, SSD), file navigation,
 * chunked upload, zip downloads, file operations & previews.
 */

declare(strict_types=1);
require_once __DIR__ . '/upload-metadata.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/video.php';

// Set execution limits for large file streaming / zip creation
ini_set('memory_limit', '192M');
ini_set('max_execution_time', '3600');
set_time_limit(3600);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load Configuration
$configFile = __DIR__ . '/config.php';
$config = file_exists($configFile) ? require $configFile : [
    'sso_enabled' => true,
    'excluded_drives' => ['C:'],
    'hide_system_files' => true,
    'hidden_system_names' => ['$RECYCLE.BIN', 'System Volume Information']
];
$excludedDrives = array_map(function($d) {
    return strtoupper(rtrim(trim($d), '\\/ :')) . ':';
}, $config['excluded_drives'] ?? ['C:']);
$hideSystemFiles = (bool)($config['hide_system_files'] ?? true);
$hiddenSystemNames = array_map('strtolower', $config['hidden_system_names'] ?? ['$recycle.bin', 'system volume information']);

function sendJson(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Validasi Sesi Pengguna jika SSO aktif
if (!empty($config['sso_enabled']) && empty($_SESSION['drive_user'])) {
    sendJson(['success' => false, 'message' => 'Sesi login telah berakhir. Silakan masuk kembali.', 'auth_required' => true], 401);
}

// Tutup lock file sesi PHP segera agar tidak memblokir request konkuren lainnya
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (in_array($action, ['compress_bulk', 'compress_zip', 'extract_archive', 'download_bulk'], true)
    || ($action === 'download' && is_dir(normalizePath($_GET['path'] ?? '')))) {
    requireWorkSlot('archive', 1);
    if ((float)disk_free_space(sys_get_temp_dir()) < 512 * 1024 * 1024) {
        sendJson(['success' => false, 'message' => 'Ruang disk tidak cukup untuk memproses arsip.'], 507);
    }
}
if ($action === 'upload_chunk') requireWorkSlot('upload', 4);

function isPathExcluded(string $path, array $excludedDrives): bool {
    $clean = strtoupper(normalizePath($path));
    foreach ($excludedDrives as $excluded) {
        $prefixWithSlash = $excluded . DIRECTORY_SEPARATOR;
        if (str_starts_with($clean, $prefixWithSlash) || $clean === $excluded || $clean === $excluded . '\\') {
            return true;
        }
    }
    return false;
}

function formatBytes(float|int $bytes, int $precision = 2): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $pow = floor(log($bytes, 1024));
    $pow = min((int)$pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function normalizePath(string $path): string {
    $path = trim($path);
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    // Ensure Windows drive path has trailing slash if it's root e.g. "C:" -> "C:\"
    if (preg_match('/^[A-Za-z]:$/', $path)) {
        $path .= DIRECTORY_SEPARATOR;
    }
    return $path;
}

function getMimeType(string $filePath): string {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'video/ogg', 'mkv' => 'video/x-matroska',
        'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'flac' => 'audio/flac', 'aac' => 'audio/aac',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain', 'log' => 'text/plain', 'csv' => 'text/csv',
        'json' => 'application/json', 'xml' => 'application/xml', 'html' => 'text/html', 'css' => 'text/css',
        'js' => 'application/javascript', 'php' => 'text/plain', 'md' => 'text/markdown',
        'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed', '7z' => 'application/x-7z-compressed',
        'tar' => 'application/x-tar', 'gz' => 'application/gzip',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
    ];
    return $mimeTypes[$ext] ?? (function_exists('mime_content_type') && @is_file($filePath) ? @mime_content_type($filePath) ?: 'application/octet-stream' : 'application/octet-stream');
}

/**
 * Menghasilkan & men-cache thumbnail gambar super cepat (WebP / JPEG 240px)
 */
function serveImageThumbnail(string $filePath, int $maxWidth = 280, int $maxHeight = 280): void {
    serveOptimizedImage($filePath, $maxWidth, $maxHeight);
}

/**
 * Mendapatkan daftar seluruh drive aktif di Windows secara instan (0.01 - 0.05s)
 */
function getSystemDrives(array $excludedDrives = []): array {
    $cacheKey = md5(json_encode($excludedDrives));
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drive_list_cache_' . $cacheKey . '.json';
    
    // Cache drive list selama 5 detik untuk performa maksimal
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 5) {
        $cached = @json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $drives = [];
    $activeLetters = [];

    // 1. Scan semua drive letter A-Z secara native PHP (< 1ms)
    foreach (range('A', 'Z') as $letter) {
        $driveLetter = $letter . ':';
        if (in_array($driveLetter, $excludedDrives, true)) {
            continue;
        }

        $path = $letter . ':\\';
        if (@is_dir($path)) {
            $total = (float)@disk_total_space($path);
            $free = (float)@disk_free_space($path);
            $used = max(0, $total - $free);
            $activeLetters[$letter] = [
                'id' => $driveLetter,
                'path' => $path,
                'total' => $total,
                'free' => $free,
                'used' => $used,
                'usedPercentage' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
            ];
        }
    }

    if (empty($activeLetters)) {
        return [];
    }

    // 2. Ambil metadata volume label & tipe drive secara cepat
    foreach ($activeLetters as $letter => $info) {
        $volOutput = @shell_exec("cmd /c vol {$letter}: 2>nul");
        $volName = '';
        if ($volOutput && preg_match('/Volume in drive [A-Z] is (.*)/i', $volOutput, $m)) {
            $volName = trim($m[1]);
        }

        $typeOutput = @shell_exec("cmd /c fsutil fsinfo drivetype {$letter}: 2>nul");
        $isRemovable = false;
        $typeLabel = 'Local Disk';
        $icon = 'hard-drive';
        $driveType = 3;

        if ($typeOutput) {
            if (stripos($typeOutput, 'Removable') !== false) {
                $isRemovable = true;
                $typeLabel = 'USB Flashdisk';
                $icon = 'usb';
                $driveType = 2;
            } elseif (stripos($typeOutput, 'CD-ROM') !== false) {
                $typeLabel = 'CD/DVD Drive';
                $icon = 'disc';
                $driveType = 5;
            } elseif (stripos($typeOutput, 'Remote') !== false || stripos($typeOutput, 'Network') !== false) {
                $typeLabel = 'Network Drive';
                $icon = 'network';
                $driveType = 4;
            } elseif (stripos($typeOutput, 'Fixed') !== false) {
                $typeLabel = ($letter === 'C') ? 'System Disk' : 'Local Disk';
                $icon = 'hard-drive';
                $driveType = 3;
            }
        }

        $drives[] = [
            'id' => $info['id'],
            'path' => $info['path'],
            'label' => $volName ?: ($isRemovable ? 'USB Flashdisk' : 'Local Disk (' . $info['id'] . ')'),
            'type' => $typeLabel,
            'driveType' => $driveType,
            'isRemovable' => $isRemovable,
            'fileSystem' => '',
            'icon' => $icon,
            'totalSize' => $info['total'],
            'freeSize' => $info['free'],
            'usedSize' => $info['used'],
            'usedPercentage' => $info['usedPercentage'],
            'totalFormatted' => formatBytes($info['total']),
            'freeFormatted' => formatBytes($info['free']),
            'usedFormatted' => formatBytes($info['used']),
            'isAccessible' => true
        ];
    }

    if (!empty($drives)) {
        @file_put_contents($cacheFile, json_encode($drives));
    }

    return $drives;
}

// Router Aksi
try {
    switch ($action) {
        case 'get_drives':
            $drives = getSystemDrives($excludedDrives);
            sendJson(['success' => true, 'drives' => $drives]);
            break;

        case 'list_files':
            $reqPath = $_GET['path'] ?? '';
            if (empty($reqPath)) {
                $availableDrives = getSystemDrives($excludedDrives);
                if (!empty($availableDrives)) {
                    $usb = null;
                    foreach ($availableDrives as $d) {
                        if (!empty($d['isRemovable'])) { $usb = $d; break; }
                    }
                    $reqPath = $usb ? $usb['path'] : $availableDrives[0]['path'];
                } else {
                    $reqPath = '';
                }
            }

            if (empty($reqPath)) {
                sendJson([
                    'success' => true,
                    'currentPath' => '',
                    'parentPath' => null,
                    'items' => [],
                    'totalItems' => 0,
                    'folderSizeFormatted' => '0 B',
                    'driveFreeFormatted' => '0 B',
                    'driveTotalFormatted' => '0 B',
                    'message' => 'Belum ada drive atau flashdisk yang dapat diakses.'
                ]);
            }

            $path = normalizePath($reqPath);

            if (isPathExcluded($path, $excludedDrives)) {
                sendJson(['success' => false, 'message' => 'Akses ke drive ini dinonaktifkan dalam konfigurasi keamanan (config.php).'], 403);
            }

            if (!is_dir($path)) {
                sendJson(['success' => false, 'message' => 'Direktori ' . htmlspecialchars($path) . ' tidak ditemukan atau tidak dapat diakses.'], 404);
            }

            // Dapatkan info parent directory
            $parentPath = null;
            $trimmed = rtrim($path, DIRECTORY_SEPARATOR);
            if (strlen($trimmed) > 2) {
                $parent = dirname($trimmed);
                if (preg_match('/^[A-Za-z]:$/', $parent)) {
                    $parent .= DIRECTORY_SEPARATOR;
                }
                $parentPath = $parent;
            }

            $entries = [];
            $handle = @scandir($path);
            if ($handle === false) {
                sendJson(['success' => false, 'message' => 'Izin ditolak atau direktori tidak dapat dibaca.'], 403);
            }

            $totalItems = 0;
            $totalSize = 0;

            $uploadMetadata = readUploadMetadata($path);
            foreach ($handle as $item) {
                if ($item === '.' || $item === '..') continue;
                if (strtolower($item) === UPLOAD_METADATA_FILE) continue;
                if (str_starts_with($item, '.drive-part-')) continue;

                // Sembunyikan file sistem Windows jika diaktifkan di config
                if ($hideSystemFiles) {
                    $lowerItem = strtolower($item);
                    if (in_array($lowerItem, $hiddenSystemNames, true) || str_starts_with($item, '$')) {
                        continue;
                    }
                }
                
                $fullPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $item;
                $isDir = @is_dir($fullPath);
                $size = 0;
                $mtime = @filemtime($fullPath) ?: null;

                if (!$isDir) {
                    $size = (float)@filesize($fullPath);
                    $totalSize += $size;
                }
                $totalItems++;

                $ext = $isDir ? '' : strtolower(pathinfo($item, PATHINFO_EXTENSION));

                $entries[] = [
                    'name' => $item,
                    'uploadedBy' => $uploadMetadata[strtolower($item)] ?? null,
                    'path' => $fullPath,
                    'isDir' => $isDir,
                    'size' => $size,
                    'sizeFormatted' => $isDir ? '-' : formatBytes($size),
                    'extension' => $ext,
                    'mimeType' => $isDir ? 'directory' : getMimeType($fullPath),
                    'modifiedTime' => $mtime ? date('Y-m-d H:i:s', $mtime) : '-',
                    'isReadable' => @is_readable($fullPath),
                    'isWritable' => @is_writable($fullPath)
                ];
            }

            // Urutkan: Folder lebih dulu, lalu File, secara ascending nama
            usort($entries, function($a, $b) {
                if ($a['isDir'] !== $b['isDir']) {
                    return $a['isDir'] ? -1 : 1;
                }
                return strnatcasecmp($a['name'], $b['name']);
            });

            // Hitung sisa ruang pada path saat ini
            $freeSpace = @disk_free_space($path) ?: 0;
            $totalSpace = @disk_total_space($path) ?: 0;

            sendJson([
                'success' => true,
                'currentPath' => $path,
                'parentPath' => $parentPath,
                'items' => $entries,
                'totalItems' => $totalItems,
                'folderSizeFormatted' => formatBytes($totalSize),
                'driveFreeFormatted' => formatBytes((float)$freeSpace),
                'driveTotalFormatted' => formatBytes((float)$totalSpace),
            ]);
            break;

        case 'create_folder':
            $targetPath = normalizePath($_POST['path'] ?? '');
            $folderName = trim($_POST['name'] ?? '');

            if (isPathExcluded($targetPath, $excludedDrives)) {
                sendJson(['success' => false, 'message' => 'Akses ke drive ini diblokir pada konfigurasi keamanan.'], 403);
            }

            if (empty($targetPath) || empty($folderName)) {
                sendJson(['success' => false, 'message' => 'Path dan nama folder wajib diisi.'], 400);
            }

            $sanitized = preg_replace('/[\\/\\\\:\*\?"<>\|]/', '', $folderName);
            if (empty($sanitized)) {
                sendJson(['success' => false, 'message' => 'Nama folder mengandung karakter yang tidak diizinkan.'], 400);
            }

            $newFolderPath = rtrim($targetPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sanitized;

            if (file_exists($newFolderPath)) {
                sendJson(['success' => false, 'message' => 'Folder atau file dengan nama tersebut sudah ada.'], 409);
            }

            if (@mkdir($newFolderPath, 0777, true)) {
                sendJson(['success' => true, 'message' => 'Folder berhasil dibuat.', 'path' => $newFolderPath]);
            } else {
                sendJson(['success' => false, 'message' => 'Gagal membuat folder. Periksa izin akses.'], 500);
            }
            break;

        case 'move':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                sendJson(['success' => false, 'message' => 'Gunakan POST.'], 405);
            }
            $source = realpath(normalizePath($_POST['source'] ?? ''));
            $destinationDir = realpath(normalizePath($_POST['destination'] ?? ''));
            if (!$source || !$destinationDir || !is_dir($destinationDir)
                || strtolower(basename($source)) === UPLOAD_METADATA_FILE
                || preg_match('/^[A-Za-z]:[\\\\\/]?$/', $source)) {
                sendJson(['success' => false, 'message' => 'Sumber atau folder tujuan tidak valid.'], 400);
            }
            if (isPathExcluded($source, $excludedDrives) || isPathExcluded($destinationDir, $excludedDrives)) {
                sendJson(['success' => false, 'message' => 'Drive ini tidak diizinkan.'], 403);
            }
            $destination = $destinationDir . DIRECTORY_SEPARATOR . basename($source);
            if (strtolower($destinationDir) === strtolower($source)
                || str_starts_with(strtolower($destinationDir . DIRECTORY_SEPARATOR), strtolower($source . DIRECTORY_SEPARATOR))) {
                sendJson(['success' => false, 'message' => 'Folder tidak bisa dipindahkan ke dalam dirinya sendiri.'], 400);
            }
            if (file_exists($destination)) {
                sendJson(['success' => false, 'message' => 'Nama yang sama sudah ada di folder tujuan.'], 409);
            }
            if (!@rename($source, $destination)) {
                sendJson(['success' => false, 'message' => 'Gagal memindahkan. Periksa izin dan pastikan file tidak sedang dibuka.'], 500);
            }
            transferUploadMetadata($source, $destination);
            sendJson(['success' => true, 'message' => 'Berhasil dipindahkan.', 'newPath' => $destination]);
            break;

        case 'rename':
            $oldPath = normalizePath($_POST['oldPath'] ?? '');
            $newName = trim($_POST['newName'] ?? '');

            if (isPathExcluded($oldPath, $excludedDrives)) {
                sendJson(['success' => false, 'message' => 'Akses ke drive ini diblokir pada konfigurasi keamanan.'], 403);
            }

            if (empty($oldPath) || empty($newName) || !file_exists($oldPath)) {
                sendJson(['success' => false, 'message' => 'File / folder tidak valid.'], 400);
            }

            $sanitized = preg_replace('/[\\/\\\\:\*\?"<>\|]/', '', $newName);
            if (empty($sanitized)) {
                sendJson(['success' => false, 'message' => 'Nama baru mengandung karakter yang tidak diizinkan.'], 400);
            }

            $parentDir = dirname($oldPath);
            $newPath = rtrim($parentDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sanitized;

            if (file_exists($newPath) && strtolower($oldPath) !== strtolower($newPath)) {
                sendJson(['success' => false, 'message' => 'Nama tersebut sudah digunakan.'], 409);
            }

            if (@rename($oldPath, $newPath)) {
                if (strtolower($oldPath) !== strtolower($newPath)) transferUploadMetadata($oldPath, $newPath);
                sendJson(['success' => true, 'message' => 'Berhasil mengubah nama.', 'newPath' => $newPath]);
            } else {
                sendJson(['success' => false, 'message' => 'Gagal mengubah nama. File mungkin sedang digunakan.'], 500);
            }
            break;

        case 'delete':
            $targetPath = normalizePath($_POST['path'] ?? '');

            if (isPathExcluded($targetPath, $excludedDrives)) {
                sendJson(['success' => false, 'message' => 'Akses ke drive ini diblokir pada konfigurasi keamanan.'], 403);
            }

            if (empty($targetPath) || !file_exists($targetPath)) {
                sendJson(['success' => false, 'message' => 'Target tidak ditemukan.'], 404);
            }

            if (preg_match('/^[A-Za-z]:\\\\?$/', $targetPath)) {
                sendJson(['success' => false, 'message' => 'Tidak dapat menghapus root drive!'], 403);
            }

            function deleteRecursive(string $target): bool {
                if (is_dir($target)) {
                    $files = @scandir($target);
                    if ($files !== false) {
                        foreach ($files as $file) {
                            if ($file === '.' || $file === '..') continue;
                            $current = $target . DIRECTORY_SEPARATOR . $file;
                            if (is_dir($current)) {
                                deleteRecursive($current);
                            } else {
                                @unlink($current);
                            }
                        }
                    }
                    return @rmdir($target);
                } else {
                    return @unlink($target);
                }
            }

            if (deleteRecursive($targetPath)) {
                setUploadMetadata($targetPath, null);
                sendJson(['success' => true, 'message' => 'Berhasil dihapus.']);
            } else {
                sendJson(['success' => false, 'message' => 'Gagal menghapus file/folder. Pastikan tidak sedang dibuka oleh aplikasi lain.'], 500);
            }
            break;

        case 'upload_chunk':
            $targetDir = normalizePath($_POST['targetDir'] ?? '');
            $fileName = trim($_POST['fileName'] ?? '');
            $relativePath = trim($_POST['relativePath'] ?? '');
            $chunkIndex = (int)($_POST['chunkIndex'] ?? 0);
            $totalChunks = (int)($_POST['totalChunks'] ?? 1);
            $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['uploadId'] ?? uniqid('upl_', true));
            if ($totalChunks < 1 || $totalChunks > 100000 || $chunkIndex < 0 || $chunkIndex >= $totalChunks) {
                sendJson(['success' => false, 'message' => 'Nomor potongan tidak valid.'], 400);
            }
            foreach (preg_split('/[\\\\\/]/', $relativePath ?: $fileName) as $segment) {
                if ($segment === '..' || str_contains($segment, ':') || str_contains($segment, "\0")
                    || strtolower($segment) === UPLOAD_METADATA_FILE) {
                    sendJson(['success' => false, 'message' => 'Nama atau path upload tidak valid.'], 400);
                }
            }

            if (isPathExcluded($targetDir, $excludedDrives)) {
                sendJson(['success' => false, 'message' => 'Akses ke drive ini diblokir pada konfigurasi keamanan.'], 403);
            }

            if (empty($targetDir) || !is_dir($targetDir) || (empty($fileName) && empty($relativePath))) {
                sendJson(['success' => false, 'message' => 'Target direktori tidak valid.'], 400);
            }

            if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
                sendJson(['success' => false, 'message' => 'Gagal menerima potongan file.'], 400);
            }

            if ((int)$_FILES['chunk']['size'] > 2 * 1024 * 1024) {
                sendJson(['success' => false, 'message' => 'Potongan upload maksimal 2 MB.'], 413);
            }
            if ((float)disk_free_space(sys_get_temp_dir()) < 512 * 1024 * 1024
                || (float)disk_free_space($targetDir) < 512 * 1024 * 1024) {
                sendJson(['success' => false, 'message' => 'Ruang disk hampir penuh. Upload dihentikan.'], 507);
            }
            $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drive_uploads_' . hash('sha256', __DIR__) . DIRECTORY_SEPARATOR . hash('sha256', session_id() . ':' . $uploadId);
            if (!is_dir($tempDir)) {
                @mkdir($tempDir, 0777, true);
            }
            $uploadLock = @fopen($tempDir . DIRECTORY_SEPARATOR . 'upload.lock', 'c');
            if (!$uploadLock || !flock($uploadLock, LOCK_EX | LOCK_NB)) {
                header('Retry-After: 1');
                sendJson(['success' => false, 'retryable' => true, 'message' => 'Potongan sebelumnya masih diproses.'], 503);
            }
            register_shutdown_function(static function () use ($uploadLock): void {
                flock($uploadLock, LOCK_UN);
                fclose($uploadLock);
            });
            $manifestPath = $tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
            $signature = hash('sha256', $targetDir . ':' . $fileName . ':' . $relativePath . ':' . $totalChunks);
            $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : ['signature' => $signature];
            if (($manifest['signature'] ?? '') !== $signature) {
                sendJson(['success' => false, 'message' => 'Tujuan atau identitas upload berubah.'], 409);
            }
            if (!empty($manifest['completed'])) {
                sendJson(['success' => true, 'completed' => true, 'filePath' => $manifest['filePath'], 'message' => 'Upload sudah selesai.']);
            }
            if (file_put_contents($manifestPath, json_encode($manifest)) === false) {
                sendJson(['success' => false, 'message' => 'Gagal mencatat upload.'], 500);
            }

            $chunkFile = $tempDir . DIRECTORY_SEPARATOR . sprintf('chunk_%05d', $chunkIndex);
            $alreadyReceived = is_file($chunkFile);
            if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile)) {
                sendJson(['success' => false, 'message' => 'Gagal menyimpan potongan file.'], 500);
            }

            // Constant-time completion check rather than rescanning every chunk on every request.
            $manifest['received'] = ($manifest['received'] ?? 0) + ($alreadyReceived ? 0 : 1);
            if (file_put_contents($manifestPath, json_encode($manifest)) === false) {
                if (!$alreadyReceived) @unlink($chunkFile);
                sendJson(['success' => false, 'message' => 'Gagal mencatat potongan upload.'], 500);
            }
            $isComplete = $manifest['received'] === $totalChunks;

            if ($isComplete) {
                if (!empty($relativePath)) {
                    // Sanitasi relative path untuk mencegah zip slip / path traversal
                    $cleanRel = str_replace(['..', "\0"], '', $relativePath);
                    $cleanRel = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $cleanRel), DIRECTORY_SEPARATOR);
                    $finalDestination = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $cleanRel;
                } else {
                    $cleanFileName = preg_replace('/[\\/\\\\:\*\?"<>\|]/', '', $fileName);
                    $finalDestination = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $cleanFileName;
                }

                $parentDest = dirname($finalDestination);
                if (!is_dir($parentDest)) {
                    @mkdir($parentDest, 0777, true);
                }

                // Serialize naming/commit in this folder so concurrent users never overwrite each other.
                $destinationLockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drive_dest_' . hash('sha256', strtolower($parentDest)) . '.lock';
                $destinationLock = @fopen($destinationLockPath, 'c');
                if (!$destinationLock || !flock($destinationLock, LOCK_EX | LOCK_NB)) {
                    header('Retry-After: 1');
                    sendJson(['success' => false, 'retryable' => true, 'message' => 'Folder tujuan sedang menyelesaikan upload lain.'], 503);
                }
                register_shutdown_function(static function () use ($destinationLock): void {
                    flock($destinationLock, LOCK_UN);
                    fclose($destinationLock);
                });
                if (file_exists($finalDestination)) {
                    $info = pathinfo($finalDestination);
                    $namePart = $info['filename'];
                    $extPart = isset($info['extension']) ? '.' . $info['extension'] : '';
                    $finalDestination = $parentDest . DIRECTORY_SEPARATOR . $namePart . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . $extPart;
                }

                $stagingPath = $parentDest . DIRECTORY_SEPARATOR . '.drive-part-' . bin2hex(random_bytes(8));
                $outHandle = @fopen($stagingPath, 'xb');
                if (!$outHandle) {
                    sendJson(['success' => false, 'message' => 'Gagal menulis file tujuan akhir. Periksa izin folder.'], 500);
                }
                register_shutdown_function(static function () use ($stagingPath): void { @unlink($stagingPath); });

                for ($i = 0; $i < $totalChunks; $i++) {
                    $part = $tempDir . DIRECTORY_SEPARATOR . sprintf('chunk_%05d', $i);
                    $inHandle = fopen($part, 'rb');
                    if (!$inHandle) {
                        fclose($outHandle);
                        sendJson(['success' => false, 'message' => 'Potongan upload tidak lengkap. Ulangi upload.'], 500);
                    }
                    if ($inHandle) {
                        while (!feof($inHandle)) {
                            $buffer = fread($inHandle, 1048576);
                            if ($buffer === false || fwrite($outHandle, $buffer) !== strlen($buffer)) {
                                fclose($inHandle);
                                fclose($outHandle);
                                sendJson(['success' => false, 'message' => 'Disk gagal menulis file. Coba ulang upload.'], 507);
                            }
                        }
                        fclose($inHandle);
                    }
                }
                fclose($outHandle);
                if (!@rename($stagingPath, $finalDestination)) {
                    sendJson(['success' => false, 'message' => 'Gagal menyelesaikan file upload.'], 500);
                }

                $user = $_SESSION['drive_user'] ?? null;
                setUploadMetadata($finalDestination, [
                    'id' => $user['id'] ?? null,
                    'name' => $user['name'] ?? 'Developer lokal (tanpa login)',
                    'uploadedAt' => date(DATE_ATOM),
                ]);
                $manifest['completed'] = true;
                $manifest['filePath'] = $finalDestination;
                file_put_contents($manifestPath, json_encode($manifest));
                for ($i = 0; $i < $totalChunks; $i++) @unlink($tempDir . DIRECTORY_SEPARATOR . sprintf('chunk_%05d', $i));
                if (str_starts_with(getMimeType($finalDestination), 'video/')) {
                    try { videoPreviewStatus($finalDestination); } catch (Throwable $error) { error_log('Video preview queue unavailable.'); }
                }

                sendJson([
                    'success' => true,
                    'completed' => true,
                    'message' => 'File berhasil diunggah sepenuhnya.',
                    'filePath' => $finalDestination
                ]);
            } else {
                sendJson([
                    'success' => true,
                    'completed' => false,
                    'chunkIndex' => $chunkIndex,
                    'message' => "Potongan {$chunkIndex} berhasil diunggah."
                ]);
            }
            break;

        case 'download_bulk':
            $pathsJson = $_REQUEST['paths'] ?? '';
            $paths = is_array($pathsJson) ? $pathsJson : @json_decode($pathsJson, true);
            if (!is_array($paths) || empty($paths)) {
                http_response_code(400);
                die('Daftar file yang dipilih tidak valid.');
            }

            if (!class_exists('ZipArchive')) {
                http_response_code(500);
                die('PHP ZipArchive tidak aktif pada server.');
            }

            $validPaths = [];
            foreach ($paths as $p) {
                $norm = normalizePath($p);
                if (!isPathExcluded($norm, $excludedDrives) && file_exists($norm)) {
                    $validPaths[] = $norm;
                }
            }

            if (empty($validPaths)) {
                http_response_code(404);
                die('Tidak ada file valid yang dapat diunduh.');
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            while (ob_get_level()) {
                ob_end_clean();
            }

            $tempZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bulk_download_' . uniqid('', true) . '.zip';
            register_shutdown_function(static function () use ($tempZip): void { @unlink($tempZip); });
            $zip = new ZipArchive();
            if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                http_response_code(500);
                die('Gagal membuat arsip ZIP sementara.');
            }

            foreach ($validPaths as $filePath) {
                $base = basename($filePath);
                if (is_dir($filePath)) {
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($filePath, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($files as $file) {
                        $subRelative = $base . DIRECTORY_SEPARATOR . substr($file->getPathname(), strlen($filePath) + 1);
                        if ($file->isDir()) {
                            $zip->addEmptyDir(str_replace('\\', '/', $subRelative));
                        } elseif ($file->isFile()) {
                            $zip->addFile($file->getPathname(), str_replace('\\', '/', $subRelative));
                        }
                    }
                } else {
                    $zip->addFile($filePath, $base);
                }
            }

            $zip->close();

            if (!file_exists($tempZip) || filesize($tempZip) === 0) {
                http_response_code(500);
                die('Gagal memproses arsip ZIP.');
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="unduhan_terpilih_' . date('Ymd_His') . '.zip"');
            header('Content-Length: ' . filesize($tempZip));
            header('Cache-Control: no-cache, no-store, must-revalidate');

            readfile($tempZip);
            @unlink($tempZip);
            exit;

        case 'delete_bulk':
            $pathsJson = $_POST['paths'] ?? '';
            $paths = is_array($pathsJson) ? $pathsJson : @json_decode($pathsJson, true);
            if (!is_array($paths) || empty($paths)) {
                sendJson(['success' => false, 'message' => 'Daftar item tidak valid.'], 400);
            }

            $deletedCount = 0;
            $failedCount = 0;
            foreach ($paths as $p) {
                $targetPath = normalizePath($p);
                if (isPathExcluded($targetPath, $excludedDrives) || preg_match('/^[A-Za-z]:\\\\?$/', $targetPath)) {
                    $failedCount++;
                    continue;
                }
                if (file_exists($targetPath)) {
                    if (deleteRecursive($targetPath)) {
                        setUploadMetadata($targetPath, null);
                        $deletedCount++;
                    } else {
                        $failedCount++;
                    }
                }
            }

            sendJson([
                'success' => true,
                'deletedCount' => $deletedCount,
                'failedCount' => $failedCount,
                'message' => "{$deletedCount} item berhasil dihapus" . ($failedCount > 0 ? " ({$failedCount} gagal)" : ".")
            ]);
            break;

        case 'compress_bulk':
            $pathsJson = $_POST['paths'] ?? '';
            $customName = trim($_POST['zipName'] ?? '');
            $paths = is_array($pathsJson) ? $pathsJson : @json_decode($pathsJson, true);
            if (!is_array($paths) || empty($paths)) {
                sendJson(['success' => false, 'message' => 'Daftar item tidak valid.'], 400);
            }

            if (!class_exists('ZipArchive')) {
                sendJson(['success' => false, 'message' => 'PHP ZipArchive tidak aktif pada server.'], 500);
            }

            $validPaths = [];
            foreach ($paths as $p) {
                $norm = normalizePath($p);
                if (!isPathExcluded($norm, $excludedDrives) && file_exists($norm)) {
                    $validPaths[] = $norm;
                }
            }

            if (empty($validPaths)) {
                sendJson(['success' => false, 'message' => 'Tidak ada file valid untuk dikompres.'], 404);
            }

            $firstDir = is_dir($validPaths[0]) ? dirname(rtrim($validPaths[0], DIRECTORY_SEPARATOR)) : dirname($validPaths[0]);
            if (empty($firstDir) || preg_match('/^[A-Za-z]:$/', $firstDir)) {
                $firstDir .= DIRECTORY_SEPARATOR;
            }

            $sanitized = !empty($customName) ? preg_replace('/[\\/\\\\:\*\?"<>\|]/', '', $customName) : 'arsip_terpilih_' . date('Ymd_His');
            if (!str_ends_with(strtolower($sanitized), '.zip')) {
                $sanitized .= '.zip';
            }

            $zipFile = rtrim($firstDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sanitized;
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                sendJson(['success' => false, 'message' => 'Gagal membuat file ZIP di direktori tujuan.'], 500);
            }

            foreach ($validPaths as $filePath) {
                $base = basename($filePath);
                if (is_dir($filePath)) {
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($filePath, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($files as $file) {
                        $subRelative = $base . DIRECTORY_SEPARATOR . substr($file->getPathname(), strlen($filePath) + 1);
                        if ($file->isDir()) {
                            $zip->addEmptyDir(str_replace('\\', '/', $subRelative));
                        } elseif ($file->isFile()) {
                            $zip->addFile($file->getPathname(), str_replace('\\', '/', $subRelative));
                        }
                    }
                } else {
                    $zip->addFile($filePath, $base);
                }
            }

            $zip->close();
            sendJson(['success' => true, 'message' => "Berhasil mengompres {$sanitized}.", 'zipPath' => $zipFile]);
            break;

        case 'download':
            $targetPath = normalizePath($_GET['path'] ?? '');

            if (isPathExcluded($targetPath, $excludedDrives)) {
                http_response_code(403);
                die('Akses ke drive ini diblokir pada konfigurasi keamanan.');
            }

            if (empty($targetPath) || !file_exists($targetPath)) {
                http_response_code(404);
                die('File atau folder tidak ditemukan.');
            }

            // Lepaskan lock session agar tidak memblokir request lain
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            // Jika folder, kompres menjadi file ZIP secara on-the-fly
            if (is_dir($targetPath)) {
                $folderName = basename(rtrim($targetPath, DIRECTORY_SEPARATOR));
                if (empty($folderName) || $folderName === ':') {
                    $folderName = 'Drive_' . str_replace([':', '\\', '/'], '', $targetPath);
                }
                $zipFileName = $folderName . '_' . date('Ymd_His') . '.zip';
                $tempZipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('zip_', true) . '.zip';
                register_shutdown_function(static function () use ($tempZipPath): void { @unlink($tempZipPath); });

                if (!class_exists('ZipArchive')) {
                    http_response_code(500);
                    die('Ekstensi PHP ZipArchive tidak aktif.');
                }

                $zip = new ZipArchive();
                if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    http_response_code(500);
                    die('Gagal membuat arsip ZIP.');
                }

                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($targetPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($files as $file) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen(rtrim($targetPath, DIRECTORY_SEPARATOR)) + 1);
                    if ($file->isDir()) {
                        $zip->addEmptyDir($relativePath);
                    } elseif ($file->isFile()) {
                        $zip->addFile($filePath, $relativePath);
                    }
                }
                $zip->close();

                while (ob_get_level()) {
                    ob_end_clean();
                }

                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
                header('Content-Length: ' . filesize($tempZipPath));
                header('Cache-Control: no-cache, must-revalidate');
                header('Pragma: no-cache');
                header('Expires: 0');
                
                readfile($tempZipPath);
                @unlink($tempZipPath);
                exit;
            }

            streamMediaFile($targetPath, getMimeType($targetPath), true);

        case 'thumb':
            $targetPath = normalizePath($_GET['path'] ?? '');

            if (isPathExcluded($targetPath, $excludedDrives)) {
                http_response_code(403);
                die('Akses ke drive ini diblokir pada konfigurasi keamanan.');
            }

            if (empty($targetPath) || !file_exists($targetPath) || is_dir($targetPath)) {
                http_response_code(404);
                die('File tidak ditemukan.');
            }

            serveImageThumbnail($targetPath, 280, 280);
            exit;

        case 'video_status':
        case 'video_preview':
            $source = realpath(normalizePath($_GET['path'] ?? ''));
            if (!$source || !is_file($source)) sendJson(['success' => false, 'message' => 'Video tidak ditemukan.'], 404);
            if (isPathExcluded($source, $excludedDrives)) sendJson(['success' => false, 'message' => 'Drive ini tidak diizinkan.'], 403);
            if (!str_starts_with(getMimeType($source), 'video/')) sendJson(['success' => false, 'message' => 'File bukan video.'], 400);
            if ($action === 'video_status') sendJson(['success' => true] + videoPreviewStatus($source));
            $cachedVideo = videoCacheRoot() . '/' . videoCacheKey($source) . '.mp4';
            if (!is_file($cachedVideo)) {
                header('Retry-After: 3');
                sendJson(['success' => false, 'message' => 'Versi hemat belum siap.'], 503);
            }
            streamMediaFile($cachedVideo, 'video/mp4');

        case 'preview':
            $targetPath = normalizePath($_GET['path'] ?? '');

            if (isPathExcluded($targetPath, $excludedDrives)) {
                http_response_code(403);
                die('Akses ke drive ini diblokir pada konfigurasi keamanan.');
            }

            if (empty($targetPath) || !file_exists($targetPath) || is_dir($targetPath)) {
                http_response_code(404);
                die('File tidak ditemukan.');
            }

            $mimeType = getMimeType($targetPath);
            if (str_starts_with($mimeType, 'image/') && empty($_GET['original'])) {
                serveOptimizedImage($targetPath, 1600, 1200);
            }
            streamMediaFile($targetPath, $mimeType);

        case 'compress_zip':
            $targetPath = normalizePath($_POST['path'] ?? '');
            $customName = trim($_POST['zipName'] ?? '');

            if (isPathExcluded($targetPath, $excludedDrives)) {
                sendJson(['success' => false, 'message' => 'Akses ke drive ini diblokir pada konfigurasi keamanan.'], 403);
            }

            if (empty($targetPath) || !file_exists($targetPath)) {
                sendJson(['success' => false, 'message' => 'File atau folder yang akan dikompres tidak ditemukan.'], 404);
            }

            if (!class_exists('ZipArchive')) {
                sendJson(['success' => false, 'message' => 'Ekstensi PHP ZipArchive tidak aktif pada server ini.'], 500);
            }

            $parentDir = is_dir($targetPath) ? dirname(rtrim($targetPath, DIRECTORY_SEPARATOR)) : dirname($targetPath);
            if (empty($parentDir) || preg_match('/^[A-Za-z]:$/', $parentDir)) {
                $parentDir .= DIRECTORY_SEPARATOR;
            }

            $baseName = basename(rtrim($targetPath, DIRECTORY_SEPARATOR));
            if (!empty($customName)) {
                $sanitized = preg_replace('/[\\/\\\\:\*\?"<>\|]/', '', $customName);
                if (!str_ends_with(strtolower($sanitized), '.zip')) {
                    $sanitized .= '.zip';
                }
                $zipFileName = $sanitized;
            } else {
                $zipFileName = pathinfo($baseName, PATHINFO_FILENAME) . '.zip';
            }

            $zipPath = rtrim($parentDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $zipFileName;

            // Jika file zip sudah ada, tambahkan penomoran
            if (file_exists($zipPath)) {
                $rawName = pathinfo($zipFileName, PATHINFO_FILENAME);
                $zipPath = rtrim($parentDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rawName . '_' . date('Ymd_His') . '.zip';
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                sendJson(['success' => false, 'message' => 'Gagal membuat file arsip ZIP. Periksa izin tulis direktori.'], 500);
            }

            if (is_dir($targetPath)) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($targetPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                $rootPrefixLength = strlen(rtrim($targetPath, DIRECTORY_SEPARATOR)) + 1;
                $folderName = basename(rtrim($targetPath, DIRECTORY_SEPARATOR));
                $zip->addEmptyDir($folderName);

                foreach ($files as $file) {
                    $filePath = $file->getRealPath();
                    $relativePath = $folderName . '/' . substr($filePath, $rootPrefixLength);
                    $relativePath = str_replace('\\', '/', $relativePath);

                    if ($file->isDir()) {
                        $zip->addEmptyDir($relativePath);
                    } elseif ($file->isFile()) {
                        $zip->addFile($filePath, $relativePath);
                    }
                }
            } else {
                $zip->addFile($targetPath, basename($targetPath));
            }

            $zip->close();

            sendJson([
                'success' => true,
                'message' => 'Berhasil membuat arsip ZIP: ' . basename($zipPath),
                'zipPath' => $zipPath
            ]);
            break;

        case 'extract_archive':
            $targetPath = normalizePath($_POST['path'] ?? '');
            $targetSubfolder = trim($_POST['targetFolder'] ?? '');

            if (isPathExcluded($targetPath, $excludedDrives)) {
                sendJson(['success' => false, 'message' => 'Akses ke drive ini diblokir pada konfigurasi keamanan.'], 403);
            }

            if (empty($targetPath) || !file_exists($targetPath) || is_dir($targetPath)) {
                sendJson(['success' => false, 'message' => 'File arsip tidak ditemukan.'], 404);
            }

            $parentDir = dirname($targetPath);
            if (empty($parentDir) || preg_match('/^[A-Za-z]:$/', $parentDir)) {
                $parentDir .= DIRECTORY_SEPARATOR;
            }

            // Tentukan folder tujuan ekstraksi
            $destDirName = !empty($targetSubfolder) ? preg_replace('/[\\/\\\\:\*\?"<>\|]/', '', $targetSubfolder) : pathinfo($targetPath, PATHINFO_FILENAME);
            $extractDir = rtrim($parentDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $destDirName;

            if (!is_dir($extractDir)) {
                @mkdir($extractDir, 0777, true);
            }

            $ext = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));

            if ($ext === 'zip') {
                if (!class_exists('ZipArchive')) {
                    sendJson(['success' => false, 'message' => 'Ekstensi PHP ZipArchive tidak aktif.'], 500);
                }

                $zip = new ZipArchive();
                if ($zip->open($targetPath) === true) {
                    // Validasi Zip-Slip keamanan sebelum extract
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $filename = $zip->getNameIndex($i);
                        if (str_contains($filename, '../') || str_contains($filename, '..\\')) {
                            $zip->close();
                            sendJson(['success' => false, 'message' => 'Arsip tidak valid atau mengandung path berbahaya (Zip-Slip).'], 400);
                        }
                    }

                    $zip->extractTo($extractDir);
                    $zip->close();

                    sendJson([
                        'success' => true,
                        'message' => 'Arsip ZIP berhasil diekstrak ke folder: ' . basename($extractDir),
                        'extractDir' => $extractDir
                    ]);
                } else {
                    sendJson(['success' => false, 'message' => 'Gagal membuka atau mengekstrak file ZIP.'], 500);
                }
            } else {
                // Ekstraksi untuk format .rar, .tar, .7z, .gz menggunakan utilitas sistem (tar/bsdtar di Windows 10/11)
                $escapedTarget = escapeshellarg($targetPath);
                $escapedDest = escapeshellarg($extractDir);
                
                // Gunakan bsdtar bawaan Windows yang mendukung zip, rar, tar, gz, 7z
                $cmd = "tar -xf {$escapedTarget} -C {$escapedDest} 2>&1";
                $output = @shell_exec($cmd);

                // Cek apakah direktori tujuan berisi file hasil ekstrak
                $extractedFiles = @scandir($extractDir);
                $hasFiles = $extractedFiles && count($extractedFiles) > 2;

                if ($hasFiles) {
                    sendJson([
                        'success' => true,
                        'message' => 'Arsip berhasil diekstrak ke folder: ' . basename($extractDir),
                        'extractDir' => $extractDir
                    ]);
                } else {
                    sendJson([
                        'success' => false,
                        'message' => 'Gagal mengekstrak arsip ' . strtoupper($ext) . '. Pastikan file arsip tidak terproteksi password atau rusak.'
                    ], 500);
                }
            }
            break;

        case 'search':
            $basePath = normalizePath($_GET['path'] ?? '');
            $query = strtolower(trim($_GET['query'] ?? ''));

            if (isPathExcluded($basePath, $excludedDrives)) {
                sendJson(['success' => false, 'message' => 'Akses ke drive ini diblokir pada konfigurasi keamanan.'], 403);
            }

            if (empty($basePath) || !is_dir($basePath) || empty($query)) {
                sendJson(['success' => false, 'message' => 'Path dan query pencarian wajib diisi.'], 400);
            }

            $results = [];
            $maxResults = 50;

            try {
                $dirIterator = new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS);
                $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);
                $iterator->setMaxDepth(3);

                foreach ($iterator as $item) {
                    if (str_contains(strtolower($item->getFilename()), $query)) {
                        $isDir = $item->isDir();
                        $fullPath = $item->getRealPath();
                        $results[] = [
                            'name' => $item->getFilename(),
                            'path' => $fullPath,
                            'isDir' => $isDir,
                            'size' => $isDir ? 0 : (float)$item->getSize(),
                            'sizeFormatted' => $isDir ? '-' : formatBytes((float)$item->getSize()),
                            'extension' => $isDir ? '' : strtolower($item->getExtension()),
                            'mimeType' => $isDir ? 'directory' : getMimeType($fullPath),
                            'modifiedTime' => date('Y-m-d H:i:s', $item->getMTime()),
                        ];

                        if (count($results) >= $maxResults) break;
                    }
                }
            } catch (\Exception $e) {
                // Ignore permission error on protected folders
            }

            sendJson(['success' => true, 'results' => $results, 'count' => count($results)]);
            break;

        default:
            sendJson(['success' => false, 'message' => 'Aksi tidak dikenali.'], 400);
            break;
    }
} catch (\Throwable $e) {
    sendJson(['success' => false, 'message' => 'Terjadi kesalahan internal: ' . $e->getMessage()], 500);
}
