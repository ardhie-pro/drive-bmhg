<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/video.php';

$ffmpeg = $argv[1] ?? dirname(__DIR__) . '/.local-server/tools/ffmpeg.exe';
$once = in_array('--once', $argv, true);
if (!is_file($ffmpeg) || !function_exists('proc_open')) {
    fwrite(STDERR, "FFmpeg/proc_open unavailable. Run server/Install-FFmpeg.ps1 first.\n");
    exit(1);
}
$root = videoCacheRoot();
$workerLock = fopen("$root/worker.lock", 'c');
if (!flock($workerLock, LOCK_EX | LOCK_NB)) { echo "Video worker already running.\n"; exit; }
$process = null;
register_shutdown_function(static function () use (&$process, $root, $workerLock): void {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    @unlink("$root/heartbeat.json");
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
});
echo "Video worker ready: 480p, one conversion, two codec threads.\n";

do {
    writeVideoState("$root/heartbeat.json", ['time' => time(), 'pid' => getmypid()]);
    foreach (new DirectoryIterator($root) as $file) {
        if (!$file->isFile() || !preg_match('/^([a-f0-9]{64})\.job\.json$/', $file->getFilename(), $match)) continue;
        $key = $match[1];
        $jobPath = $file->getPathname();
        $statePath = "$root/$key.state.json";
        $temporary = "$root/$key.part.mp4";
        $output = "$root/$key.mp4";
        try {
            $job = json_decode(file_get_contents($jobPath), true, 32, JSON_THROW_ON_ERROR);
            $source = $job['source'] ?? '';
            if (!is_file($source) || videoCacheKey($source) !== $key) throw new RuntimeException('File video berubah atau sudah tidak tersedia.');
            if (is_file($output)) { @unlink($jobPath); continue; }
            if ((float)disk_free_space($root) < 1024 * 1024 * 1024) throw new RuntimeException('Ruang disk belum cukup untuk menyiapkan versi hemat.');
            writeVideoState($statePath, ['state' => 'processing', 'message' => 'Sedang menyiapkan versi hemat.']);
            $command = [$ffmpeg, '-hide_banner', '-nostdin', '-loglevel', 'error', '-y',
                '-protocol_whitelist', 'file,pipe', '-threads', '2', '-max_alloc', '134217728', '-i', $source,
                '-map', '0:v:0', '-map', '0:a:0?', '-sn', '-dn', '-filter_threads', '1',
                '-vf', "scale=w='min(854,iw)':h='min(480,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
                '-r', '24', '-c:v', 'libx264', '-threads', '2', '-preset', 'veryfast', '-crf', '28',
                '-maxrate', '900k', '-bufsize', '1800k', '-pix_fmt', 'yuv420p', '-g', '48',
                '-c:a', 'aac', '-b:a', '64k', '-ac', '2', '-movflags', '+faststart', $temporary];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', "$root/worker-output.log", 'a'], 2 => ['file', "$root/worker-error.log", 'a']], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) throw new RuntimeException('Konversi video belum dapat dimulai.');
            fclose($pipes[0]);
            $started = time();
            do {
                usleep(500000);
                writeVideoState("$root/heartbeat.json", ['time' => time(), 'pid' => getmypid()]);
                $status = proc_get_status($process);
                clearstatcache(true, $temporary);
                if (time() - $started > 1800 || (float)disk_free_space($root) < 512 * 1024 * 1024
                    || (is_file($temporary) && filesize($temporary) > 1024 * 1024 * 1024)) {
                    proc_terminate($process);
                    throw new RuntimeException('Video terlalu berat untuk diproses sekarang. Gunakan file asli.');
                }
            } while ($status['running']);
            $exitCode = $status['exitcode'];
            proc_close($process);
            $process = null;
            if ($exitCode !== 0 || !is_file($temporary) || filesize($temporary) === 0) throw new RuntimeException('Versi hemat gagal dibuat. Gunakan file asli.');
            clearstatcache(true, $source);
            if (videoCacheKey($source) !== $key) throw new RuntimeException('Video berubah selama konversi.');
            if (!@rename($temporary, $output)) throw new RuntimeException('Gagal menyimpan versi hemat.');
            writeVideoState($statePath, ['state' => 'ready', 'quality' => '480p']);
            echo "$key: ready\n";
        } catch (Throwable $error) {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); $process = null; }
            @unlink($temporary);
            writeVideoState($statePath, ['state' => 'failed', 'message' => $error->getMessage()]);
            fwrite(STDERR, "$key: " . $error->getMessage() . "\n");
        }
        @unlink($jobPath);
    }
    if (!$once) sleep(2);
} while (!$once);
