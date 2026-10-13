<?php
declare(strict_types=1);

function sendJson(array $data, int $statusCode = 200): never {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function getMimeType(string $path): string {
    $types = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml','bmp'=>'image/bmp','ico'=>'image/x-icon','mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','avi'=>'video/x-msvideo','mkv'=>'video/x-matroska','mp3'=>'audio/mpeg','pdf'=>'application/pdf','txt'=>'text/plain'];
    return $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? (function_exists('mime_content_type') ? (@mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream');
}
function serveImageThumbnail(string $filePath, int $maxWidth = 280, int $maxHeight = 280): void {
    serveOptimizedImage($filePath, $maxWidth, $maxHeight);
}
require_once __DIR__ . '/share-links.php';
require_once __DIR__ . '/upload-metadata.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/video.php';
ini_set('memory_limit', '192M');
ini_set('max_execution_time', '3600');
set_time_limit(3600);

if (session_status() === PHP_SESSION_NONE) session_start();
$config = require __DIR__ . '/config.php';
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
header('Cache-Control: private, no-store');
if (!empty($config['sso_enabled']) && empty($_SESSION['drive_user'])) sendJson(['success'=>false,'message'=>'Silakan login melalui SSO.'], 401);
$requestUser = $_SESSION['drive_user'] ?? [];
$activeShareToken = (string)($_SESSION['active_share_token'] ?? '');
$shareOnly = !empty($_SESSION['drive_share_only']);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function shareRequireSameOrigin(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($origin === '') sendJson(['success'=>false,'message'=>'Asal permintaan tidak dapat diverifikasi.'], 403);
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $hostParts = parse_url('http://' . $host);
    $hostOnly = strtolower((string)($hostParts['host'] ?? ''));
    $originScheme = strtolower((string)parse_url($origin, PHP_URL_SCHEME));
    $originPort = (int)(parse_url($origin, PHP_URL_PORT) ?? ($originScheme === 'https' ? 443 : 80));
    $forwardedScheme = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $scheme = $forwardedScheme !== '' ? $forwardedScheme : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $expectedPort = (int)($hostParts['port'] ?? ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? ($scheme === 'https' ? 443 : ($_SERVER['SERVER_PORT'] ?? 80))));
    if ($originHost !== $hostOnly || $originPort !== $expectedPort) sendJson(['success'=>false,'message'=>'Permintaan lintas situs ditolak.'], 403);
}
function shareExcludedPath(string $path, array $config): bool {
    $path = strtoupper(str_replace('/', '\\', $path));
    foreach (($config['excluded_drives'] ?? ['C:']) as $drive) {
        $drive = strtoupper(rtrim(trim((string)$drive), '\\/:')) . ':';
        if ($path === $drive || str_starts_with($path, $drive . '\\')) return true;
    }
    return false;
}
function shareRequireEditor(array $share): void {
    if (($share['permission'] ?? 'viewer') !== 'editor') sendJson(['success'=>false,'message'=>'Tautan ini hanya memiliki izin pelihat.'], 403);
}
function shareDeleteTree(string $path): bool {
    if (is_link($path) || !is_dir($path)) return @unlink($path);
    $items = @scandir($path);
    if ($items === false) return false;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (!shareDeleteTree($path . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return @rmdir($path);
}
function shareMoveItem(array $share, string $root): never {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') sendJson(['success'=>false,'message'=>'Gunakan POST.'], 405);
    shareRequireSameOrigin();
    shareRequireEditor($share);
    $sourceRelative = (string)($_POST['source'] ?? '');
    $destinationRelative = (string)($_POST['destination'] ?? '');
    $source = resolveSharePath($share, $sourceRelative);
    $destination = resolveSharePath($share, $destinationRelative);
    if (!$source || !$destination || !file_exists($source) || !is_dir($destination)) {
        sendJson(['success'=>false,'message'=>'Item sumber atau folder tujuan tidak ditemukan di folder berbagi.'], 404);
    }
    if (strtolower($source) === strtolower($root)) sendJson(['success'=>false,'message'=>'Folder utama tautan tidak dapat dipindahkan.'], 403);
    if (is_dir($source) && sharePathWithin($destination, $source)) {
        sendJson(['success'=>false,'message'=>'Folder tidak dapat dipindahkan ke dalam dirinya sendiri.'], 400);
    }
    $sourceParent = dirname($source);
    $destinationPath = $destination . DIRECTORY_SEPARATOR . basename($source);
    if (strtolower($sourceParent) === strtolower($destination)) {
        sendJson(['success'=>false,'message'=>'Item sudah berada di folder tujuan.'], 409);
    }

    // Coordinate moves with uploads and other moves touching either folder.
    $directories = array_values(array_unique([$sourceParent, $destination], SORT_STRING));
    usort($directories, static fn(string $a, string $b): int => strcasecmp($a, $b));
    $locks = [];
    try {
        foreach ($directories as $directory) {
            $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drive_dest_' . hash('sha256', strtolower($directory)) . '.lock';
            $lock = @fopen($lockPath, 'c');
            if (!$lock || !flock($lock, LOCK_EX)) {
                if ($lock) fclose($lock);
                sendJson(['success'=>false,'message'=>'Folder sedang diproses. Coba lagi sebentar.'], 503);
            }
            $locks[] = $lock;
        }
        if (file_exists($destinationPath)) sendJson(['success'=>false,'message'=>'Nama item sudah digunakan di folder tujuan.'], 409);
        if (!@rename($source, $destinationPath)) sendJson(['success'=>false,'message'=>'Item gagal dipindahkan. Pastikan kedua folder berada pada disk yang sama.'], 500);
        transferUploadMetadata($source, $destinationPath);
        sendJson(['success'=>true,'path'=>ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($destinationPath, strlen(rtrim($root, DIRECTORY_SEPARATOR)))), '/')]);
    } finally {
        foreach (array_reverse($locks) as $lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
function shareUploadChunk(array $share, string $root, array $user): never {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') sendJson(['success'=>false,'message'=>'Gunakan POST.'], 405);
    shareRequireSameOrigin();
    shareRequireEditor($share);
    requireWorkSlot('upload', 4);
    $relativeDir = (string)($_POST['path'] ?? '');
    $targetDir = resolveSharePath($share, $relativeDir);
    $fileName = trim((string)($_POST['fileName'] ?? ''));
    $fileName = basename(str_replace('\\', '/', $fileName));
    $fileName = preg_replace('/[\\:\*\?"<>\|]/', '', $fileName);
    $chunkIndex = filter_var($_POST['chunkIndex'] ?? null, FILTER_VALIDATE_INT);
    $totalChunks = filter_var($_POST['totalChunks'] ?? null, FILTER_VALIDATE_INT);
    $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['uploadId'] ?? ''));
    if (!$targetDir || !is_dir($targetDir) || !$fileName || $fileName === '.' || $fileName === '..' || str_starts_with(strtolower($fileName), '.drive-part-') || strlen($fileName) > 240 || strtolower($fileName) === UPLOAD_METADATA_FILE
        || $chunkIndex === false || $totalChunks === false || $totalChunks < 1 || $totalChunks > 100000 || $chunkIndex < 0 || $chunkIndex >= $totalChunks
        || strlen($uploadId) < 12 || strlen($uploadId) > 80) sendJson(['success'=>false,'message'=>'Data upload tidak valid.'], 400);
    if (!isset($_FILES['chunk']) || ($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)$_FILES['chunk']['size'] > 2 * 1024 * 1024) {
        sendJson(['success'=>false,'message'=>'Potongan upload maksimal 2 MB.'], 413);
    }
    if ((float)@disk_free_space(sys_get_temp_dir()) < 512 * 1024 * 1024 || (float)@disk_free_space($targetDir) < 512 * 1024 * 1024) {
        sendJson(['success'=>false,'message'=>'Ruang disk hampir penuh.'], 507);
    }
    $tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drive_uploads_' . hash('sha256', __DIR__);
    $tempDir = $tempRoot . DIRECTORY_SEPARATOR . hash('sha256', session_id() . ':' . $uploadId . ':' . ($share['id'] ?? ''));
    if (!is_dir($tempDir) && !@mkdir($tempDir, 0700, true) && !is_dir($tempDir)) sendJson(['success'=>false,'message'=>'Folder upload sementara tidak tersedia.'], 500);
    $lock = @fopen($tempDir . DIRECTORY_SEPARATOR . 'upload.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); header('Retry-After: 1'); sendJson(['success'=>false,'retryable'=>true,'message'=>'Upload sedang diproses.'], 503); }
    try {
        $manifestPath = $tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        $signature = hash('sha256', ($share['id'] ?? '') . ':' . $targetDir . ':' . $fileName . ':' . $totalChunks);
        $manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : ['signature'=>$signature,'received'=>0];
        if (($manifest['signature'] ?? '') !== $signature) sendJson(['success'=>false,'message'=>'Tujuan upload berubah.'], 409);
        if (!empty($manifest['completed'])) sendJson(['success'=>true,'completed'=>true,'message'=>'Upload sudah selesai.']);
        $partPath = $tempDir . DIRECTORY_SEPARATOR . sprintf('chunk_%05d', $chunkIndex);
        $alreadyReceived = is_file($partPath);
        if (!$alreadyReceived && !move_uploaded_file($_FILES['chunk']['tmp_name'], $partPath)) sendJson(['success'=>false,'message'=>'Gagal menyimpan potongan upload.'], 500);
        if (!$alreadyReceived) $manifest['received'] = (int)($manifest['received'] ?? 0) + 1;
        if (file_put_contents($manifestPath, json_encode($manifest)) === false) sendJson(['success'=>false,'message'=>'Gagal mencatat upload.'], 500);
        if ((int)$manifest['received'] !== $totalChunks) sendJson(['success'=>true,'completed'=>false]);
        $destinationLock = @fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drive_dest_' . hash('sha256', strtolower($targetDir)) . '.lock', 'c');
        if (!$destinationLock) sendJson(['success'=>false,'message'=>'Folder tujuan belum dapat dikunci.'], 500);
        if (!flock($destinationLock, LOCK_EX | LOCK_NB)) {
            fclose($destinationLock);
            header('Retry-After: 1');
            sendJson(['success'=>false,'retryable'=>true,'message'=>'Folder tujuan sedang menyelesaikan upload lain.'], 503);
        }
        try {
            $final = $targetDir . DIRECTORY_SEPARATOR . $fileName;
            if (file_exists($final)) {
                $info = pathinfo($fileName);
                $suffix = '.' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
                $final = $targetDir . DIRECTORY_SEPARATOR . ($info['filename'] ?? $fileName) . $suffix . (empty($info['extension']) ? '' : '.' . $info['extension']);
            }
            $staging = $targetDir . DIRECTORY_SEPARATOR . '.drive-part-' . bin2hex(random_bytes(8));
            $output = @fopen($staging, 'xb');
            if (!$output) throw new RuntimeException('Gagal menulis file tujuan.');
            for ($index = 0; $index < $totalChunks; $index++) {
                $input = @fopen($tempDir . DIRECTORY_SEPARATOR . sprintf('chunk_%05d', $index), 'rb');
                if (!$input) throw new RuntimeException('Potongan upload belum lengkap.');
                while (!feof($input)) {
                    $buffer = fread($input, 1048576);
                    if ($buffer === false || ($buffer !== '' && fwrite($output, $buffer) !== strlen($buffer))) { fclose($input); throw new RuntimeException('Disk gagal menulis file.'); }
                }
                fclose($input);
            }
            fclose($output);
            if (!@rename($staging, $final)) { @unlink($staging); throw new RuntimeException('Gagal menyimpan file akhir.'); }
            setUploadMetadata($final, ['id'=>$user['id'] ?? null,'name'=>$user['name'] ?? 'Tamu','uploadedAt'=>date(DATE_ATOM)]);
            $manifest['completed'] = true;
            file_put_contents($manifestPath, json_encode($manifest));
        } catch (Throwable $error) {
            if (isset($output) && is_resource($output)) fclose($output);
            if (isset($staging)) @unlink($staging);
            throw $error;
        } finally {
            flock($destinationLock, LOCK_UN);
            fclose($destinationLock);
        }
        foreach (glob($tempDir . DIRECTORY_SEPARATOR . 'chunk_*') ?: [] as $part) @unlink($part);
        if (str_starts_with(getMimeType($final), 'video/')) { try { videoPreviewStatus($final); } catch (Throwable) {} }
        sendJson(['success'=>true,'completed'=>true,'name'=>basename($final)]);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

try {
    if (in_array($action, ['share_create','share_list','share_revoke'], true)) {
        if ($shareOnly) sendJson(['success'=>false,'message'=>'Tamu tidak dapat mengatur tautan berbagi.'], 403);
        $user = $requestUser;
        if ($action === 'share_list') {
            $folder = realpath((string)($_GET['path'] ?? ''));
            if (!$folder || !is_dir($folder) || shareExcludedPath($folder, $config)) sendJson(['success'=>false,'message'=>'Folder tidak ditemukan.'], 404);
            $links = listShareLinksForFolder($folder, $user);
            foreach ($links as &$link) $link['url'] = 'share.php?token=' . rawurlencode((string)$link['token']);
            unset($link);
            sendJson(['success'=>true,'links'=>$links]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') sendJson(['success'=>false,'message'=>'Gunakan POST.'], 405);
        shareRequireSameOrigin();
        if ($action === 'share_create') {
            $folder = realpath((string)($_POST['path'] ?? ''));
            $excluded = false;
            foreach (($config['excluded_drives'] ?? ['C:']) as $drive) {
                $drive = strtoupper(rtrim(trim((string)$drive), '\\/:')) . ':';
                $normalized = strtoupper(str_replace('/', '\\', (string)$folder));
                if ($normalized === $drive || str_starts_with($normalized, $drive . '\\')) { $excluded = true; break; }
            }
            if (!$folder || !is_dir($folder) || $excluded) sendJson(['success'=>false,'message'=>'Folder tidak tersedia untuk dibagikan.'], 400);
            $record = createShareLink($folder, (string)($_POST['permission'] ?? 'viewer'), $user);
            sendJson(['success'=>true,'link'=>['id'=>$record['id'],'permission'=>$record['permission'],'active'=>true,'url'=>'share.php?token=' . rawurlencode($record['token']),'createdAt'=>$record['createdAt']]]);
        }
        if (!revokeShareLink((string)($_POST['id'] ?? ''), $user)) sendJson(['success'=>false,'message'=>'Tautan tidak ditemukan atau bukan milik akun ini.'], 404);
        sendJson(['success'=>true]);
    }

    $token = $activeShareToken;
    $share = $token !== '' ? findShareLink($token) : null;
    if (!$share || empty($share['active'])) sendJson(['success'=>false,'message'=>'Tautan berbagi sudah dicabut atau tidak valid.'], 403);
    $root = realpath((string)$share['path']);
    if (!$root || !is_dir($root)) sendJson(['success'=>false,'message'=>'Folder berbagi sudah tidak tersedia.'], 404);
    if ($action === 'list') {
        $relative = (string)($_GET['path'] ?? '');
        $directory = resolveSharePath($share, $relative);
        if (!$directory || !is_dir($directory)) sendJson(['success'=>false,'message'=>'Folder tidak ditemukan.'], 404);
        $items = [];
        $uploadMetadata = readUploadMetadata($directory);
        foreach (@scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..' || strtolower($name) === UPLOAD_METADATA_FILE || str_starts_with($name, '.drive-part-')) continue;
            $candidate = $directory . DIRECTORY_SEPARATOR . $name;
            $real = realpath($candidate);
            if (!$real || !sharePathWithin($real, $root)) continue;
            $isDir = is_dir($real);
            $items[] = ['name'=>$name,'path'=>ltrim(str_replace(DIRECTORY_SEPARATOR,'/',substr($real,strlen(rtrim($root,DIRECTORY_SEPARATOR)))), '/'),'isDir'=>$isDir,'size'=>$isDir?0:(int)filesize($real),'modified'=>date('Y-m-d H:i',filemtime($real) ?: time()),'extension'=>$isDir?'':strtolower(pathinfo($name,PATHINFO_EXTENSION)),'mimeType'=>$isDir?'directory':getMimeType($real),'uploadedBy'=>$uploadMetadata[strtolower($name)]['name'] ?? null];
        }
        usort($items, static fn(array $a,array $b): int => $a['isDir'] !== $b['isDir'] ? ($a['isDir'] ? -1 : 1) : strnatcasecmp($a['name'],$b['name']));
        sendJson(['success'=>true,'items'=>$items,'rootName'=>basename($root),'permission'=>$share['permission'],'currentPath'=>str_replace('\\','/',trim($relative,'/'))]);
    }
    if ($action === 'upload_chunk') shareUploadChunk($share, $root, $requestUser);
    if ($action === 'move') shareMoveItem($share, $root);
    $relative = (string)($_GET['path'] ?? '');
    if ($action === 'rename' || $action === 'delete' || $action === 'create_folder') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') sendJson(['success'=>false,'message'=>'Gunakan POST.'], 405);
        shareRequireSameOrigin();
        shareRequireEditor($share);
        $relative = (string)($_POST['path'] ?? '');
        $target = resolveSharePath($share, $relative);
        if (!$target) sendJson(['success'=>false,'message'=>'Item tidak ditemukan di folder berbagi.'], 404);
        if ($action !== 'create_folder' && strtolower($target) === strtolower($root)) sendJson(['success'=>false,'message'=>'Folder utama tautan tidak dapat diubah.'], 403);
        if ($action === 'create_folder') {
            if (!is_dir($target)) sendJson(['success'=>false,'message'=>'Folder tujuan tidak tersedia.'], 404);
            $name = trim((string)($_POST['name'] ?? ''));
            $name = preg_replace('/[\/\\:\*\?"<>\|]/', '', $name);
            if ($name === '' || $name === '.' || $name === '..' || str_starts_with(strtolower($name), '.drive-part-') || strtolower($name) === UPLOAD_METADATA_FILE) sendJson(['success'=>false,'message'=>'Nama folder tidak valid.'], 400);
            if (!@mkdir($target . DIRECTORY_SEPARATOR . $name)) sendJson(['success'=>false,'message'=>'Folder gagal dibuat atau nama sudah ada.'], 409);
            sendJson(['success'=>true]);
        }
        if ($action === 'delete') {
            if (!shareDeleteTree($target)) sendJson(['success'=>false,'message'=>'Gagal menghapus item.'], 500);
            setUploadMetadata($target, null);
            sendJson(['success'=>true]);
        }
        $name = trim((string)($_POST['newName'] ?? ''));
        $name = preg_replace('/[\/\\:\*\?"<>\|]/', '', $name);
        if ($name === '') sendJson(['success'=>false,'message'=>'Nama baru tidak valid.'], 400);
        $destination = dirname($target) . DIRECTORY_SEPARATOR . $name;
        if (file_exists($destination) || !@rename($target, $destination)) sendJson(['success'=>false,'message'=>'Nama sudah digunakan atau item gagal diubah.'], 409);
        transferUploadMetadata($target, $destination);
        sendJson(['success'=>true]);
    }
    $target = resolveSharePath($share, $relative);
    if (!$target || is_dir($target)) sendJson(['success'=>false,'message'=>'File tidak ditemukan.'], 404);
    $mime = getMimeType($target);
    if ($action === 'thumb') {
        if (!str_starts_with($mime,'image/')) sendJson(['success'=>false,'message'=>'File ini bukan gambar.'], 400);
        serveImageThumbnail($target, 280, 280);
    }
    if ($action === 'preview') {
        if (str_starts_with($mime,'image/')) serveOptimizedImage($target,1600,1200);
        streamMediaFile($target,$mime);
    }
    if ($action === 'video_status') {
        if (!str_starts_with($mime,'video/')) sendJson(['success'=>false,'message'=>'File bukan video.'], 400);
        sendJson(['success'=>true] + videoPreviewStatus($target));
    }
    if ($action === 'video_preview') {
        if (!str_starts_with($mime,'video/')) sendJson(['success'=>false,'message'=>'File bukan video.'], 400);
        $cached = videoCacheRoot() . '/' . videoCacheKey($target) . '.mp4';
        if (!is_file($cached)) { header('Retry-After: 3'); sendJson(['success'=>false,'message'=>'Versi hemat belum siap.'], 503); }
        streamMediaFile($cached,'video/mp4');
    }
    if ($action === 'download') streamMediaFile($target,$mime,true);
    sendJson(['success'=>false,'message'=>'Aksi tidak dikenal.'],404);
} catch (Throwable $error) {
    error_log('Share API error: ' . $error->getMessage());
    sendJson(['success'=>false,'message'=>$error->getMessage()],500);
}
