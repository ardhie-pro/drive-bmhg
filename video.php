<?php
declare(strict_types=1);

function videoCacheRoot(): string {
    $root = __DIR__ . '/.local-server/video';
    if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Penyimpanan preview video belum tersedia.');
    }
    return $root;
}

function videoCacheKey(string $source): string {
    $source = realpath($source) ?: $source;
    return hash('sha256', $source . ':' . filemtime($source) . ':' . filesize($source) . ':480p-v1');
}

function writeVideoState(string $path, array $state): void {
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR)) === false) {
        @unlink($temporary);
        throw new RuntimeException('Gagal mencatat status preview video.');
    }
    // Windows readers may briefly hold the destination open during status polling.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        if (@rename($temporary, $path)) return;
        usleep(10000);
    }
    @unlink($temporary);
    throw new RuntimeException('Gagal mencatat status preview video.');
}

function videoPreviewStatus(string $source): array {
    $root = videoCacheRoot();
    $key = videoCacheKey($source);
    if (is_file("$root/$key.mp4")) return ['state' => 'ready', 'quality' => '480p'];
    $heartbeat = "$root/heartbeat.json";
    $worker = is_file($heartbeat) ? json_decode(file_get_contents($heartbeat), true) : null;
    if (!$worker || ($worker['time'] ?? 0) < time() - 30) {
        return ['state' => 'unavailable', 'message' => 'Versi hemat belum tersedia. Gunakan video asli.'];
    }
    $statePath = "$root/$key.state.json";
    if (is_file($statePath)) {
        $state = json_decode(file_get_contents($statePath), true);
        if (in_array($state['state'] ?? '', ['processing', 'failed'], true)) return $state;
    }
    if (filesize($source) > 4 * 1024 * 1024 * 1024) {
        return ['state' => 'unavailable', 'message' => 'Video ini perlu dibuka dari file asli.'];
    }
    $queued = 0;
    foreach (new DirectoryIterator($root) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.job.json') && ++$queued >= 32) {
            return ['state' => 'busy', 'message' => 'Antrean video sedang penuh. Coba lagi sebentar.'];
        }
    }
    $jobLock = @fopen("$root/$key.job.lock", 'c');
    if ($jobLock && flock($jobLock, LOCK_EX | LOCK_NB)) {
        try {
            if (!is_file("$root/$key.job.json")) writeVideoState("$root/$key.job.json", ['key' => $key, 'source' => $source, 'created' => time()]);
        } finally {
            flock($jobLock, LOCK_UN);
            fclose($jobLock);
        }
    } elseif ($jobLock) {
        fclose($jobLock);
    }
    return ['state' => 'queued', 'message' => 'Sedang menyiapkan versi hemat.'];
}
