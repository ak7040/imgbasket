<?php
// Telegram Bot API: files are stored as documents in a private channel the bot administers.
// https://core.telegram.org/bots/api

// Bots can only download files up to 20 MB, so files are stored in pieces just under that
const CHUNK_SIZE = 19 * 1024 * 1024;

function tg_base(): string
{
    return rtrim((string) config('telegram.api_base', 'https://api.telegram.org'), '/');
}

function tg_token(): string
{
    return (string) config('telegram.bot_token', '');
}

function tg_call(string $method, array $params = [], bool $multipart = false): array
{
    for ($attempt = 0; ; $attempt++) {
        $ch = curl_init(tg_base() . '/bot' . tg_token() . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $multipart ? $params : http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 600,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        if ($raw === false) throw new RuntimeException("Could not reach Telegram: $error");

        $json = json_decode((string) $raw, true);
        if (($json['ok'] ?? false) === true) return is_array($json['result']) ? $json['result'] : ['value' => $json['result']];

        // Flood control: wait as long as Telegram asks, then retry
        $retry = $json['parameters']['retry_after'] ?? null;
        if ($retry && $attempt < 5) {
            sleep((int) $retry + 1);
            continue;
        }
        throw new RuntimeException("Telegram $method failed: " . ($json['description'] ?? 'unknown error'));
    }
}

// Sends one piece of a file ($length bytes from $offset) to the channel
function tg_send_part(string $path, int $offset, int $length, string $filename, string $caption): array
{
    $temp = null;
    $source = $path;
    if ($offset !== 0 || $length !== filesize($path)) {
        $temp = tempnam(sys_get_temp_dir(), 'imgbasket');
        $in = fopen($path, 'rb');
        $out = fopen($temp, 'wb');
        stream_copy_to_stream($in, $out, $length, $offset);
        fclose($in);
        fclose($out);
        $source = $temp;
    }
    try {
        $msg = tg_call('sendDocument', [
            'chat_id' => config('telegram.channel_id'),
            'document' => new CURLFile($source, 'application/octet-stream', $filename),
            'caption' => mb_substr($caption, 0, 1000),
            'disable_content_type_detection' => 'true',
        ], true);
        return ['message_id' => (int) $msg['message_id'], 'file_id' => (string) $msg['document']['file_id']];
    } finally {
        if ($temp) @unlink($temp);
    }
}

// Streams one stored piece; $write receives the bytes as they arrive
function tg_download(string $fileId, callable $write): void
{
    $info = tg_call('getFile', ['file_id' => $fileId]);
    $ch = curl_init(tg_base() . '/file/bot' . tg_token() . '/' . $info['file_path']);
    curl_setopt_array($ch, [
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FAILONERROR => true,
        CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($write): int {
            $write($data);
            return strlen($data);
        },
    ]);
    $ok = curl_exec($ch);
    $error = curl_error($ch);
    if (!$ok) throw new RuntimeException("Download from Telegram failed: $error");
}

function tg_delete_message(int $messageId): void
{
    try {
        tg_call('deleteMessage', ['chat_id' => config('telegram.channel_id'), 'message_id' => $messageId]);
    } catch (Throwable $e) {
        // Not fatal: the record is gone either way; the message just stays in the channel
        error_log("Could not delete Telegram message $messageId: " . $e->getMessage());
    }
}

// Checks the bot and channel setup. Cached for 5 minutes once it works.
function storage_status(bool $fresh = false): array
{
    $cache = storage_dir('state') . '/telegram-status.json';
    if (!$fresh && is_file($cache) && filemtime($cache) > time() - 300) {
        $saved = json_decode((string) file_get_contents($cache), true);
        if (($saved['status'] ?? '') === 'ready') return $saved;
    }

    $fail = fn(string $message) => ['status' => 'error', 'error' => $message];
    if (tg_token() === '') return $fail('Set telegram.bot_token in app/config.php. Create a bot with @BotFather on Telegram.');

    try {
        $me = tg_call('getMe');
    } catch (Throwable $e) {
        return $fail('Telegram rejected the bot token in app/config.php. ' . $e->getMessage());
    }

    $channel = (string) config('telegram.channel_id', '');
    if ($channel === '') {
        $chats = [];
        try {
            foreach (tg_call('getUpdates') as $update) {
                $chat = ($update['channel_post'] ?? $update['message'] ?? $update['my_chat_member'] ?? [])['chat'] ?? null;
                if ($chat) $chats[$chat['id']] = $chat['id'] . ' (' . ($chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? $chat['type']) . ')';
            }
        } catch (Throwable) {
        }
        return $fail($chats
            ? 'Set telegram.channel_id in app/config.php. Chats your bot can see: ' . implode(', ', $chats) . '.'
            : "Set telegram.channel_id in app/config.php. To find it, add @{$me['username']} to your private channel as an admin, post any message there, then press Try again.");
    }

    try {
        $chat = tg_call('getChat', ['chat_id' => $channel]);
        $member = tg_call('getChatMember', ['chat_id' => $channel, 'user_id' => $me['id']]);
    } catch (Throwable $e) {
        return $fail("The bot can't open channel $channel. Check telegram.channel_id and that @{$me['username']} was added to the channel. " . $e->getMessage());
    }
    if (!in_array($member['status'] ?? '', ['administrator', 'creator'], true)) {
        return $fail("Make @{$me['username']} an admin of the channel, with permission to post and delete messages.");
    }
    if (($member['can_post_messages'] ?? true) === false || ($member['can_delete_messages'] ?? true) === false) {
        return $fail("Give @{$me['username']} the channel permissions to post messages and delete messages.");
    }

    $status = ['status' => 'ready', 'user' => ['name' => $chat['title'] ?? 'Telegram channel', 'username' => $me['username']]];
    file_put_contents($cache, json_encode($status));
    return $status;
}

function storage_ready(): bool
{
    return storage_status()['status'] === 'ready';
}
