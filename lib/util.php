<?php
/**
 * Generic helpers: HTTP client, JSON I/O, escaping, channel lookup.
 */
declare(strict_types=1);

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out($data, int $status = 200, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $k => $v) header($k . ': ' . $v);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? getallheaders()['authorization'] ?? '') : '');
    if ($h && preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m)) return $m[1];
    return null;
}

/**
 * Small cURL wrapper.
 * Returns ['status'=>int, 'headers'=>array(lowercase=>value), 'body'=>string]
 */
function http_request(string $method, string $url, array $opts = []): array
{
    $ch = curl_init();
    $headers = $opts['headers'] ?? [];
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $opts['follow'] ?? false,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => $opts['timeout'] ?? 60,
        CURLOPT_USERAGENT => 'YT-Connector/' . APP_VERSION,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        },
    ]);
    if (strtoupper($method) === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
    if (array_key_exists('json', $opts)) {
        $headers[] = 'Content-Type: application/json; charset=utf-8';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } elseif (array_key_exists('form', $opts)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif (array_key_exists('body', $opts)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    } elseif (empty($opts['file']) && (strtoupper($method) === 'POST' || strtoupper($method) === 'PUT')) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    }
    if (!empty($opts['file'])) {
        // stream a file range as the request body
        [$fh, $len] = $opts['file'];
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $fh);
        curl_setopt($ch, CURLOPT_INFILESIZE, $len);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    }
    if (!empty($opts['sink'])) {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FILE, $opts['sink']);
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false && $err) {
        throw new RuntimeException('HTTP request failed: ' . $err);
    }
    return ['status' => $status, 'headers' => $respHeaders, 'body' => is_string($body) ? $body : ''];
}

/** Public shape of a channel row (never exposes secrets). */
function channel_public(array $c): array
{
    $stats = $c['stats_json'] ? json_decode($c['stats_json'], true) : null;
    return [
        'id' => (int)$c['id'],
        'youtube_channel_id' => $c['yt_channel_id'],
        'title' => $c['title'],
        'handle' => $c['handle'],
        'thumbnail' => $c['thumbnail'],
        'ai_access' => $c['ai_access'],
        'color' => $c['color'],
        'status' => $c['status'],
        'last_error' => $c['last_error'],
        'notes' => $c['notes'],
        'stats' => $stats,
        'has_refresh_token' => !empty($c['refresh_token_enc']),
        'client_id_hint' => $c['client_id'] ? substr($c['client_id'], 0, 12) . '…' : null,
        'created_at' => (int)$c['created_at'],
        'updated_at' => (int)$c['updated_at'],
    ];
}

function channel_get(int $id): ?array
{
    return db_one('SELECT * FROM channels WHERE id = ?', [$id]);
}

/**
 * Finds a channel by internal id, YouTube channel id, @handle or title.
 * When $ref is empty and only one channel exists, returns it.
 */
function channel_resolve($ref): array
{
    $ref = is_string($ref) ? trim($ref) : $ref;
    if ($ref === null || $ref === '') {
        $all = db_all('SELECT * FROM channels ORDER BY id');
        if (count($all) === 1) return $all[0];
        if (!$all) throw new UserError('No channels are configured yet. Add one from the dashboard.');
        throw new UserError('Several channels exist — pass the "channel" argument (id, YouTube channel id, @handle or title). Use list_channels to see them.');
    }
    if (is_int($ref) || ctype_digit((string)$ref)) {
        $c = channel_get((int)$ref);
        if ($c) return $c;
    }
    $r = (string)$ref;
    $c = db_one('SELECT * FROM channels WHERE yt_channel_id = ?', [$r])
        ?? db_one('SELECT * FROM channels WHERE LOWER(handle) = LOWER(?) OR LOWER(handle) = LOWER(?)', [$r, '@' . ltrim($r, '@')])
        ?? db_one('SELECT * FROM channels WHERE LOWER(title) = LOWER(?)', [$r]);
    if (!$c) throw new UserError('Channel not found: ' . $r);
    return $c;
}

class UserError extends RuntimeException {}

function str_limit(?string $s, int $n): string
{
    $s = (string)$s;
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
}

function iso8601_duration_seconds(?string $d): int
{
    if (!$d) return 0;
    try {
        $i = new DateInterval($d);
        return $i->d * 86400 + $i->h * 3600 + $i->i * 60 + $i->s;
    } catch (Throwable $e) {
        return 0;
    }
}
