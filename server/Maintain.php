<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Bound startup maintenance time; active upload manifests are refreshed per chunk.
$removed = 0;
$uploadRoot = sys_get_temp_dir() . '/drive_uploads_' . hash('sha256', dirname(__DIR__));
if (is_dir($uploadRoot)) {
    $checked = 0;
    foreach (new DirectoryIterator($uploadRoot) as $entry) {
        if ($entry->isDot() || !$entry->isDir()) continue;
        if (++$checked > 5000) break;
        $directory = $entry->getPathname();
        $manifest = $directory . '/manifest.json';
        $mtime = is_file($manifest) ? filemtime($manifest) : $entry->getMTime();
        if ($mtime > time() - 86400) continue;
        $lock = @fopen($directory . '/upload.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); continue; }
        foreach (new DirectoryIterator($directory) as $file) {
            if ($file->isFile() && $file->getFilename() !== 'upload.lock') @unlink($file->getPathname());
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        @unlink($directory . '/upload.lock');
        if (@rmdir($directory)) $removed++;
    }
}
$cacheRoot = sys_get_temp_dir() . '/drive_thumb_cache';
if (is_dir($cacheRoot)) {
    $checked = 0;
    foreach (new DirectoryIterator($cacheRoot) as $file) {
        if ($file->isDot()) continue;
        if (++$checked > 5000) break;
        if ($file->isFile() && preg_match('/^[a-f0-9]{64}\.(webp|jpg)$/', $file->getFilename()) && $file->getMTime() < time() - 7 * 86400) {
            if (@unlink($file->getPathname())) $removed++;
        }
    }
}
echo "Maintenance: $removed temporary entries removed.\n";
