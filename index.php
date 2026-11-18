<?php
// Front controller: .htaccess sends every request that isn't a real file here.
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/admin_api.php';
require APP_DIR . '/site_api.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

try {
    // One address for search engines: www.example.com redirects to example.com (the PUBLIC_URL host)
    $canonicalHost = parse_url(public_url(), PHP_URL_HOST);
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($canonicalHost && $host === 'www.' . $canonicalHost) {
        header('Location: ' . public_url() . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
    // Keep the admin out of search engines
    if (str_starts_with($path, '/admin')) header('X-Robots-Tag: noindex, nofollow');

    // The admin page uses relative links, so it must be opened as /admin/
    if ($path === '/admin') {
        header('Location: /admin/', true, 301);
        exit;
    }
    if ($path === '/admin/' || $path === '/admin/index.html') html_out(render_view('admin.html', ['HEAD' => '', 'FOOTER' => '']));
    if (str_starts_with($path, '/admin/api/')) admin_route($method, substr($path, strlen('/admin')));
    site_route($method, $path);
} catch (Throwable $e) {
    error_log('ImgBasket: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (headers_sent()) exit;
    // Only the signed-in admin sees the real error; visitors get a generic one
    if (str_starts_with($path, '/admin/api/')) json_out(['error' => $e->getMessage()], 500);
    if (str_starts_with($path, '/api/')) json_out(['error' => 'Something went wrong. Try again.'], 500);
    html_out(message_page('Something went wrong', 'Please try again in a moment.'), 500);
}
