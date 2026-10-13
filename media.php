<?php
declare(strict_types=1);

/** Fixed slots cap expensive work without keeping a queue of blocked PHP workers. */
function acquireWorkSlot(string $group, int $limit): bool {
    $directory = sys_get_temp_dir() . '/drive_work_' . hash('sha256', __DIR__);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return false;
    for ($slot = 0; $slot < $limit; $slot++) {
        $handle = @fopen($directory . '/' . $group . '_' . $slot . '.lock', 'c');
        if (!$handle) continue;
        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            register_shutdown_function(static function () use ($handle): void {
                flock($handle, LOCK_UN);
                fclose($handle);
            });
            return true;
        }
        fclose($handle);
    }
    return false;
}

function requireWorkSlot(string $group, int $limit): void {
    if (!acquireWorkSlot($group, $limit)) {
        header('Retry-After: 2');
        sendJson(['success' => false, 'message' => 'Server sedang sibuk. Coba lagi beberapa saat.', 'retryable' => true], 503);
    }
}

/** Returns an inclusive single byte range; null means invalid/unsatisfiable. */
function parseByteRange(string $header, int $size): ?array {
    if ($size <= 0 || !preg_match('/^bytes=(\d*)-(\d*)$/D', trim($header), $match)
        || ($match[1] === '' && $match[2] === '')) return null;
    if ($match[1] === '') {
        $suffix = (int)$match[2];
        return $suffix > 0 ? [max(0, $size - $suffix), $size - 1] : null;
    }
    $start = (int)$match[1];
    $end = $match[2] === '' ? $size - 1 : min((int)$match[2], $size - 1);
    return $start < $size && $end >= $start ? [$start, $end] : null;
}

function streamMediaFile(string $path, string $mime, bool $attachment = false): never {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') requireWorkSlot('transfer', 8);
    $handle = @fopen($path, 'rb');
    if (!$handle) sendJson(['success' => false, 'message' => 'File tidak dapat dibaca.'], 404);
    $stat = fstat($handle);
    $size = (int)$stat['size'];
    $etag = '"' . hash('sha256', $path . ':' . $size . ':' . $stat['mtime']) . '"';
    while (ob_get_level()) ob_end_clean();
    ini_set('zlib.output_compression', '0');
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('ETag: ' . $etag);
    header('Content-Disposition: ' . ($attachment ? 'attachment' : 'inline') . '; filename="' . rawurlencode(basename($path)) . '"');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag && !isset($_SERVER['HTTP_RANGE'])) {
        fclose($handle);
        http_response_code(304);
        exit;
    }
    $start = 0;
    $end = $size - 1;
    if (isset($_SERVER['HTTP_RANGE']) && (!isset($_SERVER['HTTP_IF_RANGE']) || $_SERVER['HTTP_IF_RANGE'] === $etag)) {
        $range = parseByteRange($_SERVER['HTTP_RANGE'], $size);
        if ($range === null) {
            fclose($handle);
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        [$start, $end] = $range;
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    $remaining = max(0, $end - $start + 1);
    header('Content-Length: ' . $remaining);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        fclose($handle);
        exit;
    }
    if ($start > 0) fseek($handle, $start);
    while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
        $buffer = fread($handle, min(262144, $remaining));
        if ($buffer === false || $buffer === '') break;
        echo $buffer;
        $remaining -= strlen($buffer);
        flush();
    }
    fclose($handle);
    exit;
}

function imagePlaceholder(string $message = 'Preview tidak tersedia'): never {
    header('Content-Type: image/svg+xml');
    header('Cache-Control: private, no-store');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="280" height="180" viewBox="0 0 280 180"><rect width="280" height="180" fill="#e2e8f0"/><text x="140" y="90" text-anchor="middle" fill="#475569" font-size="14">' . htmlspecialchars($message, ENT_XML1) . '</text></svg>';
    exit;
}

function serveOptimizedImage(string $path, int $width, int $height): never {
    $size = @getimagesize($path);
    // Bound decode memory before GD allocates the original full-resolution bitmap.
    if (!$size || !extension_loaded('gd') || $size[0] <= 0 || $size[1] <= 0
        || $size[0] * $size[1] > 16000000 || filesize($path) > 40 * 1024 * 1024) imagePlaceholder();
    $key = hash('sha256', $path . ':' . filemtime($path) . ':' . filesize($path) . ":$width:$height:v2");
    $directory = sys_get_temp_dir() . '/drive_thumb_cache';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) imagePlaceholder();
    $webp = function_exists('imagewebp');
    $cache = $directory . '/' . $key . ($webp ? '.webp' : '.jpg');
    if (!is_file($cache)) {
        if ((float)disk_free_space(sys_get_temp_dir()) < 512 * 1024 * 1024) imagePlaceholder('Disk hampir penuh');
        requireWorkSlot('image', 2);
        $lock = @fopen($cache . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            header('Retry-After: 1');
            sendJson(['success' => false, 'retryable' => true, 'message' => 'Preview sedang disiapkan.'], 503);
        }
        try {
            if (!is_file($cache)) {
                $loader = match ($size[2]) {
                    IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng',
                    IMAGETYPE_WEBP => 'imagecreatefromwebp', IMAGETYPE_GIF => 'imagecreatefromgif',
                    IMAGETYPE_BMP => 'imagecreatefrombmp', default => null,
                };
                if (!$loader || !function_exists($loader) || !($source = @$loader($path))) imagePlaceholder();
                $ratio = min(1, $width / $size[0], $height / $size[1]);
                $targetWidth = max(1, (int)round($size[0] * $ratio));
                $targetHeight = max(1, (int)round($size[1] * $ratio));
                $target = imagecreatetruecolor($targetWidth, $targetHeight);
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, imagecolorallocatealpha($target, 255, 255, 255, 127));
                imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $size[0], $size[1]);
                imagedestroy($source);
                $temporary = $cache . '.' . bin2hex(random_bytes(6)) . '.tmp';
                $saved = $webp ? imagewebp($target, $temporary, 78) : imagejpeg($target, $temporary, 80);
                imagedestroy($target);
                if (!$saved || !@rename($temporary, $cache)) {
                    @unlink($temporary);
                    imagePlaceholder();
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    header('Content-Type: ' . ($webp ? 'image/webp' : 'image/jpeg'));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('ETag: "' . $key . '"');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '"' . $key . '"') {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($cache));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($cache);
    exit;
}
