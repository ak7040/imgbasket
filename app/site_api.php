<?php
// Public site: home page, content pages, anonymous image uploads, link-only file pages, files and thumbnails

function site_route(string $method, string $path): never
{
    $get = $method === 'GET' || $method === 'HEAD';
    if ($get && $path === '/') html_out(home_page());
    if ($get && $path === '/robots.txt') site_robots();
    if ($get && $path === '/sitemap.xml') site_sitemap();
    if ($get && ($path === '/terms' || $path === '/privacy')) html_out(legal_page(substr($path, 1)));
    if ($get && $path === '/llms.txt') site_llms();
    // Content pages: /imgur-alternative, /imgbb-alternative, /anonymous-image-hosting, /about, …
    if (preg_match('#^/([a-z0-9-]+)/$#', $path, $m) && in_array($m[1], content_slugs(), true)) {
        header('Location: /' . $m[1], true, 301); // one canonical address, no trailing slash
        exit;
    }
    if ($get && preg_match('#^/([a-z0-9-]+)$#', $path, $m) && in_array($m[1], content_slugs(), true)) {
        html_out(content_page($m[1]));
    }
    if ($get && $path === '/api/config') json_out([
        'maxUpload' => public_max_upload(),
        'expiryOptions' => array_map(fn(array $o) => ['value' => $o['value'], 'label' => $o['label']], expiry_options()),
    ]);
    if ($get && $path === '/api/gallery') site_gallery();
    if ($method === 'POST' && $path === '/api/upload') site_upload();
    if ($get && preg_match('#^/s/([A-Za-z0-9_-]+)$#', $path, $m)) site_image_page($m[1]);
    if ($get && preg_match('#^/i/([A-Za-z0-9_-]+)(?:\.[A-Za-z0-9]{1,8})?$#', $path, $m)) site_file($m[1]);
    if ($get && preg_match('#^/t/([A-Za-z0-9_-]+)\.(jpg|webp)$#', $path, $m)) site_thumbnail($m[1], $m[2]);
    if (str_starts_with($path, '/api/')) json_out(['error' => 'Not found'], 404);
    html_out(message_page('Not found', 'There is nothing here.'), 404);
}

function public_max_upload(): int
{
    $own = (int) config('public_max_upload', 0);
    return $own > 0 ? min($own, php_upload_limit()) : php_upload_limit();
}

// "Delete after" choices on the uploader. The key is what the form posts; the value is the number
// of seconds, or null for "keep forever". Only these values are accepted, so nobody can pin a file
// far into the future with a hand-made request.
const UPLOAD_EXPIRY = [
    'never' => [null, 'Keep forever'],
    '1h' => [3600, '1 hour'],
    '1d' => [86400, '1 day'],
    '7d' => [604800, '7 days'],
    '30d' => [2592000, '30 days'],
    '90d' => [7776000, '90 days'],
];

function expiry_options(): array
{
    $options = [];
    foreach (UPLOAD_EXPIRY as $value => [$seconds, $label]) {
        $options[] = ['value' => $value, 'label' => $label, 'seconds' => $seconds];
    }
    return $options;
}

// <option> markup for the home page's range selector, rendered server-side so the choices have one
// source of truth (UPLOAD_EXPIRY)
function expiry_options_html(): string
{
    $options = [];
    foreach (expiry_options() as $option) {
        $options[] = '<option value="' . esc($option['value']) . '"'
            . ($option['value'] === 'never' ? ' selected' : '') . '>'
            . esc($option['label']) . '</option>';
    }
    return "\n            " . implode("\n            ", $options);
}

// Turns the posted choice into a UTC datetime for files.expires_at, or null ("keep forever")
function parse_expiry(?string $choice): ?string
{
    $seconds = UPLOAD_EXPIRY[(string) $choice][0] ?? null;
    return $seconds === null ? null : gmdate('Y-m-d H:i:s', time() + $seconds);
}

// The shared token lookup for public pages: expired files are removed and reported as gone
function site_media_or_404(string $token): array
{
    $file = get_file_by_token($token) ?? not_found_page();
    if (file_is_expired($file)) {
        discard_file($file);
        html_out(message_page(
            'Link expired',
            'This file was set to delete itself automatically and is no longer available.'
        ), 410);
    }
    return $file;
}

// What the public site is allowed to know about a file
function public_item(array $file): array
{
    $kind = kind_of($file['mime']);
    if ($file['mime'] === 'image/gif') {
        // GIFs keep their animation; use the original as the preview
        $thumb = raw_path($file);
    } elseif (is_image($file)) {
        $thumb = '/t/' . $file['share_token'] . '.webp';
    } elseif (is_video($file) && get_thumb($file, 'grid')) {
        // A video only has a tile preview when FFmpeg made one at upload time
        $thumb = '/t/' . $file['share_token'] . '.webp';
    } else {
        $thumb = null;
    }

    return [
        'token' => $file['share_token'],
        'name' => $file['name'],
        'size' => (int) $file['size'],
        'mime' => $file['mime'],
        'kind' => $kind,
        'width' => $file['width'],
        'height' => $file['height'],
        'duration' => $file['duration'],
        'created_at' => $file['created_at'],
        'expires_at' => $file['expires_at'] ?? null,
        'page_url' => public_url() . '/s/' . $file['share_token'],
        'direct_url' => public_url() . raw_path($file),
        'src' => raw_path($file),
        'thumb' => $thumb,
    ];
}

function site_gallery(): never
{
    // There is no public gallery: every visitor upload is link-only. The endpoint is kept so older
    // clients and cached scripts get an empty list instead of an error.
    json_out(['items' => [], 'next' => null]);
}

// Keeps a readable name, drops paths and control characters, and uses the real extension
function clean_name(string $original, string $ext, string $fallback = 'image'): string
{
    $base = preg_replace('/\.[^.]*$/', '', basename(str_replace('\\', '/', $original)));
    $base = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f<>"\\\\\/]/u', '', $base)), 0, 100);
    return ($base !== '' ? $base : $fallback) . ".$ext";
}

function site_upload(): never
{
    if (!storage_ready()) json_out(['error' => 'Uploads are paused right now. Try again later.'], 503);

    // Expiring uploads are cleaned on access too; this keeps old ones from piling up in Telegram
    maybe_purge_expired();

    $ip = client_ip();
    $perHour = (int) config('public_uploads_per_hour', 30);
    if (!allow_event('upload', $ip, $perHour, 3600)) {
        $minutes = (int) ceil(event_retry_after('upload', $ip, 3600) / 60);
        json_out(['error' => "Upload limit reached. Try again in $minutes min."], 429);
    }

    // The current page posts "file"; older clients used "image" (and "video" was never sent)
    $upload = uploaded_files('file')[0] ?? uploaded_files('image')[0] ?? uploaded_files('video')[0] ?? null;
    if ($problem = upload_problem($upload)) json_out(['error' => $problem[1]], $problem[0]);
    if ($upload['size'] > public_max_upload()) {
        json_out(['error' => 'File is larger than ' . format_size(public_max_upload())], 413);
    }

    // Checked by content, not by name. Images first because it is the common case.
    $media = inspect_image($upload['tmp_name']);
    if (!$media) $media = inspect_video($upload['tmp_name']);
    if (!$media) {
        json_out(['error' => 'Only JPG, PNG, GIF, WebP, AVIF and BMP images and MP4, WebM, MOV and other videos are allowed'], 415);
    }
    $kind = str_starts_with($media['mime'], 'video/') ? 'video' : 'image';

    $file = store_file($upload['tmp_name'], [
        'name' => clean_name((string) $upload['name'], $media['ext'], $kind),
        'mime' => $media['mime'],
        'source' => 'public',
        // Every visitor upload is link-only: there is no public gallery and the page stays noindex.
        // The direct link works for anyone who has it, which is exactly what "share a link" means.
        'in_gallery' => false,
        'share_token' => new_token(),
        'uploader_ip' => $ip,
        'width' => $media['width'],
        'height' => $media['height'],
        'duration' => $media['duration'] ?? null,
        'expires_at' => parse_expiry(isset($_POST['expires']) ? (string) $_POST['expires'] : null),
    ]);
    json_out(public_item($file) + ['in_gallery' => (bool) $file['in_gallery']], 201);
}

function site_image_page(string $token): never
{
    $file = site_media_or_404($token);
    // Chat apps read og:image from this page for the link preview, so make sure it exists. For a
    // video this is a poster frame; without FFmpeg the page falls back to the site's share image.
    $og = null;
    if (is_media($file)) {
        try {
            if ($thumb = ensure_thumbnail($file, 'og')) {
                $og = ['url' => public_url() . "/t/$token.jpg", 'width' => $thumb['width'], 'height' => $thumb['height']];
            }
        } catch (TelegramGone $e) {
            // Deleted in the Telegram app: remove the stale record and show a normal 404
            discard_file($file);
            not_found_page();
        }
    }
    html_out(media_page($file, public_url() . "/s/$token", public_url() . raw_path($file), raw_path($file), $og));
}

function site_file(string $token): never
{
    $file = site_media_or_404($token);
    keep_unlisted_out_of_search($file);
    if (!storage_ready()) {
        html_out(message_page('Offline', 'This file is unavailable right now. Try again later.'), 503);
    }
    try {
        send_file($file, empty($_GET['download']), 'public, max-age=300');
    } catch (TelegramGone $e) {
        // Deleted in the Telegram app: remove the stale record and its remaining messages
        discard_file($file);
        if (!headers_sent()) {
            header_remove();
            not_found_page();
        }
        exit;
    }
}

// /t/<token>.jpg for link previews, /t/<token>.webp for gallery tiles
function site_thumbnail(string $token, string $ext): never
{
    $file = get_file_by_token($token) ?? not_found_page();
    // A thumbnail request is an <img> load, so expire it without the HTML "link expired" page
    if (file_is_expired($file)) {
        discard_file($file);
        not_found_page();
    }
    keep_unlisted_out_of_search($file);
    try {
        send_thumbnail($file, $ext === 'jpg' ? 'og' : 'grid');
    } catch (TelegramGone $e) {
        discard_file($file);
        if (!headers_sent()) {
            header_remove();
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo "Not found\n";
        }
        exit;
    }
}

// Unlisted files must not show up in Google Images. Chat apps ignore this header, so link
// previews still work.
function keep_unlisted_out_of_search(array $file): void
{
    if (!$file['in_gallery']) header('X-Robots-Tag: noindex, nofollow');
}

function site_robots(): never
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    // With an admin subdomain there's no /admin here to hide (and naming it would only advertise it)
    $admin = admin_host() === '' ? "Disallow: /admin\n" : '';
    echo "User-agent: *\n{$admin}Disallow: /api/\nAllow: /\n\nSitemap: " . public_url() . "/sitemap.xml\n";
    exit;
}

// A short, plain-text map for AI assistants and other agents that look for one. Google ignores it.
function site_llms(): never
{
    $url = public_url();
    $site = site_name();
    $lines = [
        "# $site",
        '',
        "> Free image and video hosting: 100% free, no account, no ads, no public gallery. Upload an",
        '> image or video and get a link-only page, a direct file link, and Markdown, HTML and BBCode.',
        '> Files up to ' . format_size(public_max_upload()) . ' each; optional delete-after from 1 hour to 90 days.',
        '',
        '## Pages',
        "- [Upload an image or video]($url/): home page and uploader",
    ];
    foreach (content_meta() as $slug => $meta) {
        $lines[] = "- [{$meta['crumb']}]($url/$slug)";
    }
    $lines[] = "- [Terms of Use]($url/terms)";
    $lines[] = "- [Privacy Policy]($url/privacy)";
    $lines[] = '';
    $lines[] = '## Notes';
    $lines[] = '- Every visitor upload is link-only: its page and file carry noindex and it is never added to the sitemap.';
    $lines[] = '- Images and videos are served from /i/<token>; previews from /t/<token>.jpg and /t/<token>.webp.';
    $lines[] = '- An upload can be given a delete-after period (1 hour to 90 days); when it expires its links return 410 and the file is deleted.';
    $lines[] = '- There is no public upload API. Uploads are rate-limited to '
        . (int) config('public_uploads_per_hour', 30) . ' per IP per hour.';
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo implode("\n", $lines) . "\n";
    exit;
}

// Home, content pages and legal pages. Visitor uploads are link-only and never listed here, so the
// sitemap stays small and contains only pages the site actually wants in search results.
function site_sitemap(): never
{
    $url = public_url();
    $x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    // The home page changes when the copy or the page templates change, not when someone uploads
    $latest = max(array_map(
        fn(string $f) => is_file($f) ? (int) filemtime($f) : 0,
        array_merge([APP_DIR . '/pages.php', APP_DIR . '/views/home.html'], glob(APP_DIR . '/views/pages/*.html') ?: [])
    ));

    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
        . '<url><loc>' . $x("$url/") . '</loc><lastmod>' . gmdate('Y-m-d', $latest ?: time())
        . '</lastmod><changefreq>weekly</changefreq><priority>1.0</priority></url>' . "\n";
    // Search-facing content pages, dated by the file that holds their copy
    foreach (content_meta() as $slug => $meta) {
        $file = APP_DIR . "/views/pages/$slug.html";
        $modified = is_file($file) ? gmdate('Y-m-d', filemtime($file)) : gmdate('Y-m-d');
        $out .= '<url><loc>' . $x("$url/$slug") . '</loc><lastmod>' . $modified
            . '</lastmod><changefreq>monthly</changefreq><priority>0.8</priority></url>' . "\n";
    }
    $legalDate = gmdate('Y-m-d', filemtime(APP_DIR . '/pages.php'));
    $out .= '<url><loc>' . $x("$url/terms") . '</loc><lastmod>' . $legalDate
        . '</lastmod><changefreq>yearly</changefreq><priority>0.2</priority></url>' . "\n"
        . '<url><loc>' . $x("$url/privacy") . '</loc><lastmod>' . $legalDate
        . '</lastmod><changefreq>yearly</changefreq><priority>0.2</priority></url>' . "\n";
    $out .= '</urlset>' . "\n";
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo $out;
    exit;
}
