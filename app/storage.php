<?php
// Storing files in Telegram, the local disk cache, and sending files to browsers

function storage_dir(string $sub): string
{
    $dir = APP_DIR . '/storage/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

function cache_max_file(): int
{
    return (int) config('cache_max_file', 25 * 1024 * 1024);
}

function cache_path(array $file): string
{
    return storage_dir('cache') . '/' . $file['id'];
}

function cached_path(array $file): ?string
{
    $path = cache_path($file);
    return is_file($path) && filesize($path) === (int) $file['size'] ? $path : null;
}

// Removes the cached copy and the thumbnails (<id>.<kind>.<ext>)
function uncache(int $id): void
{
    $dir = storage_dir('cache');
    @unlink("$dir/$id");
    foreach (glob("$dir/$id.*") ?: [] as $path) @unlink($path);
}

// Path of a shared file on the public site, with an extension so forums accept it as an image
function raw_path(array $file): string
{
    $ext = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
    return '/i/' . $file['share_token'] . (preg_match('/^[a-z0-9]{1,8}$/', $ext) ? ".$ext" : '');
}

function with_links(array $file): array
{
    $file = file_row($file);
    $file['share_url'] = $file['share_token'] ? public_url() . '/s/' . $file['share_token'] : null;
    $file['direct_url'] = $file['share_token'] ? public_url() . raw_path($file) : null;
    return $file;
}

function is_image(array $file): bool
{
    return str_starts_with((string) $file['mime'], 'image/');
}

// Uploads a file to Telegram (in pieces if needed) and records it. $fields: name, mime, folder_id,
// source, in_gallery, share_token, uploader_ip, width, height
function store_file(string $tmpPath, array $fields): array
{
    $size = filesize($tmpPath);
    $partSize = tg_part_size();
    $total = max(1, (int) ceil($size / $partSize));
    $sent = [];
    try {
        for ($i = 0; $i < $total; $i++) {
            $offset = $i * $partSize;
            $length = (int) min($partSize, $size - $offset);
            $partName = $total === 1 ? $fields['name'] : sprintf('%s.part%03d', $fields['name'], $i + 1);
            $caption = $total === 1 ? $fields['name'] : sprintf('%s [%d/%d]', $fields['name'], $i + 1, $total);
            $part = tg_send_part($tmpPath, $offset, $length, $partName, $caption, (string) $fields['mime']);
            $sent[] = $part + ['size' => $length];
        }
    } catch (Throwable $e) {
        // Don't leave orphaned pieces in Telegram
        foreach ($sent as $part) tg_delete_chunk(['tg_file_id' => $part['file_id'], 'tg_message_id' => $part['message_id']]);
        throw $e;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q_exec(
            'INSERT INTO files (name, folder_id, size, mime, source, in_gallery, share_token, uploader_ip, width, height)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['name'], $fields['folder_id'] ?? null, $size, $fields['mime'], $fields['source'],
                !empty($fields['in_gallery']) ? 1 : 0, $fields['share_token'] ?? null, $fields['uploader_ip'] ?? null,
                $fields['width'] ?? null, $fields['height'] ?? null,
            ]
        );
        $id = (int) $pdo->lastInsertId();
        foreach ($sent as $idx => $part) {
            q_exec('INSERT INTO chunks (file_id, idx, tg_file_id, tg_message_id, size) VALUES (?, ?, ?, ?, ?)',
                [$id, $idx, $part['file_id'], $part['message_id'], $part['size']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        foreach ($sent as $part) tg_delete_chunk(['tg_file_id' => $part['file_id'], 'tg_message_id' => $part['message_id']]);
        throw $e;
    }

    $file = get_file($id);
    if ($size <= cache_max_file()) @copy($tmpPath, cache_path($file));
    prepare_thumbnails($file, $tmpPath);
    return $file;
}

function delete_file(array $file): void
{
    $chunks = file_chunks($file);
    q_exec('DELETE FROM files WHERE id = ?', [$file['id']]);
    foreach ($chunks as $chunk) tg_delete_chunk($chunk);
    uncache($file['id']);
}

// Writes the whole file to $path (used for thumbnails of files that aren't cached)
function download_to(array $file, string $path): void
{
    tg_download_file($file, $path);
}

// Downloads a small file from Telegram into the cache; returns its cached path
function fill_cache(array $file): ?string
{
    $tmp = cache_path($file) . '.' . bin2hex(random_bytes(6)) . '.tmp';
    try {
        tg_download_file($file, $tmp);
        if (filesize($tmp) !== (int) $file['size']) return null;
        rename($tmp, cache_path($file));
        return cache_path($file);
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
}

function end_output_buffers(): void
{
    while (ob_get_level() > 0) ob_end_clean();
}

// Sends a file to the browser. Files up to cache_max_file are served from the local cache (filled
// from Telegram on first view), with range support so videos can be seeked; bigger ones stream
// straight from Telegram.
function send_file(array $file, bool $inline, string $cacheControl): never
{
    @set_time_limit(0);
    end_output_buffers();

    $size = (int) $file['size'];
    $headers = function () use ($file, $inline, $cacheControl): void {
        $name = $file['name'];
        $fallback = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $name);
        header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"$fallback\"; filename*=UTF-8''" . rawurlencode($name));
        // Uploaded HTML/SVG must not run scripts on this site
        header('Content-Security-Policy: sandbox');
        header('X-Content-Type-Options: nosniff');
        header("Cache-Control: $cacheControl");
    };

    $cached = cached_path($file) ?? ($size <= cache_max_file() ? fill_cache($file) : null);
    if (!$cached) {
        if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
            $headers();
            header("Content-Length: $size");
            exit;
        }
        tg_stream_file($file, function () use ($headers, $size): void {
            $headers();
            header("Content-Length: $size");
            header('Accept-Ranges: none');
        });
    }

    $headers();
    header('Accept-Ranges: bytes');
    $start = 0;
    $end = $size - 1;
    if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') {
            $start = max(0, $size - (int) $m[2]);
        } else {
            $start = (int) $m[1];
            if ($m[2] !== '') $end = min($end, (int) $m[2]);
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
    $fh = fopen($cached, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh) && !connection_aborted()) {
        $buf = fread($fh, (int) min(1 << 16, $left));
        echo $buf;
        $left -= strlen($buf);
        flush();
    }
    fclose($fh);
    exit;
}

// Normalises $_FILES[$key] to a list of uploads, whether one or many were sent
function uploaded_files(string $key): array
{
    $f = $_FILES[$key] ?? null;
    if (!$f) return [];
    if (!is_array($f['name'])) return [$f];
    $list = [];
    foreach (array_keys($f['name']) as $i) {
        $list[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $list;
}

// Turns PHP's upload errors (and an over-limit request body) into a clear message, or null if fine
function upload_problem(?array $upload): ?array
{
    $limit = format_size(php_upload_limit());
    if (!$upload) {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > ini_bytes('post_max_size')) return [413, "File is larger than this server's upload limit ($limit)"];
        return [400, 'No file was sent'];
    }
    return match ((int) $upload['error']) {
        UPLOAD_ERR_OK => is_uploaded_file($upload['tmp_name']) ? null : [400, 'Upload failed'],
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => [413, "File is larger than this server's upload limit ($limit)"],
        UPLOAD_ERR_PARTIAL => [400, 'The upload was interrupted. Try again.'],
        UPLOAD_ERR_NO_FILE => [400, 'No file was sent'],
        default => [500, 'The server could not save the upload (error ' . (int) $upload['error'] . ')'],
    };
}
