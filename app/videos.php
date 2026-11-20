<?php
// Video checks (by content, not by name), metadata, and poster frames.
//
// Videos are stored in Telegram exactly like images: as one document message (or several pieces for
// files over the storage limit). Telegram keeps the original bytes; it does not transcode them. See
// storage_saved.php / storage_bot.php for the upload side.
//
// Poster frames need FFmpeg. It is optional: without it, videos still upload, play and download, they
// just don't get a thumbnail or link-preview image. ffmpeg_path in config.php overrides the detection.

// Telegram sends the file back with the same mime type it was stored with, and the public site uses
// these types to decide what a browser can play inline.
const VIDEO_TYPES = [
    'video/mp4'        => ['mp4',  true],
    'video/webm'       => ['webm', true],
    'video/ogg'        => ['ogv',  true],
    'video/quicktime'  => ['mov',  true],
    'video/x-m4v'      => ['m4v',  true],
    'video/x-matroska' => ['mkv',  false],
    'video/x-msvideo'  => ['avi',  false],
    'video/mpeg'       => ['mpg',  false],
    'video/3gpp'       => ['3gp',  false],
    'video/x-flv'      => ['flv',  false],
    'video/x-ms-wmv'   => ['wmv',  false],
];

// Returns ['mime', 'ext', 'width', 'height', 'duration', 'playable'] for supported videos, or null.
function inspect_video(string $path): ?array
{
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    // Some libmagic builds report ISO base media without saying it is video
    if ($mime === 'application/mp4') $mime = 'video/mp4';
    if (!isset(VIDEO_TYPES[$mime])) return null;

    [$ext, $playable] = VIDEO_TYPES[$mime];
    $info = probe_video($path, $mime) ?? [];

    return [
        'mime' => $mime,
        'ext' => $ext,
        'width' => $info['width'] ?? null,
        'height' => $info['height'] ?? null,
        'duration' => $info['duration'] ?? null,
        'playable' => $playable,
    ];
}

// Whether a browser can normally play this video type inline
function video_playable_mime(string $mime): bool
{
    return (bool) (VIDEO_TYPES[$mime][1] ?? false);
}

// "PT1M5S" for schema.org (VideoObject duration)
function iso_duration(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return 'PT' . ($h ? "{$h}H" : '') . ($m ? "{$m}M" : '') . (($s || (!$h && !$m)) ? "{$s}S" : '');
}

// "1:05" / "1:02:03" for the page's metadata line
function format_duration(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

// Width, height and duration, from ffprobe when present and a small MP4 parser otherwise
function probe_video(string $path, string $mime): ?array
{
    $info = ffprobe_info($path);
    if ($info) return $info;
    // MP4/MOV/M4V/3GP are all ISO base media files, which the built-in parser understands
    if (in_array($mime, ['video/mp4', 'video/quicktime', 'video/x-m4v', 'video/3gpp'], true)) {
        return mp4_info($path);
    }
    return null;
}

// True when PHP may run shell commands (exec is enabled and not in disable_functions)
function can_exec(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return $ok = function_exists('exec') && !in_array('exec', $disabled, true);
}

function ffmpeg_bin(): ?string
{
    static $found = false;
    if ($found !== false) return $found;
    if (!can_exec()) return $found = null;

    $custom = trim((string) config('ffmpeg_path', ''));
    $candidates = $custom !== ''
        ? [$custom]
        : ['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'];
    foreach ($candidates as $candidate) {
        $out = [];
        $code = 1;
        @exec(escapeshellarg($candidate) . ' -version 2>/dev/null', $out, $code);
        if ($code === 0) return $found = $candidate;
    }
    return $found = null;
}

function ffprobe_bin(): ?string
{
    static $found = false;
    if ($found !== false) return $found;
    if (!can_exec()) return $found = null;

    $candidates = [];
    // ffprobe normally sits next to ffmpeg
    if ($ffmpeg = ffmpeg_bin()) {
        $dir = dirname($ffmpeg);
        $candidates[] = $dir === '.' ? 'ffprobe' : $dir . '/ffprobe';
    }
    $candidates = array_merge($candidates, ['ffprobe', '/usr/bin/ffprobe', '/usr/local/bin/ffprobe', '/opt/homebrew/bin/ffprobe']);
    foreach (array_unique($candidates) as $candidate) {
        $out = [];
        $code = 1;
        @exec(escapeshellarg($candidate) . ' -version 2>/dev/null', $out, $code);
        if ($code === 0) return $found = $candidate;
    }
    return $found = null;
}

// ffprobe JSON: width, height (rotation-aware) and whole seconds
function ffprobe_info(string $path): ?array
{
    $probe = ffprobe_bin();
    if (!$probe) return null;

    $out = [];
    $code = 1;
    @exec(
        escapeshellarg($probe) . ' -v quiet -print_format json -show_format -show_streams ' . escapeshellarg($path) . ' 2>/dev/null',
        $out,
        $code
    );
    if ($code !== 0) return null;

    $json = json_decode(implode("\n", $out), true);
    if (!is_array($json)) return null;

    $stream = null;
    foreach ($json['streams'] ?? [] as $s) {
        if (($s['codec_type'] ?? '') === 'video' && !empty($s['width'])) {
            $stream = $s;
            break;
        }
    }
    if (!$stream) return null;

    $width = (int) $stream['width'];
    $height = (int) $stream['height'];
    // A phone video can carry a 90°/270° display matrix; the frame is shown rotated
    foreach ($stream['side_data_list'] ?? [] as $side) {
        if (abs((int) ($side['rotation'] ?? 0)) % 180 === 90) {
            [$width, $height] = [$height, $width];
            break;
        }
    }

    $duration = $stream['duration'] ?? $json['format']['duration'] ?? null;
    return [
        'width' => $width ?: null,
        'height' => $height ?: null,
        'duration' => $duration === null ? null : max(0, (int) round((float) $duration)),
    ];
}

// Reads the moov box of an ISO base media file (MP4, MOV, M4V, 3GP) without external tools
function mp4_info(string $path): ?array
{
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    try {
        $size = (int) filesize($path);
        $moov = null;
        foreach (mp4_boxes($fh, 0, $size) as [$type, $offset, $length]) {
            if ($type === 'moov') {
                $moov = [$offset, $length];
                break;
            }
        }
        if (!$moov) return null;

        $duration = null;
        foreach (mp4_boxes($fh, $moov[0], $moov[0] + $moov[1]) as [$type, $offset, $length]) {
            if ($type !== 'mvhd') continue;
            fseek($fh, $offset);
            $version = ord((string) fread($fh, 1));
            if ($version === 1) {
                fseek($fh, $offset + 20);
                $tsRaw = (string) fread($fh, 4);
                $ticksRaw = (string) fread($fh, 8);
                $timescale = strlen($tsRaw) === 4 ? unpack('N', $tsRaw)[1] : 0;
                $ticks = strlen($ticksRaw) === 8 ? unpack('J', $ticksRaw)[1] : 0;
            } else {
                fseek($fh, $offset + 12);
                $tsRaw = (string) fread($fh, 4);
                $ticksRaw = (string) fread($fh, 4);
                $timescale = strlen($tsRaw) === 4 ? unpack('N', $tsRaw)[1] : 0;
                $ticks = strlen($ticksRaw) === 4 ? unpack('N', $ticksRaw)[1] : 0;
            }
            if ($timescale > 0) $duration = (int) round($ticks / $timescale);
            break;
        }

        $width = $height = 0;
        foreach (mp4_boxes($fh, $moov[0], $moov[0] + $moov[1]) as [$type, $offset, $length]) {
            if ($type !== 'trak') continue;
            foreach (mp4_boxes($fh, $offset, $offset + $length) as [$child, $childOffset]) {
                if ($child !== 'tkhd') continue;
                fseek($fh, $childOffset);
                $version = ord((string) fread($fh, 1));
                // Width and height are the last two 16.16 fixed-point values of tkhd
                fseek($fh, $childOffset + ($version === 1 ? 88 : 76));
                $raw = (string) fread($fh, 8);
                if (strlen($raw) < 8) break;
                [$w, $h] = array_values(unpack('N2', $raw));
                $w = (int) round($w / 65536);
                $h = (int) round($h / 65536);
                if ($w * $h > $width * $height) {
                    $width = $w;
                    $height = $h;
                }
                break;
            }
        }

        return [
            'width' => $width ?: null,
            'height' => $height ?: null,
            'duration' => $duration,
        ];
    } finally {
        fclose($fh);
    }
}

// Yields [type, payload offset, payload length] for the boxes between $start and $end
function mp4_boxes($fh, int $start, int $end): Generator
{
    $pos = $start;
    while ($pos + 8 <= $end) {
        fseek($fh, $pos);
        $head = (string) fread($fh, 16);
        if (strlen($head) < 8) return;

        $size = unpack('N', substr($head, 0, 4))[1];
        $type = substr($head, 4, 4);
        $header = 8;
        if ($size === 1) {
            if (strlen($head) < 16) return;
            $hi = unpack('N', substr($head, 8, 4))[1];
            $lo = unpack('N', substr($head, 12, 4))[1];
            $size = $hi * 4294967296 + $lo;
            $header = 16;
        } elseif ($size === 0) {
            $size = $end - $pos;
        }
        // Malformed sizes must not send the walker backwards
        if ($size < $header || $pos + $size > $end) return;

        yield [$type, $pos + $header, $size - $header];
        $pos += $size;
    }
}

// Extracts a frame with FFmpeg and resizes it with the same GD pipeline as images
function make_video_thumbnail(string $source, string $target, string $kind): ?string
{
    $ffmpeg = ffmpeg_bin();
    if (!$ffmpeg) return null;

    $base = tempnam(storage_dir('tmp'), 'frame');
    if ($base === false) return null;
    @unlink($base);
    $frame = $base . '.jpg';
    try {
        $made = false;
        // Seek a little in so the frame is not a black lead-in; fall back to the first frame for
        // clips shorter than half a second.
        foreach (['0.5', '0'] as $seek) {
            @unlink($frame);
            $cmd = escapeshellarg($ffmpeg)
                . ' -hide_banner -nostdin -loglevel error -y -ss ' . $seek
                . ' -i ' . escapeshellarg($source)
                // The comma must be escaped, or FFmpeg treats it as a filter separator
                . ' -frames:v 1 -vf ' . escapeshellarg('scale=min(1280\,iw):-2')
                . ' -f image2 -c:v mjpeg -q:v 2 ' . escapeshellarg($frame) . ' 2>/dev/null';
            $out = [];
            $code = 1;
            @exec($cmd, $out, $code);
            clearstatcache(true, $frame);
            if ($code === 0 && is_file($frame) && filesize($frame) > 0) {
                $made = true;
                break;
            }
        }
        if (!$made) return null;
        return make_thumbnail($frame, $target, $kind);
    } finally {
        @unlink($frame);
    }
}
