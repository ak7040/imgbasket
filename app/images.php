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
        'og' => ['max' => 1200, 'ext' => 'jpg'],
        'grid' => ['max' => 640, 'ext' => $webp ? 'webp' : 'jpg'],
    };
}

function thumb_path(array $file, string $kind): string
{
    return storage_dir('cache') . "/{$file['id']}.$kind." . thumb_spec($kind)['ext'];
}

// Returns the thumbnail's path, creating it on first use; null if it can't be made
function thumbnail(array $file, string $kind, ?string $source = null): ?string
{
    $target = thumb_path($file, $kind);
    if (is_file($target)) return $target;
    if (!is_image($file) || !function_exists('imagecreatetruecolor')) return null;

    $temp = null;
    try {
        $source ??= cached_path($file);
        if (!$source) {
            // Big originals aren't worth downloading just for a preview
            if ($file['size'] > 200 * 1024 * 1024 || !storage_ready()) return null;
            $source = $temp = tempnam(sys_get_temp_dir(), 'imgbasket');
            download_to($file, $temp);
        }
        return make_thumbnail($source, $target, $kind);
    } catch (Throwable $e) {
        error_log("No $kind thumbnail for file {$file['id']}: " . $e->getMessage());
        return null;
    } finally {
        if ($temp) @unlink($temp);
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

// Builds both thumbnails right after upload, from the uploaded temp file
function prepare_thumbnails(array $file, string $source): void
{
    if (!is_image($file)) return;
    foreach (['og', 'grid'] as $kind) thumbnail($file, $kind, $source);
}

function send_thumbnail(string $path): never
{
    end_output_buffers();
    header('Content-Type: ' . (str_ends_with($path, '.webp') ? 'image/webp' : 'image/jpeg'));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
