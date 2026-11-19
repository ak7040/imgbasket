<?php
// Telegram storage. Two ways to store files, chosen with 'storage' in config.php:
//   'saved_messages' (default) your own account's Saved Messages, via MadelineProto (storage_saved.php)
//   'bot'                      a private channel through a bot, via the Bot API (storage_bot.php)
// Each stored piece remembers which way it was stored (bot pieces have a Bot API file_id, Saved
// Messages pieces don't), so files keep working after switching.

require APP_DIR . '/storage_bot.php';
require APP_DIR . '/storage_saved.php';

// Thrown when Telegram says a stored message or file no longer exists: it was deleted in the
// Telegram app instead of through the admin, so the database row is stale.
class TelegramGone extends RuntimeException
{
}

function storage_driver(): string
{
    return config('storage', 'saved_messages') === 'bot' ? 'bot' : 'saved_messages';
}

// Files bigger than this are stored as several messages
function tg_part_size(): int
{
    return storage_driver() === 'bot' ? BOT_PART_SIZE : SAVED_PART_SIZE;
}

// Sends $length bytes of $path from $offset as one message
function tg_send_part(string $path, int $offset, int $length, string $filename, string $caption, string $mime): array
{
    $temp = null;
    $source = $path;
    if ($offset !== 0 || $length !== filesize($path)) {
        $temp = tempnam(storage_dir('tmp'), 'part');
        $in = fopen($path, 'rb');
        $out = fopen($temp, 'wb');
        stream_copy_to_stream($in, $out, $length, $offset);
        fclose($in);
        fclose($out);
        $source = $temp;
    }
    try {
        return storage_driver() === 'bot'
            ? bot_send_part($source, $filename, $caption)
            : saved_send_part($source, $filename, $caption, $mime);
    } finally {
        if ($temp) @unlink($temp);
    }
}

function chunk_is_bot(array $chunk): bool
{
    return (string) $chunk['tg_file_id'] !== '';
}

function file_chunks(array $file): array
{
    return q_all('SELECT * FROM chunks WHERE file_id = ? ORDER BY idx', [$file['id']]);
}

// Writes the whole file to $path
function tg_download_file(array $file, string $path): void
{
    $chunks = file_chunks($file);
    if (count($chunks) === 1 && !chunk_is_bot($chunks[0])) {
        saved_download_to((int) $chunks[0]['tg_message_id'], $path);
        return;
    }
    $out = fopen($path, 'wb');
    try {
        foreach ($chunks as $chunk) {
            if (chunk_is_bot($chunk)) {
                bot_download($chunk['tg_file_id'], fn(string $data) => fwrite($out, $data));
                continue;
            }
            $part = tempnam(storage_dir('tmp'), 'part');
            try {
                saved_download_to((int) $chunk['tg_message_id'], $part);
                $in = fopen($part, 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            } finally {
                @unlink($part);
            }
        }
    } finally {
        fclose($out);
    }
}

// Streams every stored piece of a file to the browser, in order
function tg_stream_chunks(array $chunks): void
{
    foreach ($chunks as $chunk) {
        if (chunk_is_bot($chunk)) {
            bot_download((string) $chunk['tg_file_id'], function (string $data): void {
                echo $data;
                flush();
            });
            continue;
        }
        saved_download_callable((int) $chunk['tg_message_id'], function (string $data, int $offset): void {
            echo $data;
            flush();
        });
    }
}

// Streams one Saved Messages file, or a byte range of it; $end is exclusive, -1 means the end
function tg_stream_range(int $messageId, int $start, int $end): void
{
    saved_download_callable($messageId, function (string $data, int $offset): void {
        echo $data;
        flush();
    }, $start, $end);
}

// Streams a stored thumbnail to the browser
function tg_stream_thumb(array $thumb): void
{
    if (chunk_is_bot($thumb)) {
        bot_download((string) $thumb['tg_file_id'], function (string $data): void {
            echo $data;
            flush();
        });
        return;
    }
    saved_download_callable((int) $thumb['tg_message_id'], function (string $data, int $offset): void {
        echo $data;
        flush();
    });
}

function tg_delete_chunk(array $chunk): void
{
    if (chunk_is_bot($chunk)) bot_delete_message((int) $chunk['tg_message_id']);
    else saved_delete_message((int) $chunk['tg_message_id']);
}

// Whether storage is set up and logged in. Cached for 5 minutes once it works.
function storage_status(bool $fresh = false): array
{
    $cache = storage_dir('state') . '/telegram-status.json';
    if (!$fresh && is_file($cache) && filemtime($cache) > time() - 300) {
        $saved = json_decode((string) file_get_contents($cache), true);
        if (($saved['status'] ?? '') === 'ready' && ($saved['driver'] ?? '') === storage_driver()) return $saved;
    }
    $status = (storage_driver() === 'bot' ? bot_status() : saved_status()) + ['driver' => storage_driver()];
    if ($status['status'] === 'ready') file_put_contents($cache, json_encode($status));
    elseif (is_file($cache)) unlink($cache);
    return $status;
}

function storage_ready(): bool
{
    return storage_status()['status'] === 'ready';
}

// Two-step verification password during the Saved Messages login
function storage_submit_password(string $password): array
{
    $error = storage_driver() === 'saved_messages' ? saved_submit_password($password) : 'Not needed for bot storage';
    $status = storage_status(true);
    if ($error !== null && $status['status'] !== 'ready') $status['error'] = $error;
    return $status;
}
