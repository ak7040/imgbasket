<?php
// Storing files in Telegram, and sending files to browsers. Nothing is kept on this server's disk:
// originals and previews both live in Telegram and are streamed from there.

function storage_dir(string $sub): string
{
    $dir = APP_DIR . '/storage/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
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
    // The previews go to Telegram as well, so the server keeps no image files at all
    prepare_thumbnails($file, $tmpPath);
    return $file;
}

function delete_file(array $file): void
{
    $chunks = file_chunks($file);
    $thumbs = thumb_rows($file);
    q_exec('DELETE FROM files WHERE id = ?', [$file['id']]);
    foreach ($chunks as $chunk) tg_delete_chunk($chunk);
    foreach ($thumbs as $thumb) tg_delete_chunk($thumb);
}

// A file whose messages were deleted in the Telegram app: remove the stale database row and any
// messages that are still there (typically the previews)
function discard_file(array $file): void
{
    try {
        delete_file($file);
    } catch (Throwable $e) {
        error_log("Could not remove gone file {$file['id']}: " . $e->getMessage());
    }
}

// Writes the whole file to $path (used to build a preview for files uploaded before previews
// were stored in Telegram)
function download_to(array $file, string $path): void
{
    tg_download_file($file, $path);
}

function end_output_buffers(): void
{
    while (ob_get_level() > 0) ob_end_clean();
}

// Sends a file to the browser straight from Telegram. Saved Messages files support byte ranges, so
// videos can be seeked; bot storage is streamed piece by piece without ranges.
function send_file(array $file, bool $inline, string $cacheControl): never
{
    @set_time_limit(0);
    end_output_buffers();

    $size = (int) $file['size'];
    $chunks = file_chunks($file);
    $single = count($chunks) === 1 && !chunk_is_bot($chunks[0]);

    $start = 0;
    $end = $size - 1;
    $partial = false;
    if ($single && preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
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
        $partial = true;
    }

    $name = $file['name'];
    $fallback = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $name);
    header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"$fallback\"; filename*=UTF-8''" . rawurlencode($name));
    // Uploaded HTML/SVG must not run scripts on this site
    header('Content-Security-Policy: sandbox');
    header('X-Content-Type-Options: nosniff');
    header("Cache-Control: $cacheControl");
    header('Accept-Ranges: ' . ($single ? 'bytes' : 'none'));
    if ($partial) {
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
        header('Content-Length: ' . ($end - $start + 1));
    } else {
        header("Content-Length: $size");
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $size === 0) exit;

    if ($single) {
        tg_stream_range((int) $chunks[0]['tg_message_id'], $start, $end === $size - 1 ? -1 : $end + 1);
    } else {
        tg_stream_chunks($chunks);
    }
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
