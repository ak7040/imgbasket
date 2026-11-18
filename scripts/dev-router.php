<?php
// Router for PHP's built-in server (scripts/dev.sh). Mirrors .htaccess: real files are served,
// app/ and dotfiles are forbidden, everything else goes to index.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/app(/|$)|/\.#', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) return false;
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
