<?php
declare(strict_types=1);

const APP_DIR = __DIR__;

date_default_timezone_set('UTC');

if (!is_file(APP_DIR . '/config.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Setup needed: copy app/config.sample.php to app/config.php and fill it in.\n");
}
$GLOBALS['CONFIG'] = require APP_DIR . '/config.php';

// config('telegram.bot_token') reads nested keys
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['CONFIG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}

require APP_DIR . '/db.php';
require APP_DIR . '/telegram.php';
require APP_DIR . '/storage.php';
require APP_DIR . '/images.php';
require APP_DIR . '/videos.php';
require APP_DIR . '/pages.php';
require APP_DIR . '/auth.php';

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function html_out(string $html, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

function json_body(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '{}', true);
    return is_array($data) ? $data : [];
}

function client_ip(): string
{
    $header = (string) config('ip_header', '');
    if ($header !== '' && !empty($_SERVER[$header])) {
        return trim(explode(',', (string) $_SERVER[$header])[0]);
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function new_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
}

function public_url(): string
{
    $url = (string) config('public_url', '');
    if ($url === '') $url = (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return rtrim($url, '/');
}

// Host name of this request, lowercase, without the port
function request_host(): string
{
    return strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
}

// The admin's own host name (e.g. admin.imgbasket.com), or '' to keep the admin at /admin
function admin_host(): string
{
    return strtolower(trim((string) config('admin_host', '')));
}

function on_admin_host(): bool
{
    return admin_host() !== '' && request_host() === admin_host();
}

// URL path the admin lives under: "/" on its subdomain, "/admin/" otherwise
function admin_base(): string
{
    return on_admin_host() ? '/' : '/admin/';
}

// The admin page's own files. Normally the web server sends them directly; this covers a
// subdomain whose folder is separate from the main site (see extras/admin-subdomain).
function serve_admin_asset(string $path): void
{
    $types = [
        '/assets/admin/app.js' => 'application/javascript',
        '/assets/admin/style.css' => 'text/css',
        '/icons.js' => 'application/javascript',
        '/favicon.ico' => 'image/x-icon',
        '/favicon.svg' => 'image/svg+xml',
        '/icon-192.png' => 'image/png',
        '/icon-512.png' => 'image/png',
        '/icon-maskable-192.png' => 'image/png',
        '/icon-maskable-512.png' => 'image/png',
        '/apple-touch-icon.png' => 'image/png',
        '/site.webmanifest' => 'application/manifest+json',
        '/logo-mark.png' => 'image/png',
        '/site.css' => 'text/css',
    ];
    if (!isset($types[$path])) return;
    $file = dirname(APP_DIR) . $path;
    header('Content-Type: ' . $types[$path] . '; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

function site_name(): string
{
    return (string) config('site_name', 'ImgBasket');
}

// Static files get a ?v=<mtime> query so a deploy never leaves a stale browser or CDN copy in place.
// Hostinger's CDN caches compressed variants separately and does not always purge them on deploy.
function asset_url(string $path): string
{
    $file = dirname(APP_DIR) . $path;
    $mtime = is_file($file) ? filemtime($file) : false;
    return $path . ($mtime !== false ? '?v=' . $mtime : '');
}

// Bytes from php.ini notation like "128M"
function ini_bytes(string $key): int
{
    $v = trim((string) ini_get($key));
    if ($v === '' || $v === '-1' || $v === '0') return PHP_INT_MAX;
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

// Largest upload PHP will accept on this server
function php_upload_limit(): int
{
    return min(ini_bytes('upload_max_filesize'), ini_bytes('post_max_size'));
}

function format_size(int|float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    $n = number_format($bytes, $i && $bytes < 100 ? 1 : 0, '.', '');
    if (str_contains($n, '.')) $n = rtrim(rtrim($n, '0'), '.');
    return $n . ' ' . $units[$i];
}
