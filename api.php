<?php
/**
 * Local Drive Manager - Backend API
 * Handles drive detection (USB Flashdisk, Harddisk, SSD), file navigation,
 * chunked upload, zip downloads, file operations & previews.
 */

declare(strict_types=1);

// Set execution limits for large file streaming / zip creation
ini_set('memory_limit', '1024M');
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

$action = $_GET['action'] ?? $_POST['action'] ?? '';

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
 * Mendapatkan daftar seluruh drive aktif di Windows
 */
/**
 * Mendapatkan daftar seluruh drive aktif di Windows (kecuali drive yang dikecualikan)
 */
function getSystemDrives(array $excludedDrives = []): array {
    $drives = [];
    
    // Gunakan PowerShell CIM / WMI untuk data akurat tentang Flashdisk (Removable), HDD/SSD, dan label
    $psCommand = 'powershell -NoProfile -Command "Get-CimInstance Win32_LogicalDisk | Select-Object DeviceID, VolumeName, DriveType, Size, FreeSpace, FileSystem | ConvertTo-Json -Compress"';
    $output = @shell_exec($psCommand);

    if ($output) {
        $json = @json_decode(trim($output), true);
        if ($json !== null) {
            $list = isset($json['DeviceID']) ? [$json] : (is_array($json) ? $json : []);
            
            foreach ($list as $item) {
                $deviceId = $item['DeviceID'] ?? '';
                if (empty($deviceId)) continue;

                $driveLetter = strtoupper(rtrim($deviceId, '\\'));
                
                // Cek pengecualian drive (misal C:)
                if (in_array($driveLetter, $excludedDrives, true) || in_array($driveLetter . ':', $excludedDrives, true)) {
                    continue;
                }

                $driveType = (int)($item['DriveType'] ?? 3);
                $totalSize = (float)($item['Size'] ?? 0);
                $freeSize = (float)($item['FreeSpace'] ?? 0);
                $usedSize = max(0, $totalSize - $freeSize);
                $usedPercentage = $totalSize > 0 ? round(($usedSize / $totalSize) * 100, 1) : 0;
                $volumeName = trim($item['VolumeName'] ?? '');
                $fileSystem = trim($item['FileSystem'] ?? '');

                // Drive Type Mapping
                // 2: Removable (Flashdisk / USB Drive / Memory Card)
                // 3: Fixed Disk (Harddisk / SSD)
                // 4: Network Drive
                // 5: CD-ROM / DVD
                $typeLabel = 'Local Disk';
                $isRemovable = false;
                $icon = 'hard-drive';

                if ($driveType === 2) {
                    $typeLabel = 'USB Flashdisk';
                    $isRemovable = true;
                    $icon = 'usb';
                } elseif ($driveType === 3) {
                    $typeLabel = ($driveLetter === 'C:') ? 'System Disk' : 'Local Disk';
                    $icon = 'hard-drive';
                } elseif ($driveType === 4) {
                    $typeLabel = 'Network Drive';
                    $icon = 'network';
                } elseif ($driveType === 5) {
                    $typeLabel = 'CD/DVD Drive';
                    $icon = 'disc';
                }

                $drives[] = [
                    'id' => $driveLetter,
                    'path' => $driveLetter . DIRECTORY_SEPARATOR,
                    'label' => $volumeName ?: ($isRemovable ? 'USB Flashdisk' : 'Local Disk (' . $driveLetter . ')'),
                    'type' => $typeLabel,
                    'driveType' => $driveType,
                    'isRemovable' => $isRemovable,
                    'fileSystem' => $fileSystem,
                    'icon' => $icon,
                    'totalSize' => $totalSize,
                    'freeSize' => $freeSize,
                    'usedSize' => $usedSize,
                    'usedPercentage' => $usedPercentage,
                    'totalFormatted' => formatBytes($totalSize),
                    'freeFormatted' => formatBytes($freeSize),
                    'usedFormatted' => formatBytes($usedSize),
                    'isAccessible' => is_dir($driveLetter . DIRECTORY_SEPARATOR)
                ];
            }
        }
    }

    // Fallback jika PowerShell dinonaktifkan / gagal
    if (empty($drives)) {
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
                $drives[] = [
                    'id' => $letter . ':',
                    'path' => $path,
                    'label' => ($letter === 'C') ? 'System Drive' : 'Disk ' . $letter,
                    'type' => ($letter === 'C') ? 'System Disk' : 'Drive',
                    'driveType' => 3,
                    'isRemovable' => false,
                    'fileSystem' => '',
                    'icon' => 'hard-drive',
                    'totalSize' => $total,
                    'freeSize' => $free,
                    'usedSize' => $used,
                    'usedPercentage' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
                    'totalFormatted' => formatBytes($total),
                    'freeFormatted' => formatBytes($free),
                    'usedFormatted' => formatBytes($used),
                    'isAccessible' => true
                ];
            }
        }
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
                $reqPath = !empty($availableDrives) ? $availableDrives[0]['path'] : 'D:\\';
            }

            $path = normalizePath($reqPath);

            if (isPathExcluded($path, $excludedDrives)) {
                sendJson(['success' => false, 'message' => 'Akses ke drive ini dinonaktifkan dalam konfigurasi keamanan (config.php).'], 403);
            }

            if (!is_dir($path)) {
                sendJson(['success' => false, 'message' => 'Direktori tidak ditemukan atau tidak dapat diakses.'], 404);
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

            foreach ($handle as $item) {
                if ($item === '.' || $item === '..') continue;

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
                sendJson(['success' => true, 'message' => 'Berhasil dihapus.']);
            } else {
                sendJson(['success' => false, 'message' => 'Gagal menghapus file/folder. Pastikan tidak sedang dibuka oleh aplikasi lain.'], 500);
            }
            break;

        case 'upload_chunk':
            $targetDir = normalizePath($_POST['targetDir'] ?? '');
            $fileName = trim($_POST['fileName'] ?? '');
            $chunkIndex = (int)($_POST['chunkIndex'] ?? 0);
            $totalChunks = (int)($_POST['totalChunks'] ?? 1);
            $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['uploadId'] ?? uniqid('upl_', true));

            if (isPathExcluded($targetDir, $excludedDrives)) {
                sendJson(['success' => false, 'message' => 'Akses ke drive ini diblokir pada konfigurasi keamanan.'], 403);
            }

            if (empty($targetDir) || !is_dir($targetDir) || empty($fileName)) {
                sendJson(['success' => false, 'message' => 'Target direktori tidak valid.'], 400);
            }

            if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
                sendJson(['success' => false, 'message' => 'Gagal menerima potongan file.'], 400);
            }

            $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'local_drive_uploads' . DIRECTORY_SEPARATOR . $uploadId;
            if (!is_dir($tempDir)) {
                @mkdir($tempDir, 0777, true);
            }

            $chunkFile = $tempDir . DIRECTORY_SEPARATOR . sprintf('chunk_%05d', $chunkIndex);
            if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile)) {
                sendJson(['success' => false, 'message' => 'Gagal menyimpan potongan file.'], 500);
            }

            // Cek apakah seluruh chunks sudah terkirim
            $isComplete = true;
            for ($i = 0; $i < $totalChunks; $i++) {
                if (!file_exists($tempDir . DIRECTORY_SEPARATOR . sprintf('chunk_%05d', $i))) {
                    $isComplete = false;
                    break;
                }
            }

            if ($isComplete) {
                $cleanFileName = preg_replace('/[\\/\\\\:\*\?"<>\|]/', '', $fileName);
                $finalDestination = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $cleanFileName;

                if (file_exists($finalDestination)) {
                    $info = pathinfo($cleanFileName);
                    $namePart = $info['filename'];
                    $extPart = isset($info['extension']) ? '.' . $info['extension'] : '';
                    $finalDestination = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $namePart . '_' . date('Ymd_His') . $extPart;
                }

                $outHandle = @fopen($finalDestination, 'wb');
                if (!$outHandle) {
                    sendJson(['success' => false, 'message' => 'Gagal menulis file tujuan akhir. Periksa izin folder.'], 500);
                }

                for ($i = 0; $i < $totalChunks; $i++) {
                    $part = $tempDir . DIRECTORY_SEPARATOR . sprintf('chunk_%05d', $i);
                    $inHandle = fopen($part, 'rb');
                    if ($inHandle) {
                        while (!feof($inHandle)) {
                            $buffer = fread($inHandle, 1048576);
                            fwrite($outHandle, $buffer);
                        }
                        fclose($inHandle);
                    }
                    @unlink($part);
                }
                fclose($outHandle);
                @rmdir($tempDir);

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

            // Jika folder, kompres menjadi file ZIP secara on-the-fly
            if (is_dir($targetPath)) {
                $folderName = basename(rtrim($targetPath, DIRECTORY_SEPARATOR));
                if (empty($folderName) || $folderName === ':') {
                    $folderName = 'Drive_' . str_replace([':', '\\', '/'], '', $targetPath);
                }
                $zipFileName = $folderName . '_' . date('Ymd_His') . '.zip';
                $tempZipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('zip_', true) . '.zip';

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

            // Jika file biasa
            $fileName = basename($targetPath);
            $fileSize = (float)filesize($targetPath);
            $mimeType = getMimeType($targetPath);

            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
            header('Content-Length: ' . $fileSize);
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Expires: 0');

            // Streaming file dalam chunks 2MB
            $fp = @fopen($targetPath, 'rb');
            if ($fp) {
                while (!feof($fp)) {
                    echo fread($fp, 2097152);
                    flush();
                }
                fclose($fp);
            }
            exit;

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
            $fileSize = (float)filesize($targetPath);

            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . $fileSize);
            header('Content-Disposition: inline; filename="' . rawurlencode(basename($targetPath)) . '"');
            header('Accept-Ranges: bytes');

            // Support Range Requests untuk Video & Audio streaming
            if (isset($_SERVER['HTTP_RANGE'])) {
                list($param, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
                if (strtolower(trim($param)) === 'bytes') {
                    list($from, $to) = explode('-', $range, 2);
                    $from = (int)$from;
                    $to = (int)($to ?: $fileSize - 1);
                    if ($to >= $fileSize) $to = (int)($fileSize - 1);
                    $length = $to - $from + 1;

                    http_response_code(206);
                    header("Content-Range: bytes $from-$to/$fileSize");
                    header("Content-Length: $length");

                    $fp = fopen($targetPath, 'rb');
                    fseek($fp, $from);
                    $remaining = $length;
                    while ($remaining > 0 && !feof($fp)) {
                        $chunk = min($remaining, 1048576);
                        echo fread($fp, $chunk);
                        flush();
                        $remaining -= $chunk;
                    }
                    fclose($fp);
                    exit;
                }
            }

            readfile($targetPath);
            exit;

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
