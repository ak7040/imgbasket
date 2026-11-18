<?php
// One-time server check: can this hosting run MadelineProto (Telegram user-account login, needed
// for Saved Messages storage)? Upload to public_html, open it in the browser, send the result,
// then DELETE this file. It shows server settings, so don't leave it online.
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$checks = [];
$add = function (string $name, bool $ok, string $detail, bool $required = true) use (&$checks) {
    $checks[] = [$name, $ok, $detail, $required];
};

$add('PHP 8.2 or newer', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);
$add('64-bit PHP', PHP_INT_SIZE === 8, (PHP_INT_SIZE * 8) . '-bit');
foreach (['json', 'xml', 'dom', 'mbstring', 'openssl', 'zlib', 'fileinfo', 'filter', 'hash'] as $ext) {
    $add("extension: $ext", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing');
}
foreach (['gmp', 'ffi', 'sockets', 'posix', 'pcntl'] as $ext) {
    $add("extension: $ext (speeds things up)", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing', false);
}

$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
foreach (['proc_open', 'proc_get_status', 'proc_close', 'shell_exec', 'exec', 'set_time_limit', 'ignore_user_abort', 'fsockopen', 'stream_socket_client'] as $fn) {
    $usable = function_exists($fn) && !in_array($fn, $disabled, true);
    $add("function: $fn", $usable, $usable ? 'allowed' : 'disabled', in_array($fn, ['stream_socket_client', 'ignore_user_abort'], true));
}

// MadelineProto keeps a background process connected to Telegram between page loads
$spawn = 'not tried';
if (function_exists('proc_open') && !in_array('proc_open', $disabled, true)) {
    $p = @proc_open([PHP_BINARY ?: 'php', '-r', 'echo "ok";'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($p)) {
        $out = stream_get_contents($pipes[1]);
        proc_close($p);
        $spawn = trim((string) $out) === 'ok' ? 'works' : 'started but no output';
    } else {
        $spawn = 'blocked';
    }
}
$add('can start a background PHP process', $spawn === 'works', $spawn . ' (PHP binary: ' . (PHP_BINARY ?: 'unknown') . ')', false);

// MTProto talks to Telegram's data centers directly on port 443 (not through the HTTP Bot API)
foreach (['149.154.167.50' => 'DC2', '149.154.175.53' => 'DC1', '149.154.167.91' => 'DC4'] as $ip => $dc) {
    $t = microtime(true);
    $s = @stream_socket_client("tcp://$ip:443", $errno, $errstr, 5);
    $ok = is_resource($s);
    if ($ok) fclose($s);
    $add("connect to Telegram $dc ($ip:443)", $ok, $ok ? round((microtime(true) - $t) * 1000) . ' ms' : "failed: $errstr");
}

$mem = (string) ini_get('memory_limit');
$memBytes = (int) $mem * match (strtolower(substr($mem, -1))) { 'g' => 1 << 30, 'm' => 1 << 20, 'k' => 1 << 10, default => 1 };
$add('memory_limit at least 256M', $mem === '-1' || $memBytes >= 256 << 20, $mem);
$add('max_execution_time', true, ini_get('max_execution_time') . ' s', false);
$add('upload_max_filesize / post_max_size', true, ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size'), false);

$tmp = sys_get_temp_dir();
$add('temp folder writable', is_writable($tmp), $tmp);
$add('this folder writable', is_writable(__DIR__), __DIR__);

$failedRequired = 0;
echo "ImgBasket server check for Saved Messages storage (MadelineProto)\n";
echo str_repeat('=', 66) . "\n";
foreach ($checks as [$name, $ok, $detail, $required]) {
    if (!$ok && $required) $failedRequired++;
    printf("%-4s %-46s %s\n", $ok ? 'OK' : ($required ? 'FAIL' : 'warn'), $name, $detail);
}
echo str_repeat('=', 66) . "\n";
echo $failedRequired === 0
    ? "RESULT: looks compatible. Send this page to your developer, then delete tg-check.php.\n"
    : "RESULT: $failedRequired required check(s) failed. Send this page to your developer, then delete tg-check.php.\n";
