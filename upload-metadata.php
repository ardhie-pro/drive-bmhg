<?php
declare(strict_types=1);

const UPLOAD_METADATA_FILE = '.drive-uploaders.json';

function readUploadMetadata(string $directory): array {
    $handle = @fopen($directory . DIRECTORY_SEPARATOR . UPLOAD_METADATA_FILE, 'rb');
    if (!$handle) return [];
    flock($handle, LOCK_SH);
    $data = json_decode(stream_get_contents($handle), true);
    flock($handle, LOCK_UN);
    fclose($handle);
    return is_array($data) ? $data : [];
}

function setUploadMetadata(string $path, ?array $record): void {
    $handle = @fopen(dirname($path) . DIRECTORY_SEPARATOR . UPLOAD_METADATA_FILE, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        throw new RuntimeException('Gagal menyimpan informasi pengunggah.');
    }
    try {
        $data = json_decode(stream_get_contents($handle), true) ?: [];
        $key = strtolower(basename($path));
        if ($record === null) unset($data[$key]);
        else $data[$key] = $record;
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json)) {
            throw new RuntimeException('Gagal menyimpan informasi pengunggah.');
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function transferUploadMetadata(string $source, string $destination): void {
    $record = readUploadMetadata(dirname($source))[strtolower(basename($source))] ?? null;
    setUploadMetadata($destination, $record);
    setUploadMetadata($source, null);
}
