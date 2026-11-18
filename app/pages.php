<?php
// Server-rendered pages: image pages, legal pages, messages, plus the SEO bits they share

function esc(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// JSON for <script type="application/ld+json">; escaped so it can't close the script tag
function json_ld(array $data): string
{
    return '<script type="application/ld+json">'
        . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
        . '</script>';
}

function kind_of(?string $mime): string
{
    $mime = (string) $mime;
    if (str_starts_with($mime, 'image/')) return 'image';
    if (str_starts_with($mime, 'video/')) return 'video';
    if (str_starts_with($mime, 'audio/')) return 'audio';
    return 'file';
}

function contact_email(): string
{
    return (string) config('contact_email', '');
}

function site_description(): string
{
    return (string) config('site_description', 'Free image hosting: upload images and get shareable links in seconds.');
}

// Tags every public page shares: icons, theme, fonts, Search Console verification
function common_head(): string
{
    $verify = (string) config('google_site_verification', '');
    return implode("\n  ", array_filter([
        '<meta name="theme-color" content="#0b0d11">',
        '<link rel="icon" href="/favicon.svg" type="image/svg+xml">',
        '<link rel="apple-touch-icon" href="/apple-touch-icon.png">',
        '<link rel="manifest" href="/site.webmanifest">',
        '<link rel="preconnect" href="https://fonts.googleapis.com">',
        '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>',
        '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">',
        $verify !== '' ? '<meta name="google-site-verification" content="' . esc($verify) . '">' : null,
    ]));
}

function site_footer(): string
{
    $site = esc(site_name());
    $year = gmdate('Y');
    $contact = contact_email() !== '' ? '<a href="mailto:' . esc(contact_email()) . '">Contact</a>' : '';
    return <<<HTML
<footer class="site-footer">
    <div class="footer-inner">
      <a class="brand" href="/"><span class="mark" data-icon="logo"></span>$site</a>
      <nav class="footer-links" aria-label="Footer">
        <a href="/">Upload</a>
        <a href="/imgur-alternative">Imgur alternative</a>
        <a href="/imgbb-alternative">ImgBB alternative</a>
        <a href="/anonymous-image-hosting">Anonymous hosting</a>
        <a href="/image-hosting-for-forums">For forums</a>
        <a href="/about">About</a>
        <a href="/terms">Terms</a>
        <a href="/privacy">Privacy</a>
        $contact
      </nav>
      <p class="footer-note">© $year $site · Free image hosting, unlimited uploads</p>
    </div>
  </footer>
HTML;
}

// Search-facing pages built from app/views/pages/<slug>.html
function content_meta(): array
{
    $size = format_size(public_max_upload());
    return [
        'imgur-alternative' => [
            'title' => 'Imgur Alternative — ' . $size . ' Free Image Hosting · ' . site_name(),
            'description' => 'Looking for an Imgur alternative? ' . site_name() . ' hosts images free, without an account, up to ' . $size . ' each, with direct links, Markdown and BBCode.',
            'crumb' => 'Imgur alternative',
        ],
        'imgbb-alternative' => [
            'title' => 'ImgBB Alternative — ' . $size . ' Free Image Hosting · ' . site_name(),
            'description' => 'Swap ImgBB for ' . site_name() . ': no account, no ads, up to ' . $size . ' per image, direct links and BBCode. A free ImgBB alternative for forums and sharing.',
            'crumb' => 'ImgBB alternative',
        ],
        'anonymous-image-hosting' => [
            'title' => 'Anonymous Image Hosting — Upload Without an Account · ' . site_name(),
            'description' => 'Upload images anonymously. No sign-up, no email, no ad trackers. Public or unlisted links, up to ' . $size . ' per image, free.',
            'crumb' => 'Anonymous image hosting',
        ],
        'image-hosting-for-forums' => [
            'title' => 'Free Image Hosting for Forums — BBCode, HTML & Direct Links · ' . site_name(),
            'description' => 'Host images for forums, Reddit and blogs. Copy BBCode, Markdown, HTML or a direct link in one click. Free, no account, up to ' . $size . ' per image.',
            'crumb' => 'Image hosting for forums',
        ],
        'about' => [
            'title' => 'About ' . site_name() . ' — How Our Free Image Hosting Works',
            'description' => 'How ' . site_name() . ' works: free image hosting on Telegram-backed storage, no account, up to ' . $size . ' per image, and how we handle abuse and privacy.',
            'crumb' => 'About',
        ],
    ];
}

function content_slugs(): array
{
    return array_keys(content_meta());
}

// BreadcrumbList JSON-LD. Pass [name, path] pairs; a null path omits the URL for the last item.
function breadcrumb_ld(array $items): string
{
    $list = [];
    foreach ($items as $i => [$name, $path]) {
        $item = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name];
        if ($path !== null) $item['item'] = public_url() . $path;
        $list[] = $item;
    }
    return json_ld([
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $list,
    ]);
}

function content_page(string $slug): string
{
    $meta = content_meta()[$slug];
    $body = render_view("pages/$slug.html", [
        'MAX_UPLOAD' => esc(format_size(public_max_upload())),
    ]);
    $crumbs = '<nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">Home</a>'
        . '<span aria-hidden="true">/</span><span>' . esc($meta['crumb']) . '</span></nav>';
    // Keep the breadcrumbs inside the article column, not loose on the page
    $body = str_replace('<main class="content-page">', '<main class="content-page">' . $crumbs, $body, $count);
    if ($count === 0) $body = $crumbs . $body;
    return layout([
        'title' => $meta['title'],
        'description' => $meta['description'],
        'canonical' => public_url() . "/$slug",
        'head' => breadcrumb_ld([['Home', '/'], [$meta['crumb'], null]]),
        'body' => $body,
    ]);
}

// Templates in app/views use {{SITE_NAME}}, {{SITE_URL}}, {{DESCRIPTION}}, {{HEAD}}, {{FOOTER}}, …
function render_view(string $name, array $vars = []): string
{
    $vars += [
        'SITE_NAME' => esc(site_name()),
        'SITE_URL' => esc(public_url()),
        'DESCRIPTION' => esc(site_description()),
        'HEAD' => common_head(),
        'FOOTER' => site_footer(),
        'CONTACT' => esc(contact_email()),
    ];
    $replace = [];
    foreach ($vars as $k => $v) $replace['{{' . $k . '}}'] = $v;
    return strtr((string) file_get_contents(APP_DIR . "/views/$name"), $replace);
}

// The first page of the public gallery, rendered into the HTML so crawlers get real links to image
// pages even before the gallery script runs. The script replaces it with its own masonry layout.
function recent_gallery_html(int $limit = 12): string
{
    try {
        $rows = q_all(
            'SELECT * FROM files WHERE in_gallery = 1 AND share_token IS NOT NULL AND mime LIKE \'image/%\''
            . ' ORDER BY id DESC LIMIT ' . $limit
        );
    } catch (Throwable $e) {
        return '';
    }
    if (!$rows) return '';

    $cols = [[], [], []];
    $i = 0;
    foreach ($rows as $row) {
        $f = file_row($row);
        $ratio = $f['width'] && $f['height'] ? 'aspect-ratio:' . $f['width'] . ' / ' . $f['height'] . ';' : '';
        $thumb = $f['mime'] === 'image/gif' ? raw_path($f) : '/t/' . $f['share_token'] . '.webp';
        $dims = $f['width'] ? ' width="' . (int) $f['width'] . '" height="' . (int) $f['height'] . '"' : '';
        $cols[$i++ % 3][] = '<a class="card" href="' . esc(public_url() . '/s/' . $f['share_token']) . '" style="' . $ratio . '">'
            . '<img class="loaded" src="' . esc($thumb) . '" alt="' . esc($f['name']) . '"' . $dims . ' loading="lazy" decoding="async">'
            . '<div class="shade"><div class="name">' . esc($f['name']) . '</div>'
            . '<div class="meta">' . esc(format_size($f['size'])) . ' · '
            . esc(gmdate('M j, Y', strtotime($f['created_at'] . ' UTC'))) . '</div></div></a>';
    }
    $html = '';
    foreach ($cols as $col) {
        if ($col) $html .= '<div class="col">' . implode('', $col) . '</div>';
    }
    return $html;
}

function home_page(): string
{
    $url = public_url();
    $ld = json_ld([
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'WebSite',
                '@id' => "$url/#website",
                'url' => "$url/",
                'name' => site_name(),
                'description' => site_description(),
                'inLanguage' => 'en',
            ],
            [
                '@type' => 'Organization',
                '@id' => "$url/#organization",
                'name' => site_name(),
                'url' => "$url/",
                'logo' => "$url/icon-512.png",
                'description' => 'Free image hosting with unlimited uploads and no account required.',
            ] + (contact_email() !== '' ? ['email' => contact_email()] : []),
            [
                '@type' => 'WebApplication',
                'name' => site_name(),
                'url' => "$url/",
                'applicationCategory' => 'MultimediaApplication',
                'operatingSystem' => 'Any',
                'browserRequirements' => 'Requires a modern browser with JavaScript enabled.',
                'isAccessibleForFree' => true,
                'featureList' => [
                    'Image upload without an account',
                    'Direct image links, Markdown, HTML and BBCode',
                    'Public gallery or unlisted images',
                    'Uploads up to ' . format_size(public_max_upload()) . ' per image',
                ],
                'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
            ],
        ],
    ]);
    return render_view('home.html', [
        'JSONLD' => $ld,
        'MAX_UPLOAD' => esc(format_size(public_max_upload())),
        'RECENT' => recent_gallery_html(),
    ]);
}

// $o: title, description, canonical, robots, head, body, script, og_image
function layout(array $o): string
{
    $site = esc(site_name());
    $title = esc($o['title']);
    $description = esc($o['description'] ?? site_description());
    $canonical = isset($o['canonical']) ? '<link rel="canonical" href="' . esc($o['canonical']) . '">' : '';
    $robots = isset($o['robots']) ? '<meta name="robots" content="' . esc($o['robots']) . '">' : '';
    $common = common_head();
    $head = $o['head'] ?? '';
    $body = $o['body'];
    $script = $o['script'] ?? '';
    $footer = site_footer();
    return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>$title</title>
  <meta name="description" content="$description">
  $canonical
  $robots
  $common
  <link rel="stylesheet" href="/site.css">
  $head
</head>
<body>
  <header class="site-header">
    <a class="brand" href="/"><span class="mark" data-icon="logo"></span>$site</a>
    <a class="btn primary" href="/"><span data-icon="upload"></span>Upload</a>
  </header>
  $body
  $footer
  <script src="/icons.js"></script>
  <script>hydrateIcons();$script</script>
</body>
</html>
HTML;
}

// Inline script for the image page: copy buttons and format tabs
const PAGE_SCRIPT = <<<'JS'

  const input = document.getElementById('code');
  const tabs = document.querySelectorAll('.tabs button');
  for (const b of tabs) b.onclick = () => {
    input.value = b.dataset.value;
    for (const t of tabs) t.classList.toggle('active', t === b);
  };
  const copy = (text, btn) => navigator.clipboard.writeText(text).then(() => {
    const label = btn.querySelector('.label');
    const prev = label.textContent;
    label.textContent = 'Copied!';
    setTimeout(() => (label.textContent = prev), 1500);
  });
  document.getElementById('copy-code').onclick = (e) => copy(input.value, e.currentTarget);
  document.getElementById('copy-link').onclick = (e) => copy(e.currentTarget.dataset.url, e.currentTarget);
  input.onfocus = () => input.select();
JS;

// $og: ['url', 'width', 'height'] of the link-preview thumbnail, or null
function image_page(array $file, string $pageUrl, string $directUrl, string $rawPath, ?array $og): string
{
    $kind = kind_of($file['mime']);
    $site = site_name();
    $uploaded = strtotime($file['created_at'] . ' UTC');
    $date = gmdate('M j, Y', $uploaded);
    $ext = strtoupper(preg_match('/\.([a-z0-9]{1,6})$/i', $file['name'], $m) ? $m[1] : 'file');
    $dims = $file['width'] && $file['height'] ? " · {$file['width']} × {$file['height']}" : '';
    $size = format_size($file['size']);
    // A readable title from the file name: "summer-trip_01.jpg" -> "summer trip 01"
    $label = trim(preg_replace('/[_\-.]+/', ' ', pathinfo($file['name'], PATHINFO_FILENAME))) ?: $file['name'];
    $kindWord = ['image' => 'image', 'video' => 'video', 'audio' => 'audio file'][$kind] ?? 'file';
    $description = sprintf(
        '%s%s %s (%s) hosted on %s. View, download or share it with a direct link.',
        ucfirst($label), $file['width'] ? " – {$file['width']}×{$file['height']}" : '', $kindWord, $size, $site
    );

    $media = match ($kind) {
        'image' => '<img src="' . esc($rawPath) . '" alt="' . esc($label) . '"'
            . ($file['width'] ? " width=\"{$file['width']}\" height=\"{$file['height']}\"" : '') . ' fetchpriority="high">',
        'video' => '<video src="' . esc($rawPath) . '" controls playsinline preload="metadata"></video>',
        'audio' => '<audio src="' . esc($rawPath) . '" controls preload="metadata"></audio>',
        default => '<div class="file-icon"><div class="ext">' . esc($ext) . '</div><div>No preview available</div></div>',
    };

    $name = preg_replace('/[\[\]<>"]/', '', $file['name']);
    $formats = $kind === 'image'
        ? [
            ['Link', $pageUrl],
            ['Direct', $directUrl],
            ['Markdown', "![$name]($directUrl)"],
            ['HTML', "<img src=\"$directUrl\" alt=\"$name\">"],
            ['BBCode', "[img]{$directUrl}[/img]"],
        ]
        : [
            ['Link', $pageUrl],
            ['Direct', $directUrl],
            ['Markdown', "[$name]($pageUrl)"],
            ['HTML', "<a href=\"$pageUrl\">$name</a>"],
        ];

    // Link previews (WhatsApp, Telegram, Discord, X, Facebook...) use a small JPEG, because
    // several apps skip images that are too large. Without one, fall back to the original.
    $preview = $og['url'] ?? ($kind === 'image' ? $directUrl : null);
    $head = array_filter([
        '<meta property="og:site_name" content="' . esc($site) . '">',
        '<meta property="og:title" content="' . esc($file['name']) . '">',
        '<meta property="og:description" content="' . esc($description) . '">',
        '<meta property="og:type" content="website">',
        '<meta property="og:url" content="' . esc($pageUrl) . '">',
        $preview ? '<meta property="og:image" content="' . esc($preview) . '">' : null,
        $preview && str_starts_with($preview, 'https:') ? '<meta property="og:image:secure_url" content="' . esc($preview) . '">' : null,
        $og ? '<meta property="og:image:type" content="image/jpeg">' : null,
        $og ? '<meta property="og:image:width" content="' . (int) $og['width'] . '">' : null,
        $og ? '<meta property="og:image:height" content="' . (int) $og['height'] . '">' : null,
        $preview ? '<meta property="og:image:alt" content="' . esc($label) . '">' : null,
        '<meta name="twitter:card" content="' . ($preview ? 'summary_large_image' : 'summary') . '">',
        '<meta name="twitter:title" content="' . esc($file['name']) . '">',
        '<meta name="twitter:description" content="' . esc($description) . '">',
        $preview ? '<meta name="twitter:image" content="' . esc($preview) . '">' : null,
        $kind === 'video' ? '<meta property="og:video" content="' . esc($directUrl) . '">' : null,
        $kind === 'image' ? json_ld(array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'ImageObject',
            'name' => $label,
            'description' => $description,
            'contentUrl' => $directUrl,
            'thumbnailUrl' => $og['url'] ?? null,
            'url' => $pageUrl,
            'encodingFormat' => $file['mime'],
            'width' => $file['width'],
            'height' => $file['height'],
            'contentSize' => $size,
            'uploadDate' => gmdate('c', $uploaded),
            'isPartOf' => ['@id' => public_url() . '/#website'],
        ], fn($v) => $v !== null)) : null,
    ]);

    $head[] = breadcrumb_ld([['Home', '/'], [$file['name'], null]]);

    $tabs = '';
    foreach ($formats as $i => [$tabLabel, $value]) {
        $tabs .= '<button class="' . ($i ? '' : 'active') . '" data-value="' . esc($value) . '">' . $tabLabel . '</button>';
    }

    $report = '';
    if (contact_email() !== '') {
        $mail = 'mailto:' . contact_email() . '?subject=' . rawurlencode("Report: $pageUrl")
            . '&body=' . rawurlencode("I'm reporting this file: $pageUrl\n\nReason:\n");
        $report = '<a class="report-link" href="' . esc($mail) . '"><span data-icon="alert"></span>Report</a>';
    }

    $title = esc($file['name']);
    $meta = esc("$size$dims · $date");
    $pageAttr = esc($pageUrl);
    $download = esc($rawPath) . '?download=1';
    $first = esc($formats[0][1]);
    $body = <<<HTML
<main class="viewer-page">
    <div class="stage">$media</div>
    <div class="info">
      <div>
        <h1>$title</h1>
        <div class="meta">$meta</div>
      </div>
      <div class="actions">
        <button class="btn" id="copy-link" data-url="$pageAttr"><span data-icon="link"></span><span class="label">Copy link</span></button>
        <a class="btn primary" href="$download"><span data-icon="download"></span>Download</a>
      </div>
    </div>
    <section class="panel">
      <h2 class="panel-title">Share</h2>
      <div class="share-box">
        <div class="tabs">$tabs</div>
        <div class="copy-row">
          <input id="code" readonly value="$first" aria-label="Share code">
          <button class="btn primary" id="copy-code"><span data-icon="copy"></span><span class="label">Copy</span></button>
        </div>
      </div>
    </section>
    <div class="page-foot">
      <a class="btn" href="/"><span data-icon="upload"></span>Upload your own image</a>
      $report
    </div>
    <p class="page-note">This image is hosted free on $site. <a href="/">Upload your own image</a> to get a
      direct link in seconds, or read the guide to
      <a href="/image-hosting-for-forums">image hosting for forums</a>.</p>
  </main>
HTML;

    return layout([
        'title' => "{$file['name']} — Free Image Hosting · $site",
        'description' => $description,
        'canonical' => $pageUrl,
        // Only gallery images may appear in search results; unlisted links stay private
        'robots' => $file['in_gallery'] ? 'index, follow, max-image-preview:large' : 'noindex, nofollow',
        'head' => implode("\n  ", $head),
        'body' => $body,
        'script' => PAGE_SCRIPT,
    ]);
}

function message_page(string $title, string $message): string
{
    return layout([
        'title' => $title . ' · ' . site_name(),
        'description' => $message,
        'robots' => 'noindex',
        'body' => '<main class="empty-page"><h1>' . esc($title) . '</h1><p>' . esc($message)
            . '</p><a class="btn primary" href="/">Go to the gallery</a></main>',
    ]);
}

function not_found_page(): never
{
    html_out(message_page('Link not found', 'This link does not exist or the file was removed.'), 404);
}

// Terms of Use and Privacy Policy. Plain, general wording; review it for your situation.
function legal_page(string $which): string
{
    $site = esc(site_name());
    $email = contact_email();
    $contact = $email !== '' ? '<a href="mailto:' . esc($email) . '">' . esc($email) . '</a>' : 'the site owner';
    $updated = 'November 2026';

    if ($which === 'terms') {
        $title = 'Terms of Use';
        $description = "The rules for uploading and sharing images on $site.";
        $content = <<<HTML
<p>By uploading to or using $site, you agree to these terms.</p>
<h2>What you can upload</h2>
<p>Only upload images you own or have the right to share. You must not upload anything that is illegal,
including sexual content involving minors, content that infringes someone else's copyright or trademark,
malware, or content that harasses, threatens or exposes private information about others.</p>
<h2>Public and unlisted images</h2>
<p>Images shown in the gallery are public. Unlisted images don't appear in the gallery or in search
engines, but anyone with the link can open them. Don't upload anything private.</p>
<h2>Removal</h2>
<p>We may remove any upload at any time, for any reason, without notice. To report an image or request
removal of content you own, use the Report link on the image page or email $contact.
Copyright holders: include the link to the image and proof that you own the content.</p>
<h2>No guarantee</h2>
<p>$site is provided free and "as is". Uploads can be lost or become unavailable, so keep your own copies.
We are not responsible for content uploaded by users.</p>
<h2>Limits</h2>
<p>Uploads are rate-limited, and we may block anyone who abuses the service.</p>
HTML;
    } else {
        $title = 'Privacy Policy';
        $description = "What $site stores about you when you upload or view images.";
        $content = <<<HTML
<p>$site doesn't use accounts, advertising trackers or analytics cookies.</p>
<h2>What we store when you upload</h2>
<p>The image itself, its file name, size and dimensions, the upload time, whether it's public or unlisted,
and your IP address. The IP address is used to prevent abuse (upload limits) and to handle reports.</p>
<h2>Where images are stored</h2>
<p>Images are stored on our server and with Telegram, which we use as storage. Telegram processes the
files under its own privacy policy.</p>
<h2>Cookies</h2>
<p>Visitors get no cookies. Your browser remembers your "Show in public gallery" choice locally on your
device. The site administrator uses one sign-in cookie for the admin area.</p>
<h2>Third parties</h2>
<p>Pages load the Inter font from Google Fonts, which receives your IP address when the font loads.</p>
<h2>Removal and questions</h2>
<p>To have an image removed or to ask about your data, email $contact with the image's link.</p>
HTML;
    }

    $body = <<<HTML
<main class="legal-page">
    <h1>$title</h1>
    <p class="muted">Last updated: $updated</p>
    $content
  </main>
HTML;
    return layout([
        'title' => "$title · " . site_name(),
        'description' => $description,
        'canonical' => public_url() . "/$which",
        'head' => breadcrumb_ld([['Home', '/'], [$title, null]]),
        'body' => $body,
    ]);
}
