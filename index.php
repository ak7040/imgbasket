<?php
// Front controller: .htaccess sends every request that isn't a real file here.
// Serves the public site, and the admin either at /admin or on its own subdomain (admin_host).
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/admin_api.php';
require APP_DIR . '/site_api.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$adminApi = false;

try {
    // One address for search engines: www.example.com redirects to example.com (the PUBLIC_URL host)
    $canonicalHost = parse_url(public_url(), PHP_URL_HOST);
    if ($canonicalHost && request_host() === 'www.' . $canonicalHost) {
        header('Location: ' . public_url() . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }

    if (on_admin_host()) {
        // Admin subdomain: the admin page at /, its API at /api/..., nothing else
        header('X-Robots-Tag: noindex, nofollow');
        if ($path === '/robots.txt') {
            header('Content-Type: text/plain; charset=utf-8');
            exit("User-agent: *\nDisallow: /\n");
        }
        serve_admin_asset($path);
        if ($path === '/' || $path === '/index.html') html_out(render_view('admin.html', ['HEAD' => '', 'FOOTER' => '']));
        if (str_starts_with($path, '/api/')) {
            $adminApi = true;
            admin_route($method, $path);
        }
        html_out(message_page('Not found', 'There is nothing here.'), 404);
    }

    if (str_starts_with($path, '/admin')) {
        // With an admin subdomain, the main site has no admin at all
        if (admin_host() !== '') html_out(message_page('Not found', 'There is nothing here.'), 404);
        header('X-Robots-Tag: noindex, nofollow');
        // The admin page uses relative API links, so it must be opened as /admin/
        if ($path === '/admin') {
            header('Location: /admin/', true, 301);
            exit;
        }
        if ($path === '/admin/' || $path === '/admin/index.html') html_out(render_view('admin.html', ['HEAD' => '', 'FOOTER' => '']));
        if (str_starts_with($path, '/admin/api/')) {
            $adminApi = true;
            admin_route($method, substr($path, strlen('/admin')));
        }
    }
    site_route($method, $path);
} catch (Throwable $e) {
    error_log('ImgBasket: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (headers_sent()) exit;
    // Only the signed-in admin sees the real error; visitors get a generic one
    if ($adminApi) json_out(['error' => $e->getMessage()], 500);
    if (str_starts_with($path, '/api/')) json_out(['error' => 'Something went wrong. Try again.'], 500);
    html_out(message_page('Something went wrong', 'Please try again in a moment.'), 500);
}
