<?php
// Public site: home page, gallery API, anonymous image uploads, image pages, files and thumbnails

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
    if ($get && $path === '/api/config') json_out(['maxUpload' => public_max_upload()]);
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

// What the public site is allowed to know about a file
function public_item(array $file): array
{
    return [
        'token' => $file['share_token'],
        'name' => $file['name'],
        'size' => (int) $file['size'],
        'width' => $file['width'],
        'height' => $file['height'],
        'created_at' => $file['created_at'],
        'page_url' => public_url() . '/s/' . $file['share_token'],
        'direct_url' => public_url() . raw_path($file),
        'src' => raw_path($file),
        // GIFs keep their animation in the gallery; everything else uses the small preview
        'thumb' => $file['mime'] === 'image/gif' ? raw_path($file) : '/t/' . $file['share_token'] . '.webp',
    ];
}

function site_gallery(): never
{
    $limit = 30;
    $before = (int) ($_GET['before'] ?? 0) ?: PHP_INT_MAX;
    // One extra row tells us whether there's another page
    $rows = array_map('file_row', q_all(
        'SELECT * FROM files WHERE in_gallery = 1 AND share_token IS NOT NULL AND id < ? ORDER BY id DESC LIMIT ' . ($limit + 1),
        [$before]
    ));
    $page = array_slice($rows, 0, $limit);
    json_out([
        'items' => array_map('public_item', $page),
        'next' => count($rows) > $limit ? end($page)['id'] : null,
    ]);
}

// Keeps a readable name, drops paths and control characters, and uses the real extension
function clean_name(string $original, string $ext): string
{
    $base = preg_replace('/\.[^.]*$/', '', basename(str_replace('\\', '/', $original)));
    $base = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f<>"\\\\\/]/u', '', $base)), 0, 100);
    return ($base !== '' ? $base : 'image') . ".$ext";
}

function site_upload(): never
{
    if (!storage_ready()) json_out(['error' => 'Uploads are paused right now. Try again later.'], 503);

    $ip = client_ip();
    $perHour = (int) config('public_uploads_per_hour', 30);
    if (!allow_event('upload', $ip, $perHour, 3600)) {
        $minutes = (int) ceil(event_retry_after('upload', $ip, 3600) / 60);
        json_out(['error' => "Upload limit reached. Try again in $minutes min."], 429);
    }

    $upload = uploaded_files('image')[0] ?? null;
    if ($problem = upload_problem($upload)) json_out(['error' => $problem[1]], $problem[0]);
    if ($upload['size'] > public_max_upload()) {
        json_out(['error' => 'Image is larger than ' . format_size(public_max_upload())], 413);
    }

    $image = inspect_image($upload['tmp_name']);
    if (!$image) json_out(['error' => 'Only JPG, PNG, GIF, WebP, AVIF and BMP images are allowed'], 415);

    $file = store_file($upload['tmp_name'], [
        'name' => clean_name((string) $upload['name'], $image['ext']),
        'mime' => $image['mime'],
        'source' => 'public',
        // Unlisted uploads still get links, they just don't appear in the gallery
        'in_gallery' => ($_POST['public'] ?? '1') !== '0',
        'share_token' => new_token(),
        'uploader_ip' => $ip,
        'width' => $image['width'],
        'height' => $image['height'],
    ]);
    json_out(public_item($file) + ['in_gallery' => (bool) $file['in_gallery']], 201);
}

function site_image_page(string $token): never
{
    $file = get_file_by_token($token) ?? not_found_page();
    // Chat apps read og:image from this page for the link preview, so make sure it exists
    $og = null;
    if (is_image($file)) {
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
    html_out(image_page($file, public_url() . "/s/$token", public_url() . raw_path($file), raw_path($file), $og));
}

function site_file(string $token): never
{
    $file = get_file_by_token($token) ?? not_found_page();
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
        "> Free image hosting with unlimited uploads, no account required. Upload an image and get a",
        '> page link and a direct image link, plus Markdown, HTML and BBCode. Files up to '
            . format_size(public_max_upload()) . ' each.',
        '',
        '## Pages',
        "- [Upload an image]($url/): home page and uploader",
    ];
    foreach (content_meta() as $slug => $meta) {
        $lines[] = "- [{$meta['crumb']}]($url/$slug)";
    }
    $lines[] = "- [Terms of Use]($url/terms)";
    $lines[] = "- [Privacy Policy]($url/privacy)";
    $lines[] = '';
    $lines[] = '## Notes';
    $lines[] = '- Public gallery images have indexable pages at /s/<token>. Unlisted images are link-only and noindex.';
    $lines[] = '- Images are served from /i/<token>; previews from /t/<token>.jpg and /t/<token>.webp.';
    $lines[] = '- There is no public upload API. Uploads are rate-limited to '
        . (int) config('public_uploads_per_hour', 30) . ' per IP per hour.';
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo implode("\n", $lines) . "\n";
    exit;
}

// Home, content pages, legal pages, and every image in the public gallery (never unlisted ones), with image tags
function site_sitemap(): never
{
    $url = public_url();
    $x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    // If the database is briefly unavailable the static pages should still be listable
    try {
        $latest = q_one('SELECT MAX(created_at) AS t FROM files WHERE in_gallery = 1 AND share_token IS NOT NULL')['t'] ?? null;
    } catch (Throwable $e) {
        $latest = null;
    }

    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n"
        . '<url><loc>' . $x("$url/") . '</loc>' . ($latest ? '<lastmod>' . gmdate('Y-m-d', strtotime("$latest UTC")) . '</lastmod>' : '')
        . '<changefreq>hourly</changefreq><priority>1.0</priority></url>' . "\n";
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
    // A sitemap holds at most 50,000 URLs
    try {
        $rows = q_all("SELECT * FROM files WHERE in_gallery = 1 AND share_token IS NOT NULL AND mime LIKE 'image/%' ORDER BY id DESC LIMIT 49000");
    } catch (Throwable $e) {
        $rows = [];
    }
    foreach ($rows as $row) {
        $f = file_row($row);
        $out .= '<url><loc>' . $x("$url/s/{$f['share_token']}") . '</loc><lastmod>' . gmdate('Y-m-d', strtotime($f['created_at'] . ' UTC'))
            . '</lastmod><image:image><image:loc>' . $x($url . raw_path($f)) . '</image:loc>'
            . '<image:title>' . $x($f['name']) . '</image:title></image:image></url>' . "\n";
    }
    $out .= '</urlset>' . "\n";
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo $out;
    exit;
}
