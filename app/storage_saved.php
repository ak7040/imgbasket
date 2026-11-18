<?php
// Saved Messages storage ('storage' => 'saved_messages'): logs in as your Telegram account with
// MadelineProto (https://docs.madelineproto.xyz) and keeps each file as one message in your
// Saved Messages. The login happens from the admin (QR code, then the 2FA password if set).

use danog\MadelineProto\API;
use danog\MadelineProto\Ipc\Runner\WebRunner;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;

// Telegram's limit is 2 GB per file for regular accounts (4 GB with Premium)
const SAVED_PART_SIZE = 2000 * 1024 * 1024;

function saved_session_dir(): string
{
    return storage_dir('telegram');
}

function saved_api(): API
{
    static $api = null;
    if ($api) return $api;

    $autoload = dirname(APP_DIR) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('The vendor/ folder is missing. Upload it with the site (it holds MadelineProto).');
    }
    require_once $autoload;
    saved_prepare_web_runner();

    $settings = new Settings;
    $settings->getAppInfo()
        ->setApiId((int) config('telegram.api_id'))
        ->setApiHash((string) config('telegram.api_hash'))
        // Shown in Telegram → Settings → Devices
        ->setDeviceModel(site_name() . ' server')
        ->setSystemVersion('PHP ' . PHP_VERSION)
        ->setAppVersion('1.0');
    $settings->getLogger()
        ->setType(Logger::FILE_LOGGER)
        ->setExtra(saved_session_dir() . '/MadelineProto.log')
        ->setLevel(Logger::LEVEL_WARNING)
        ->setMaxSize(5 * 1024 * 1024);

    $api = new API(saved_session_dir() . '/account.madeline', $settings);
    // MadelineProto installs an error handler that turns every warning (even @-silenced ones) into an
    // exception. The Telegram work happens in its worker; in our pages, keep PHP's normal handling.
    restore_error_handler();
    return $api;
}

// MadelineProto keeps one background worker connected to Telegram. Where proc_open is disabled (shared
// hosting such as Hostinger), it starts that worker by requesting its own entry.php over HTTP from this
// site. It would work out that URL from the current request, which our clean URLs (/api/upload,
// /admin/api/...) and the admin subdomain confuse, so give it the exact address on the main site.
// .htaccess lets only that one vendor file through.
function saved_prepare_web_runner(): void
{
    $url = parse_url(public_url());
    if (!empty($url['host'])) {
        $https = ($url['scheme'] ?? 'http') === 'https';
        $_SERVER['SERVER_NAME'] = $url['host'];
        $_SERVER['HTTPS'] = $https ? 'on' : 'off';
        $_SERVER['SERVER_PORT'] = (string) ($url['port'] ?? ($https ? 443 : 80));
    }
    $entry = rtrim((string) ($url['path'] ?? ''), '/') . '/vendor/danog/madelineproto/src/Ipc/Runner/entry.php';
    try {
        (new ReflectionProperty(WebRunner::class, 'runPath'))->setValue(null, $entry);
    } catch (Throwable $e) {
        // A newer MadelineProto may have renamed this; it then falls back to its own detection
        error_log('ImgBasket: could not set the MadelineProto web runner path: ' . $e->getMessage());
    }
}

function saved_status(): array
{
    $fail = fn(string $message) => ['status' => 'error', 'error' => $message];
    if (!config('telegram.api_id') || !config('telegram.api_hash')) {
        return $fail('Set telegram.api_id and telegram.api_hash in app/config.php. Get them at https://my.telegram.org/apps: log in with your phone number, open "API development tools", and create an app with any name.');
    }
    try {
        $api = saved_api();
        $state = $api->getAuthorization();
        if ($state === API::NOT_LOGGED_IN) {
            // Returns a QR code to scan, or null once the scan has happened
            $qr = $api->qrLogin();
            if ($qr) return ['status' => 'qr', 'qr' => 'data:image/svg+xml;base64,' . base64_encode($qr->getQRSvg(320, 2))];
            $state = $api->getAuthorization();
        }
        if ($state === API::WAITING_PASSWORD) return ['status' => 'password', 'hint' => ''];
        if ($state === API::LOGGED_IN) {
            $me = $api->getSelf() ?: [];
            return [
                'status' => 'ready',
                'user' => [
                    'name' => trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: 'Telegram account',
                    'username' => $me['username'] ?? null,
                ],
                'premium' => !empty($me['premium']),
            ];
        }
        return $fail("The Telegram login is in an unexpected state ($state). Delete the folder app/storage/telegram on the server and try again.");
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (str_contains($message, 'API_ID_INVALID') || str_contains($message, 'API_ID_PUBLISHED_FLOOD')) {
            return $fail('Telegram rejected telegram.api_id / api_hash in app/config.php. Copy them again from https://my.telegram.org/apps.');
        }
        return $fail("Could not connect to Telegram: $message");
    }
}

function saved_submit_password(string $password): ?string
{
    try {
        saved_api()->complete2faLogin($password);
        return null;
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'PASSWORD_HASH_INVALID') ? 'Wrong password' : $e->getMessage();
    }
}

// Sends one file (or piece of one) to Saved Messages
function saved_send_part(string $path, string $filename, string $caption, string $mime): array
{
    $message = saved_api()->sendDocument(
        peer: 'me',
        file: new LocalFile($path),
        caption: mb_substr($caption, 0, 1000),
        fileName: $filename,
        mimeType: $mime,
    );
    return ['message_id' => (int) $message->id, 'file_id' => ''];
}

// The stored message, fresh from Telegram (file references expire, so it's fetched every time)
function saved_message(int $messageId): array
{
    $result = saved_api()->messages->getMessages(id: [$messageId]);
    $message = $result['messages'][0] ?? null;
    if (!$message || ($message['_'] ?? '') === 'messageEmpty' || empty($message['media'])) {
        throw new RuntimeException("Message $messageId is no longer in Saved Messages");
    }
    return $message;
}

function saved_download_to(int $messageId, string $path): void
{
    saved_api()->downloadToFile(saved_message($messageId), $path);
}

// Streams a large file to the browser (with range support), with MadelineProto writing the headers
function saved_stream(int $messageId, array $file): void
{
    saved_api()->downloadToBrowser(
        saved_message($messageId),
        size: (int) $file['size'],
        name: $file['name'],
        mime: $file['mime'] ?: 'application/octet-stream',
    );
}

function saved_delete_message(int $messageId): void
{
    try {
        saved_api()->messages->deleteMessages(revoke: true, id: [$messageId]);
    } catch (Throwable $e) {
        // Not fatal: the record is gone either way; the message just stays in Saved Messages
        error_log("Could not delete Saved Messages message $messageId: " . $e->getMessage());
    }
}
