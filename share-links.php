<?php
declare(strict_types=1);

function shareStorePath(): string {
    $directory = __DIR__ . '/.local-server';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Penyimpanan tautan berbagi belum tersedia.');
    }
    return $directory . '/share-links.json';
}

function withShareStore(callable $callback, bool $write = false): mixed {
    $handle = @fopen(shareStorePath(), 'c+b');
    if (!$handle || !flock($handle, $write ? LOCK_EX : LOCK_SH)) {
        if ($handle) fclose($handle);
        throw new RuntimeException('Penyimpanan tautan berbagi sedang tidak tersedia.');
    }
    try {
        rewind($handle);
        $records = json_decode(stream_get_contents($handle) ?: '[]', true);
        if (!is_array($records)) $records = [];
        $result = $callback($records);
        if ($write) {
            $json = json_encode($records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json)) {
                throw new RuntimeException('Gagal menyimpan tautan berbagi.');
            }
            fflush($handle);
        }
        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function shareTokenKey(string $token): ?string {
    return preg_match('/^[a-f0-9]{64}$/i', $token) ? hash('sha256', strtolower($token)) : null;
}

function findShareLink(string $token): ?array {
    $key = shareTokenKey($token);
    if ($key === null) return null;
    return withShareStore(static fn(array &$records): ?array => $records[$key] ?? null);
}

function createShareLink(string $folder, string $permission, array $user): array {
    if (!in_array($permission, ['viewer', 'editor'], true)) throw new InvalidArgumentException('Izin berbagi tidak valid.');
    $root = realpath($folder);
    if (!$root || !is_dir($root)) throw new InvalidArgumentException('Pilih folder yang masih tersedia.');
    $token = bin2hex(random_bytes(32));
    $key = hash('sha256', $token);
    $record = [
        'id' => $key,
        'token' => $token,
        'path' => $root,
        'permission' => $permission,
        'active' => true,
        'createdBy' => (string)($user['id'] ?? ''),
        'createdByEmail' => strtolower((string)($user['email'] ?? '')),
        'createdByName' => (string)($user['name'] ?? 'Pengguna'),
        'createdAt' => date(DATE_ATOM),
    ];
    withShareStore(static function (array &$records) use ($key, $record): void { $records[$key] = $record; }, true);
    return $record;
}

function listShareLinksForFolder(string $folder, array $user): array {
    $root = realpath($folder);
    if (!$root || !is_dir($root)) return [];
    $userId = (string)($user['id'] ?? '');
    $email = strtolower((string)($user['email'] ?? ''));
    return withShareStore(static function (array &$records) use ($root, $userId, $email): array {
        $result = [];
        foreach ($records as $record) {
            $owned = ($userId !== '' && ($record['createdBy'] ?? '') === $userId)
                || ($email !== '' && ($record['createdByEmail'] ?? '') === $email)
                || ($userId === '' && $email === '');
            if ($owned && strtolower((string)($record['path'] ?? '')) === strtolower($root)) $result[] = $record;
        }
        usort($result, static fn(array $a, array $b): int => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
        return $result;
    });
}

function revokeShareLink(string $id, array $user): bool {
    if (!preg_match('/^[a-f0-9]{64}$/i', $id)) return false;
    return withShareStore(static function (array &$records) use ($id, $user): bool {
        $record = $records[strtolower($id)] ?? null;
        if (!$record) return false;
        $userId = (string)($user['id'] ?? '');
        $email = strtolower((string)($user['email'] ?? ''));
        $owned = ($userId !== '' && ($record['createdBy'] ?? '') === $userId)
            || ($email !== '' && ($record['createdByEmail'] ?? '') === $email)
            || ($userId === '' && $email === '');
        if (!$owned) return false;
        $records[strtolower($id)]['active'] = false;
        return true;
    }, true);
}

function sharePathWithin(string $path, string $root): bool {
    $normalize = static fn(string $value): string => strtolower(rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $value), DIRECTORY_SEPARATOR));
    $candidate = $normalize($path);
    $base = $normalize($root);
    return $candidate === $base || str_starts_with($candidate, $base . DIRECTORY_SEPARATOR);
}

function resolveSharePath(array $share, string $relativePath = ''): ?string {
    $root = realpath((string)($share['path'] ?? ''));
    if (!$root || !is_dir($root)) return null;
    $relativePath = str_replace('\\', '/', trim($relativePath));
    if (str_starts_with($relativePath, '/') || preg_match('/^[A-Za-z]:/', $relativePath)) return null;
    $segments = array_filter(explode('/', $relativePath), static fn(string $part): bool => $part !== '' && $part !== '.');
    foreach ($segments as $segment) if ($segment === '..' || str_contains($segment, "\0")) return null;
    $candidate = $root;
    foreach ($segments as $segment) $candidate .= DIRECTORY_SEPARATOR . $segment;
    $resolved = realpath($candidate);
    return $resolved && sharePathWithin($resolved, $root) ? $resolved : null;
}
