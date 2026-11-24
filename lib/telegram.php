<?php
/**
 * Telegram Bot API integration: the AI can send messages, photos, videos, documents,
 * albums, read incoming messages and call any Bot API method through the user's bot.
 * The bot token is stored encrypted and is never revealed to the AI.
 */
declare(strict_types=1);

const TG_API = 'https://api.telegram.org';
const TG_MAX_UPLOAD = 50 * 1024 * 1024;     // Bot API limit for files sent by bots
const TG_MAX_DOWNLOAD = 20 * 1024 * 1024;   // Bot API limit for getFile
const TG_MEDIA_TYPES = ['photo', 'video', 'document', 'audio', 'animation', 'voice', 'video_note', 'sticker'];
const TG_BLOCKED_METHODS = ['logout', 'close'];

function tg_token(): ?string
{
    $enc = (string)setting('tg_token_enc', '');
    return $enc !== '' ? decrypt_str($enc) : null;
}

function tg_enabled(): bool
{
    return setting('tg_enabled', '1') === '1' && (bool)tg_token();
}

function tg_known_chats(): array
{
    return json_decode((string)setting('tg_chats', '[]'), true) ?: [];
}

function tg_allowed_list(): array
{
    $raw = trim((string)setting('tg_allowed', ''));
    if ($raw === '') return [];
    return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw))));
}

function tg_settings_public(): array
{
    $bot = json_decode((string)setting('tg_bot', 'null'), true);
    return [
        'has_token' => (bool)tg_token(),
        'enabled' => setting('tg_enabled', '1') === '1',
        'bot' => $bot,
        'default_chat' => (string)setting('tg_default_chat', ''),
        'allowed' => (string)setting('tg_allowed', ''),
        'chats' => tg_known_chats(),
    ];
}

/** Low-level Bot API call. $files: [field => ['path'=>..., 'name'=>..., 'mime'=>...]] */
function tg_api(string $method, array $params = [], array $files = [], ?string $tokenOverride = null)
{
    $token = $tokenOverride ?: tg_token();
    if (!$token) throw new UserError('No Telegram bot token yet. Add it in the dashboard → Telegram.');
    if (in_array(strtolower($method), TG_BLOCKED_METHODS, true)) throw new UserError('This method is blocked because it would disconnect the bot.');
    if (!preg_match('/^[A-Za-z]+$/', $method)) throw new UserError('Invalid method name.');
    $ch = curl_init(TG_API . '/bot' . $token . '/' . $method);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $files ? 600 : 60, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_POST => true];
    if ($files) {
        $post = [];
        foreach (array_filter($params, fn($v) => $v !== null) as $k => $v) $post[$k] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v);
        foreach ($files as $field => $f) $post[$field] = new CURLFile($f['path'], $f['mime'] ?? 'application/octet-stream', $f['name'] ?? basename($f['path']));
        $opts[CURLOPT_POSTFIELDS] = $post;
    } else {
        $params = array_filter($params, fn($v) => $v !== null);
        $opts[CURLOPT_POSTFIELDS] = json_encode((object)$params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new UserError('Could not reach Telegram: ' . $err);
    $d = json_decode((string)$body, true);
    if (!is_array($d)) throw new UserError('Unexpected Telegram response.');
    if (empty($d['ok'])) {
        $desc = (string)($d['description'] ?? 'unknown error');
        if (stripos($desc, 'chat not found') !== false) $desc .= ' — the user must first send /start to the bot (or add it to the group / as channel admin).';
        if (stripos($desc, 'conflict') !== false) $desc .= ' — this bot has a webhook; reading updates is not possible while a webhook is set.';
        throw new UserError('Telegram: ' . $desc);
    }
    return $d['result'];
}

/** Resolves the target chat (default chat when empty) and enforces the allow-list. */
function tg_chat($chat): string
{
    $chat = trim((string)($chat ?? ''));
    if ($chat === '') $chat = (string)setting('tg_default_chat', '');
    if ($chat === '') throw new UserError('No chat given and no default chat set. Use telegram_chats to see known chats, or set a default in the dashboard.');
    // allow names of known chats
    if (!preg_match('/^-?\d+$/', $chat) && $chat[0] !== '@') {
        foreach (tg_known_chats() as $c) {
            if (strcasecmp((string)($c['title'] ?? ''), $chat) === 0 || strcasecmp('@' . ($c['username'] ?? ''), '@' . ltrim($chat, '@')) === 0) { $chat = (string)$c['id']; break; }
        }
    }
    $allowed = tg_allowed_list();
    if ($allowed && !in_array($chat, $allowed, true)) {
        throw new UserError('Chat ' . $chat . ' is not in the allowed list (dashboard → Telegram).');
    }
    return $chat;
}

/** Remembers chats seen in updates so the AI can address them by name. */
function tg_remember_chats(array $chats): void
{
    $known = [];
    foreach (tg_known_chats() as $c) $known[(string)$c['id']] = $c;
    foreach ($chats as $c) {
        if (empty($c['id'])) continue;
        $known[(string)$c['id']] = [
            'id' => $c['id'],
            'type' => $c['type'] ?? null,
            'title' => $c['title'] ?? trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
            'username' => $c['username'] ?? null,
            'seen_at' => time(),
        ];
    }
    uasort($known, fn($a, $b) => ($b['seen_at'] ?? 0) <=> ($a['seen_at'] ?? 0));
    setting_set('tg_chats', json_encode(array_values(array_slice($known, 0, 100, true)), JSON_UNESCAPED_UNICODE));
}

/** Compact view of an incoming message. */
function tg_message_public(array $m): array
{
    $out = [
        'message_id' => $m['message_id'] ?? null,
        'chat' => ['id' => $m['chat']['id'] ?? null, 'type' => $m['chat']['type'] ?? null, 'title' => $m['chat']['title'] ?? trim(($m['chat']['first_name'] ?? '') . ' ' . ($m['chat']['last_name'] ?? ''))],
        'from' => isset($m['from']) ? trim(($m['from']['first_name'] ?? '') . ' ' . ($m['from']['last_name'] ?? '')) . (isset($m['from']['username']) ? ' (@' . $m['from']['username'] . ')' : '') : ($m['author_signature'] ?? null),
        'date' => isset($m['date']) ? gmdate('c', (int)$m['date']) : null,
        'text' => $m['text'] ?? ($m['caption'] ?? null),
    ];
    foreach (['photo', 'video', 'document', 'audio', 'voice', 'animation', 'video_note', 'sticker'] as $k) {
        if (empty($m[$k])) continue;
        $f = $k === 'photo' ? end($m[$k]) : $m[$k];
        $out['file'] = [
            'type' => $k,
            'file_id' => $f['file_id'] ?? null,
            'file_name' => $f['file_name'] ?? null,
            'mime' => $f['mime_type'] ?? null,
            'size' => $f['file_size'] ?? null,
            'width' => $f['width'] ?? null,
            'height' => $f['height'] ?? null,
            'duration' => $f['duration'] ?? null,
        ];
    }
    if (isset($m['reply_to_message']['message_id'])) $out['reply_to'] = $m['reply_to_message']['message_id'];
    return array_filter($out, fn($v) => $v !== null && $v !== '');
}

/** Reads recent updates. When $ack is true, they are confirmed so they are not returned again. */
function tg_updates(int $limit = 30, bool $ack = false): array
{
    $offset = (int)setting('tg_offset', '0');
    $res = tg_api('getUpdates', ['offset' => $offset ?: null, 'limit' => max(1, min(100, $limit)), 'timeout' => 0, 'allowed_updates' => ['message', 'channel_post', 'edited_message', 'my_chat_member', 'callback_query']]);
    $msgs = [];
    $chats = [];
    $maxId = 0;
    foreach ($res as $u) {
        $maxId = max($maxId, (int)$u['update_id']);
        $m = $u['message'] ?? $u['channel_post'] ?? $u['edited_message'] ?? null;
        if ($m) { $chats[] = $m['chat']; $msgs[] = tg_message_public($m) + ['update_id' => $u['update_id']]; }
        if (isset($u['my_chat_member']['chat'])) $chats[] = $u['my_chat_member']['chat'];
        if (isset($u['callback_query'])) {
            $cq = $u['callback_query'];
            if (isset($cq['message']['chat'])) $chats[] = $cq['message']['chat'];
            $msgs[] = ['update_id' => $u['update_id'], 'button_pressed' => $cq['data'] ?? null, 'from' => $cq['from']['first_name'] ?? null, 'chat' => ['id' => $cq['message']['chat']['id'] ?? null]];
        }
    }
    if ($chats) tg_remember_chats($chats);
    if ($ack && $maxId) setting_set('tg_offset', (string)($maxId + 1));
    return $msgs;
}

/** Inline keyboard from a simple description: [[{"text":"Open","url":"https://…"}],[{"text":"Yes","data":"yes"}]] */
function tg_keyboard($rows): ?array
{
    if (!$rows || !is_array($rows)) return null;
    $kb = [];
    foreach ($rows as $row) {
        $r = [];
        foreach ((array)$row as $b) {
            if (!is_array($b) || empty($b['text'])) continue;
            $btn = ['text' => (string)$b['text']];
            if (!empty($b['url'])) $btn['url'] = (string)$b['url'];
            else $btn['callback_data'] = mb_substr((string)($b['data'] ?? $b['text']), 0, 64);
            $r[] = $btn;
        }
        if ($r) $kb[] = $r;
    }
    return $kb ? ['inline_keyboard' => $kb] : null;
}

function tg_common(array $a): array
{
    $p = [];
    if (!empty($a['silent'])) $p['disable_notification'] = true;
    if (!empty($a['protect_content'])) $p['protect_content'] = true;
    if (!empty($a['reply_to'])) $p['reply_parameters'] = ['message_id' => (int)$a['reply_to'], 'allow_sending_without_reply' => true];
    if (!empty($a['thread_id'])) $p['message_thread_id'] = (int)$a['thread_id'];
    if ($kb = tg_keyboard($a['buttons'] ?? null)) $p['reply_markup'] = $kb;
    $pm = $a['parse_mode'] ?? 'HTML';
    if ($pm && $pm !== 'none') $p['parse_mode'] = $pm;
    return $p;
}

function tg_sent_public($r): array
{
    if (isset($r[0])) return ['ok' => true, 'messages' => array_map(fn($m) => ['message_id' => $m['message_id'], 'chat_id' => $m['chat']['id']], $r)];
    return ['ok' => true, 'message_id' => $r['message_id'] ?? null, 'chat_id' => $r['chat']['id'] ?? null, 'date' => isset($r['date']) ? gmdate('c', (int)$r['date']) : null];
}

/**
 * Resolves a media source to either a string (URL / file_id) or a local file to upload.
 * Returns [value|null, file|null, cleanup paths]
 */
function tg_media_source(array $a, string $type): array
{
    if (!empty($a['file_id'])) return [(string)$a['file_id'], null, []];
    if (!empty($a['upload_id'])) {
        $u = upload_get((int)$a['upload_id']);
        if (!$u || $u['source'] !== 'tg') throw new UserError('upload_id not found (create it with telegram_file_start).');
        if ($u['status'] !== 'ready') throw new UserError('The file is not complete yet (' . $u['received'] . ' / ' . $u['size'] . ' bytes received).');
        return [null, ['path' => $u['path'], 'name' => $u['filename'] ?: 'file', 'mime' => $u['mime'] ?: 'application/octet-stream'], [$u]];
    }
    if (!empty($a['base64'])) {
        $bin = base64_decode(preg_replace('#^data:[^,]*,#', '', (string)$a['base64']), true);
        if ($bin === false || $bin === '') throw new UserError('base64 is not valid.');
        $tmp = tempnam(staging_dir(), 'tg');
        file_put_contents($tmp, $bin);
        return [null, ['path' => $tmp, 'name' => (string)($a['filename'] ?? ($type . '.bin')), 'mime' => 'application/octet-stream'], [$tmp]];
    }
    if (!empty($a['url'])) {
        $url = (string)$a['url'];
        if (!preg_match('#^https?://#i', $url)) throw new UserError('url must be http(s).');
        if (empty($a['download_first'])) return [$url, null, []];
        // download on the server, then upload (for URLs Telegram cannot fetch, or files > 20 MB)
        $tmp = tempnam(staging_dir(), 'tg');
        $fh = fopen($tmp, 'wb');
        $r = http_request('GET', $url, ['follow' => true, 'sink' => $fh, 'timeout' => 600]);
        fclose($fh);
        if ($r['status'] >= 400) { @unlink($tmp); throw new UserError('Download failed (HTTP ' . $r['status'] . ').'); }
        if (filesize($tmp) > TG_MAX_UPLOAD) { @unlink($tmp); throw new UserError('File is larger than 50 MB (Telegram bot limit).'); }
        $name = (string)($a['filename'] ?? (basename((string)parse_url($url, PHP_URL_PATH)) ?: $type));
        return [null, ['path' => $tmp, 'name' => $name, 'mime' => 'application/octet-stream'], [$tmp]];
    }
    throw new UserError('Give url, file_id, upload_id or base64.');
}

function tg_cleanup(array $items): void
{
    foreach ($items as $it) {
        if (is_array($it)) upload_discard($it, 'done');
        elseif (is_string($it)) @unlink($it);
    }
}

function tg_send_media(array $a): array
{
    $type = in_array($a['type'] ?? 'document', TG_MEDIA_TYPES, true) ? $a['type'] : 'document';
    $chat = tg_chat($a['chat'] ?? null);
    [$value, $file, $cleanup] = tg_media_source($a, $type);
    $p = tg_common($a) + ['chat_id' => $chat];
    if (isset($a['caption']) && $type !== 'sticker' && $type !== 'video_note') $p['caption'] = mb_substr((string)$a['caption'], 0, 1024);
    else unset($p['parse_mode']);
    if ($type === 'video') $p['supports_streaming'] = true;
    if (!empty($a['spoiler']) && in_array($type, ['photo', 'video', 'animation'], true)) $p['has_spoiler'] = true;
    $method = 'send' . str_replace(' ', '', ucwords(str_replace('_', ' ', $type)));
    try {
        if ($file) $r = tg_api($method, $p, [$type => $file]);
        else $r = tg_api($method, $p + [$type => $value]);
    } catch (Throwable $e) {
        // temp copies go away; a staged upload stays (until it expires) so the send can be retried
        tg_cleanup(array_filter($cleanup, 'is_string'));
        throw $e;
    }
    tg_cleanup($cleanup); // staged file deleted right after a successful send
    return tg_sent_public($r);
}

/** Sends 2-10 photos/videos/documents/audios as one album. */
function tg_send_album(array $a): array
{
    $chat = tg_chat($a['chat'] ?? null);
    $items = array_values((array)($a['items'] ?? []));
    if (count($items) < 2 || count($items) > 10) throw new UserError('An album needs 2 to 10 items.');
    $media = []; $files = []; $cleanup = [];
    try {
        foreach ($items as $i => $it) {
            $type = in_array($it['type'] ?? 'photo', ['photo', 'video', 'document', 'audio'], true) ? $it['type'] : 'photo';
            [$value, $file, $cl] = tg_media_source((array)$it, $type);
            $cleanup = array_merge($cleanup, $cl);
            $m = ['type' => $type, 'media' => $file ? 'attach://f' . $i : $value];
            if ($file) $files['f' . $i] = $file;
            if (!empty($it['caption'])) { $m['caption'] = mb_substr((string)$it['caption'], 0, 1024); $m['parse_mode'] = $a['parse_mode'] ?? 'HTML'; }
            $media[] = $m;
        }
        $p = ['chat_id' => $chat, 'media' => $media];
        if (!empty($a['silent'])) $p['disable_notification'] = true;
        $r = tg_api('sendMediaGroup', $p, $files);
    } catch (Throwable $e) {
        tg_cleanup(array_filter($cleanup, 'is_string'));
        throw $e;
    }
    tg_cleanup($cleanup);
    return tg_sent_public($r);
}

/** Downloads a file the bot received (≤ 20 MB) into a temp file. Returns [path, info]. */
function tg_download(string $fileId): array
{
    $f = tg_api('getFile', ['file_id' => $fileId]);
    if (empty($f['file_path'])) throw new UserError('Telegram did not return a file path (files over 20 MB cannot be downloaded by bots).');
    $tmp = tempnam(staging_dir(), 'tgdl');
    $fh = fopen($tmp, 'wb');
    $r = http_request('GET', TG_API . '/file/bot' . tg_token() . '/' . $f['file_path'], ['sink' => $fh, 'timeout' => 300]);
    fclose($fh);
    if ($r['status'] !== 200) { @unlink($tmp); throw new UserError('Download from Telegram failed (HTTP ' . $r['status'] . ').'); }
    return [$tmp, ['file_path' => $f['file_path'], 'size' => filesize($tmp), 'name' => basename($f['file_path'])]];
}
