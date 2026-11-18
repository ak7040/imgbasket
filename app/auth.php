<?php
// Admin password: a signed cookie that lasts 30 days. No PHP sessions, so nothing depends on
// the host's session storage.

const ADMIN_COOKIE = 'imgbasket_admin';
const ADMIN_MAX_AGE = 30 * 86400;

// Random secret kept in storage, mixed with the password so changing it signs everyone out
function admin_key(): string
{
    $path = storage_dir('state') . '/admin-secret.key';
    if (!is_file($path)) {
        file_put_contents($path, random_bytes(32));
        @chmod($path, 0600);
    }
    return hash('sha256', file_get_contents($path) . admin_password(), true);
}

function admin_password(): string
{
    return (string) config('admin_password', '');
}

function admin_sign(string $value): string
{
    return rtrim(strtr(base64_encode(hash_hmac('sha256', $value, admin_key(), true)), '+/', '-_'), '=');
}

function is_admin(): bool
{
    if (admin_password() === '') return false;
    [$expires, $signature] = array_pad(explode('.', (string) ($_COOKIE[ADMIN_COOKIE] ?? ''), 2), 2, '');
    if ($expires === '' || (int) $expires < time()) return false;
    return hash_equals(admin_sign($expires), $signature);
}

function set_admin_cookie(string $value, int $maxAge): void
{
    setcookie(ADMIN_COOKIE, $value, [
        'expires' => $maxAge ? time() + $maxAge : time() - 3600,
        'path' => '/admin',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

// POST /admin/api/admin/login { password }: at most 10 attempts per IP every 15 minutes
function admin_login(): never
{
    if (admin_password() === '') json_out(['error' => 'Set admin_password in app/config.php first'], 503);
    $ip = client_ip();
    if (!allow_event('login', $ip, 10, 900)) json_out(['error' => 'Too many attempts. Try again in 15 minutes.'], 429);
    $password = (string) (json_body()['password'] ?? '');
    if (!hash_equals(hash('sha256', admin_password()), hash('sha256', $password))) {
        json_out(['error' => 'Wrong password'], 403);
    }
    clear_events('login', $ip);
    $expires = (string) (time() + ADMIN_MAX_AGE);
    set_admin_cookie($expires . '.' . admin_sign($expires), ADMIN_MAX_AGE);
    json_out(storage_status());
}

function admin_logout(): never
{
    set_admin_cookie('', 0);
    json_out(['status' => 'admin']);
}
