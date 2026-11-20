<?php
// Image checks (by content, not by name) and thumbnails made with GD

const IMAGE_TYPES = [
    IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
    IMAGETYPE_PNG => ['image/png', 'png'],
    IMAGETYPE_GIF => ['image/gif', 'gif'],
    IMAGETYPE_WEBP => ['image/webp', 'webp'],
    IMAGETYPE_BMP => ['image/bmp', 'bmp'],
    IMAGETYPE_AVIF => ['image/avif', 'avif'],
];

// Returns ['mime', 'ext', 'width', 'height'] for supported images, or null. SVG is never accepted.
function inspect_image(string $path): ?array
{
    $info = @getimagesize($path);
    if (!$info || !isset(IMAGE_TYPES[$info[2]])) return null;
    [$mime, $ext] = IMAGE_TYPES[$info[2]];
    return ['mime' => $mime, 'ext' => $ext, 'width' => $info[0] ?: null, 'height' => $info[1] ?: null];
}

// og: small JPEG for link previews (WhatsApp and others skip large images); grid: gallery tiles
function thumb_spec(string $kind): array
{
    $webp = function_exists('imagewebp');
    return match ($kind) {
        'og' => ['max' => 1200, 'ext' => 'jpg', 'mime' => 'image/jpeg'],
        'grid' => $webp
            ? ['max' => 640, 'ext' => 'webp', 'mime' => 'image/webp']
            : ['max' => 640, 'ext' => 'jpg', 'mime' => 'image/jpeg'],
    };
}

// Returns the stored preview, making it and sending it to Telegram on first use; null if it can't
// be made. $source is the just-uploaded temp file; without it the original is fetched from Telegram
// (only for files uploaded before previews were stored there). Videos get a poster frame from FFmpeg.
function ensure_thumbnail(array $file, string $kind, ?string $source = null): ?array
{
    $thumb = get_thumb($file, $kind);
    if ($thumb) return $thumb;

    $video = is_video($file);
    if (!$video && !is_image($file)) return null;
    // Both routes end in GD for the final resize, and videos need FFmpeg for the frame
    if (!function_exists('imagecreatetruecolor')) return null;
    if ($video && !ffmpeg_bin()) return null;

    $tempSource = null;
    $tempThumb = null;
    try {
        if ($source === null) {
            // Big originals aren't worth downloading just for a preview. A video's poster is made
            // right after upload instead, so on-demand video previews only cover small files.
            $limit = $video ? 50 * 1024 * 1024 : 200 * 1024 * 1024;
            if ((int) $file['size'] > $limit || !storage_ready()) return null;
            @set_time_limit(0);
            $tempSource = $source = tempnam(storage_dir('tmp'), 'orig');
            download_to($file, $source);
        }
        $tempThumb = tempnam(storage_dir('tmp'), 'thumb');
        $made = $video ? make_video_thumbnail($source, $tempThumb, $kind) : make_thumbnail($source, $tempThumb, $kind);
        if (!$made) return null;
        clearstatcache(true, $tempThumb);
        $size = (int) filesize($tempThumb);
        $spec = thumb_spec($kind);
        $sent = tg_send_part(
            $tempThumb, 0, $size,
            "preview-{$file['id']}-$kind.{$spec['ext']}", "Preview of {$file['name']}", $spec['mime']
        );
        $info = @getimagesize($tempThumb);
        $inserted = q_exec(
            'INSERT IGNORE INTO thumbs (file_id, kind, tg_file_id, tg_message_id, size, mime, width, height)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$file['id'], $kind, (string) ($sent['file_id'] ?? ''), (int) $sent['message_id'],
             $size, $spec['mime'], $info[0] ?? null, $info[1] ?? null]
        );
        if ($inserted === 0) {
            // Another request stored this preview first; drop the duplicate from Telegram
            tg_delete_chunk(['tg_file_id' => (string) ($sent['file_id'] ?? ''), 'tg_message_id' => (int) $sent['message_id']]);
        }
        return get_thumb($file, $kind);
    } catch (TelegramGone $e) {
        // The original itself is gone; the caller removes the stale record
        throw $e;
    } catch (Throwable $e) {
        error_log("No $kind thumbnail for file {$file['id']}: " . $e->getMessage());
        return null;
    } finally {
        if ($tempSource) @unlink($tempSource);
        if ($tempThumb) @unlink($tempThumb);
    }
}

function make_thumbnail(string $source, string $target, string $kind): ?string
{
    $info = @getimagesize($source);
    // GD holds the whole picture in memory; skip huge ones (callers fall back to the original)
    if (!$info || $info[0] * $info[1] > 50_000_000) return null;
    @ini_set('memory_limit', '512M');

    $img = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
        IMAGETYPE_PNG => @imagecreatefrompng($source),
        IMAGETYPE_GIF => @imagecreatefromgif($source),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        IMAGETYPE_BMP => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($source) : false,
        IMAGETYPE_AVIF => function_exists('imagecreatefromavif') ? @imagecreatefromavif($source) : false,
        default => false,
    };
    if (!$img) return null;

    // Phone photos are often stored sideways with an EXIF rotation tag
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $orientation = (int) (@exif_read_data($source)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle) {
            $rotated = imagerotate($img, $angle, 0);
            if ($rotated) $img = $rotated;
        }
    }

    $spec = thumb_spec($kind);
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, $spec['max'] / max($w, $h));
    $tw = max(1, (int) round($w * $scale));
    $th = max(1, (int) round($h * $scale));

    $out = imagecreatetruecolor($tw, $th);
    if ($spec['ext'] === 'jpg') {
        // JPEG has no transparency: put transparent images on white
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    } else {
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    }
    imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $w, $h);

    $tmp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $ok = $spec['ext'] === 'jpg' ? imagejpeg($out, $tmp, 80) : imagewebp($out, $tmp, 78);
    if (!$ok) {
        @unlink($tmp);
        return null;
    }
    rename($tmp, $target);
    return $target;
}

// Builds both previews right after upload, from the uploaded temp file, and stores them in Telegram
function prepare_thumbnails(array $file, string $source): void
{
    if (!is_media($file)) return;
    foreach (['og', 'grid'] as $kind) ensure_thumbnail($file, $kind, $source);
}

// Sends a stored preview to the browser, or redirects to $fallback (the original) when none can be
// made. Throws TelegramGone when the original was deleted in the Telegram app.
function send_thumbnail(array $file, string $kind, ?string $fallback = null, int $attempt = 0): never
{
    @set_time_limit(0);
    $thumb = ensure_thumbnail($file, $kind);
    if (!$thumb) {
        // A video/file preview that couldn't be made must not redirect to the original: an <img>
        // would get media bytes it can't decode (and would download the whole file doing so).
        if (!is_image($file)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo "No preview\n";
            exit;
        }
        header('Location: ' . ($fallback ?? raw_path($file)), true, 302);
        exit;
    }
    end_output_buffers();
    header('Content-Type: ' . ($thumb['mime'] ?: thumb_spec($kind)['mime']));
    header('Content-Length: ' . (int) $thumb['size']);
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
    try {
        tg_stream_thumb($thumb);
    } catch (TelegramGone $e) {
        // The preview message itself was deleted in Telegram: forget it and rebuild it once
        if (headers_sent()) exit;
        q_exec('DELETE FROM thumbs WHERE file_id = ? AND kind = ?', [$file['id'], $kind]);
        header_remove();
        if ($attempt > 0) {
            // Still gone after rebuilding: let the original's route clean up or serve it. For
            // video/file previews there is no image to fall back to.
            if (!is_image($file)) {
                header('Content-Type: text/plain; charset=utf-8');
                header('Cache-Control: no-store');
                echo "No preview\n";
                exit;
            }
            header('Location: ' . ($fallback ?? raw_path($file)), true, 302);
            exit;
        }
        send_thumbnail($file, $kind, $fallback, $attempt + 1);
    }
    exit;
}
