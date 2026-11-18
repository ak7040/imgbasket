<?php
// Router for PHP's built-in server (scripts/dev.sh). Mirrors .htaccess: real files are served,
// app/, vendor/ (except MadelineProto's worker entry point), composer files and dotfiles are
// forbidden, everything else goes to index.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$entry = '/vendor/danog/madelineproto/src/Ipc/Runner/entry.php';
if ($path === $entry && ($_GET['MadelineSelfRestart'] ?? '') === '1') return false;
if (preg_match('#^/(app|vendor)(/|$)|^/composer\.(json|lock)$|/\.#', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) return false;
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
