<?php
// File/folder metadata in MySQL. The bytes live in Telegram; this only records where.

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', config('db.host', 'localhost'), config('db.name')),
        (string) config('db.user'),
        (string) config('db.pass'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    // Dates are stored and returned in UTC; the browser shows them in local time
    $pdo->exec("SET time_zone = '+00:00'");
    ensure_schema($pdo);
    return $pdo;
}

// Creates the tables on first run, and adds new ones on later versions
function ensure_schema(PDO $pdo): void
{
    $marker = storage_dir('state') . '/schema-v3';
    if (is_file($marker)) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS folders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            parent_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (parent_id),
            FOREIGN KEY (parent_id) REFERENCES folders(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            folder_id INT NULL,
            size BIGINT NOT NULL,
            mime VARCHAR(100) NULL,
            source VARCHAR(10) NOT NULL DEFAULT 'admin',
            in_gallery TINYINT(1) NOT NULL DEFAULT 0,
            share_token VARCHAR(32) NULL UNIQUE,
            uploader_ip VARCHAR(45) NULL,
            width INT NULL,
            height INT NULL,
            duration INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (folder_id),
            INDEX gallery (in_gallery, id),
            FOREIGN KEY (folder_id) REFERENCES folders(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Each file is stored as one or more Telegram messages (bots can only download 20 MB at a time)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chunks (
            file_id INT NOT NULL,
            idx INT NOT NULL,
            tg_file_id VARCHAR(255) NOT NULL,
            tg_message_id BIGINT NOT NULL,
            size BIGINT NOT NULL,
            PRIMARY KEY (file_id, idx),
            FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Thumbnails are Telegram messages too: nothing image-shaped is kept on the server's disk
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS thumbs (
            file_id INT NOT NULL,
            kind VARCHAR(10) NOT NULL,
            tg_file_id VARCHAR(255) NOT NULL DEFAULT '',
            tg_message_id BIGINT NOT NULL,
            size BIGINT NOT NULL,
            mime VARCHAR(100) NOT NULL,
            width INT NULL,
            height INT NULL,
            PRIMARY KEY (file_id, kind),
            FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Databases created before videos existed have no duration column
    $hasDuration = $pdo->query("SHOW COLUMNS FROM files LIKE 'duration'")->fetch();
    if (!$hasDuration) {
        try {
            $pdo->exec('ALTER TABLE files ADD COLUMN duration INT NULL AFTER height');
        } catch (PDOException $e) {
            // Another request may have added it between the check and the ALTER
            if (!str_contains($e->getMessage(), 'Duplicate column')) throw $e;
        }
    }
    // Rate limiting: public uploads and admin password attempts
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(20) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (kind, ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Older versions kept image copies on the server's disk; remove them so nothing image-shaped
    // stays there. Telegram is the only storage now.
    $cache = APP_DIR . '/storage/cache';
    if (is_dir($cache)) {
        foreach (glob($cache . '/*') ?: [] as $path) @unlink($path);
        @rmdir($cache);
    }
    touch($marker);
}

function q_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function q_exec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

// Consistent types in JSON (the admin page compares ids strictly)
function file_row(?array $r): ?array
{
    if (!$r) return null;
    foreach (['id', 'size', 'in_gallery'] as $k) $r[$k] = (int) $r[$k];
    foreach (['folder_id', 'width', 'height', 'duration'] as $k) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    return $r;
}

function folder_row(?array $r): ?array
{
    if (!$r) return null;
    $r['id'] = (int) $r['id'];
    $r['parent_id'] = $r['parent_id'] === null ? null : (int) $r['parent_id'];
    if (isset($r['file_count'])) $r['file_count'] = (int) $r['file_count'];
    return $r;
}

function thumb_row(?array $r): ?array
{
    if (!$r) return null;
    foreach (['file_id', 'tg_message_id', 'size'] as $k) $r[$k] = (int) $r[$k];
    foreach (['width', 'height'] as $k) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    return $r;
}

function get_thumb(array $file, string $kind): ?array
{
    return thumb_row(q_one('SELECT * FROM thumbs WHERE file_id = ? AND kind = ?', [$file['id'], $kind]));
}

function thumb_rows(array $file): array
{
    return array_map('thumb_row', q_all('SELECT * FROM thumbs WHERE file_id = ?', [$file['id']]));
}

function get_file(int $id): ?array
{
    return file_row(q_one('SELECT * FROM files WHERE id = ?', [$id]));
}

function get_file_by_token(string $token): ?array
{
    return file_row(q_one('SELECT * FROM files WHERE share_token = ?', [$token]));
}

function get_folder(int $id): ?array
{
    return folder_row(q_one('SELECT * FROM folders WHERE id = ?', [$id]));
}

// Breadcrumb from root down to the given folder
function folder_path(?int $folderId): array
{
    $trail = [];
    $current = $folderId === null ? null : get_folder($folderId);
    while ($current) {
        array_unshift($trail, ['id' => $current['id'], 'name' => $current['name']]);
        $current = $current['parent_id'] === null ? null : get_folder($current['parent_id']);
    }
    return $trail;
}

// Counts recent events for rate limiting and records this one; returns false when over the limit
function allow_event(string $kind, string $ip, int $limit, int $windowSeconds): bool
{
    if (random_int(1, 100) === 1) q_exec('DELETE FROM events WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    $count = (int) q_one(
        'SELECT COUNT(*) AS n FROM events WHERE kind = ? AND ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL ? SECOND',
        [$kind, $ip, $windowSeconds]
    )['n'];
    if ($count >= $limit) return false;
    q_exec('INSERT INTO events (kind, ip) VALUES (?, ?)', [$kind, $ip]);
    return true;
}

// Seconds until the oldest counted event leaves the window
function event_retry_after(string $kind, string $ip, int $windowSeconds): int
{
    $row = q_one(
        'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), MIN(created_at) + INTERVAL ? SECOND) AS s
         FROM events WHERE kind = ? AND ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL ? SECOND',
        [$windowSeconds, $kind, $ip, $windowSeconds]
    );
    return max(60, (int) ($row['s'] ?? $windowSeconds));
}

function clear_events(string $kind, string $ip): void
{
    q_exec('DELETE FROM events WHERE kind = ? AND ip = ?', [$kind, $ip]);
}
