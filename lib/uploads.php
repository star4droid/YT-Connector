<?php
/**
 * Upload jobs: files are staged on the server (from the browser, a one-time link,
 * or a public URL) and then pushed to YouTube in resumable chunks so that no
 * single PHP request runs longer than the hosting limits allow.
 *
 * Status flow:  waiting_file → receiving → ready → uploading → done
 *               (url)  downloading → ready …          ↘ error
 */
declare(strict_types=1);

const YT_CHUNK = 8 * 1024 * 1024;      // must be a multiple of 256 KB
const DL_CHUNK = 32 * 1024 * 1024;

function staging_dir(): string
{
    if (!is_dir(STAGING_DIR)) @mkdir(STAGING_DIR, 0775, true);
    return STAGING_DIR;
}

function upload_get(int $id): ?array
{
    return db_one('SELECT * FROM uploads WHERE id = ?', [$id]);
}

function upload_by_token(string $token): ?array
{
    return db_one('SELECT * FROM uploads WHERE token = ?', [$token]);
}

function upload_create(int $channelId, string $source, array $meta = [], array $extra = []): array
{
    $token = random_token(24);
    $id = db_insert('uploads', array_merge([
        'token' => $token,
        'channel_id' => $channelId,
        'source' => $source,
        'status' => $source === 'url' ? 'downloading' : ($source === 'link' ? 'waiting_file' : 'receiving'),
        'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        'path' => staging_dir() . '/' . $token . '.bin',
        'expires' => time() + 86400 * 2,
        'created_at' => time(),
        'updated_at' => time(),
    ], $extra));
    return upload_get($id);
}

function upload_public(array $u): array
{
    $meta = $u['meta'] ? json_decode($u['meta'], true) : [];
    $total = (int)$u['size'];
    $pct = 0;
    if ($total > 0) {
        if (in_array($u['status'], ['receiving', 'downloading', 'waiting_file'], true)) $pct = (int)floor($u['received'] / $total * 100);
        elseif ($u['status'] === 'uploading') $pct = (int)floor(min($u['yt_sent'], $total) / $total * 100);
        elseif ($u['status'] === 'done') $pct = 100;
    }
    return [
        'upload_id' => (int)$u['id'],
        'channel_id' => (int)$u['channel_id'],
        'status' => $u['status'],
        'source' => $u['source'],
        'filename' => $u['filename'],
        'size' => $total,
        'received' => (int)$u['received'],
        'sent_to_youtube' => min((int)$u['yt_sent'], $total),
        'progress_percent' => $pct,
        'video_id' => $u['video_id'],
        'video_url' => $u['video_id'] ? 'https://youtu.be/' . $u['video_id'] : null,
        'studio_url' => $u['video_id'] ? 'https://studio.youtube.com/video/' . $u['video_id'] . '/edit' : null,
        'title' => $meta['title'] ?? null,
        'notes' => $meta['result_notes'] ?? [],
        'awaiting_metadata' => !empty($meta['await_metadata']) && empty($meta['title']),
        'error' => $u['error'],
        'upload_page' => $u['source'] === 'link' ? base_url() . '/drop.php?t=' . $u['token'] : null,
        'created_at' => (int)$u['created_at'],
        'updated_at' => (int)$u['updated_at'],
    ];
}

function upload_set(int $id, array $data): array
{
    $data['updated_at'] = time();
    db_update('uploads', $data, 'id = ?', [$id]);
    return upload_get($id);
}

/** Appends a chunk sent from the browser. */
function upload_append(array $u, int $offset, string $tmpPath): array
{
    if (!in_array($u['status'], ['receiving', 'waiting_file'], true)) throw new UserError('This upload is not accepting data (status: ' . $u['status'] . ').');
    clearstatcache();
    $have = is_file($u['path']) ? filesize($u['path']) : 0;
    if ($offset !== $have) {
        return upload_set((int)$u['id'], ['received' => $have]); // client will resync from `received`
    }
    $in = fopen($tmpPath, 'rb');
    $out = fopen($u['path'], 'ab');
    if (!$in || !$out) throw new RuntimeException('Cannot write staging file');
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    clearstatcache();
    $have = filesize($u['path']);
    $data = ['received' => $have, 'status' => 'receiving'];
    if ($u['size'] > 0 && $have > (int)$u['size']) {
        upload_discard($u, 'error');
        throw new UserError('Received more bytes than the declared size — upload cancelled. Start again with the exact size.');
    }
    if ($u['size'] > 0 && $have >= (int)$u['size']) $data['status'] = 'ready';
    return upload_set((int)$u['id'], $data);
}

/** Appends a base64 chunk (sent by the AI through the MCP tool). */
function upload_append_base64(array $u, int $offset, string $b64): array
{
    $b64 = preg_replace('#^data:[^,]*,#', '', trim($b64));
    $bin = base64_decode(preg_replace('/\s+/', '', $b64), true);
    if ($bin === false || $bin === '') throw new UserError('data_base64 is not valid base64.');
    $tmp = tempnam(staging_dir(), 'chunk');
    file_put_contents($tmp, $bin);
    try {
        $u = upload_append($u, $offset, $tmp);
    } finally {
        @unlink($tmp);
    }
    if ((int)$u['received'] !== $offset + strlen($bin) && $u['status'] !== 'ready') {
        throw new UserError('Offset mismatch: the server has ' . $u['received'] . ' bytes. Resend from offset ' . $u['received'] . '.');
    }
    return $u;
}

/** Appends a raw stream (HTTP PUT/POST body) to a staged upload. */
function upload_append_stream(array $u, int $offset, $in): array
{
    $tmp = tempnam(staging_dir(), 'chunk');
    $out = fopen($tmp, 'wb');
    stream_copy_to_stream($in, $out);
    fclose($out);
    try {
        if (filesize($tmp) === 0) throw new UserError('Empty request body.');
        return upload_append($u, $offset, $tmp);
    } finally {
        @unlink($tmp);
    }
}

/** Deletes the staged file(s) and marks the job as finished with the given status. */
function upload_discard(array $u, string $status = 'cancelled'): void
{
    @unlink($u['path']);
    $meta = json_decode($u['meta'] ?: '{}', true) ?: [];
    if (!empty($meta['thumbnail_path'])) @unlink($meta['thumbnail_path']);
    db_update('uploads', ['status' => $status, 'updated_at' => time()], 'id = ?', [$u['id']]);
}

/**
 * Advances a job for up to $budget seconds. Safe to call repeatedly.
 */
function upload_step(int $id, int $budget = 25): array
{
    @set_time_limit(max(60, $budget + 60));
    ignore_user_abort(true);
    $deadline = time() + $budget;
    $u = upload_get($id);
    if (!$u) throw new UserError('Upload not found');

    try {
        while (time() < $deadline) {
            $u = upload_get($id);
            switch ($u['status']) {
                case 'downloading':
                    $u = upload_download_step($u, $deadline);
                    if ($u['status'] === 'downloading') continue 2;
                    break;
                case 'ready':
                    $meta = json_decode($u['meta'] ?: '{}', true) ?: [];
                    if (empty($meta['title'])) {
                        // a file dropped via link without metadata: wait for upload_video/publish call
                        if (!empty($meta['await_metadata'])) return upload_public($u);
                        $meta['title'] = pathinfo((string)$u['filename'], PATHINFO_FILENAME) ?: 'Untitled';
                    }
                    $yt = YouTube::forId((int)$u['channel_id']);
                    $session = $yt->startUpload($meta, (int)$u['size'], $u['mime'] ?: 'video/*');
                    $u = upload_set($id, ['yt_session' => $session, 'yt_sent' => 0, 'status' => 'uploading']);
                    break;
                case 'uploading':
                    $yt = YouTube::forId((int)$u['channel_id']);
                    $res = $yt->pushChunk($u['yt_session'], $u['path'], (int)$u['yt_sent'], (int)$u['size'], YT_CHUNK);
                    if ($res['done']) {
                        $u = upload_finish($u, $yt, $res['video'] ?? []);
                        return upload_public($u);
                    }
                    $u = upload_set($id, ['yt_sent' => $res['sent']]);
                    break;
                default:
                    return upload_public($u);
            }
        }
    } catch (UserError $e) {
        $u = upload_set($id, ['status' => 'error', 'error' => $e->getMessage()]);
        // files sent by the AI are never kept on the server: it can resend them
        if ($u['source'] === 'mcp') upload_discard($u, 'error');
        log_activity('system', 'upload_failed', (int)$u['channel_id'], ['upload' => $id, 'error' => $e->getMessage()], false);
    } catch (Throwable $e) {
        // transient: keep status so the next step can resume
        upload_set($id, ['error' => 'Temporary problem, will retry: ' . $e->getMessage()]);
    }
    return upload_public(upload_get($id));
}

function upload_download_step(array $u, int $deadline): array
{
    $id = (int)$u['id'];
    $url = (string)$u['source_url'];
    if ((int)$u['size'] === 0 && (int)$u['received'] === 0) {
        $head = http_request('HEAD', $url, ['follow' => true, 'timeout' => 30]);
        $len = (int)($head['headers']['content-length'] ?? 0);
        $mime = $head['headers']['content-type'] ?? 'video/*';
        if (stripos($mime, 'text/html') !== false) {
            throw new UserError('The URL returned an HTML page, not a video file. Use a direct download link.');
        }
        $ranges = stripos($head['headers']['accept-ranges'] ?? '', 'bytes') !== false;
        $u = upload_set($id, ['size' => $len, 'mime' => explode(';', $mime)[0], 'error' => null]);
        if (!$len || !$ranges) {
            // no range support: download in one go
            @set_time_limit(0);
            $fh = fopen($u['path'], 'wb');
            $r = http_request('GET', $url, ['follow' => true, 'sink' => $fh, 'timeout' => 3000]);
            fclose($fh);
            if ($r['status'] >= 400) throw new UserError('Download failed with HTTP ' . $r['status']);
            clearstatcache();
            $size = filesize($u['path']);
            if ($size < 1024) throw new UserError('Downloaded file is too small to be a video.');
            return upload_set($id, ['size' => $size, 'received' => $size, 'status' => 'ready']);
        }
    }
    while (time() < $deadline) {
        $from = (int)$u['received'];
        $total = (int)$u['size'];
        if ($from >= $total) return upload_set($id, ['status' => 'ready']);
        $to = min($from + DL_CHUNK, $total) - 1;
        $fh = fopen($u['path'], $from === 0 ? 'wb' : 'ab');
        $r = http_request('GET', $url, ['follow' => true, 'sink' => $fh, 'timeout' => 600, 'headers' => ['Range: bytes=' . $from . '-' . $to]]);
        fclose($fh);
        if ($r['status'] !== 206 && $r['status'] !== 200) throw new UserError('Download failed with HTTP ' . $r['status']);
        clearstatcache();
        $have = filesize($u['path']);
        if ($r['status'] === 200) $have = min($have, $total); // server ignored Range — whole file written
        $u = upload_set($id, ['received' => $have]);
        if ($have >= $total) return upload_set($id, ['status' => 'ready']);
    }
    return $u;
}

function upload_finish(array $u, YouTube $yt, array $video): array
{
    $id = (int)$u['id'];
    $videoId = $video['id'] ?? null;
    $meta = json_decode($u['meta'] ?: '{}', true) ?: [];
    $notes = [];
    if ($videoId) {
        // optional thumbnail
        try {
            $bytes = null;
            if (!empty($meta['thumbnail_path']) && is_file($meta['thumbnail_path'])) $bytes = file_get_contents($meta['thumbnail_path']);
            elseif (!empty($meta['thumbnail_url'])) $bytes = fetch_image_bytes($meta['thumbnail_url']);
            elseif (!empty($meta['thumbnail_base64'])) $bytes = base64_decode(preg_replace('#^data:[^,]+,#', '', $meta['thumbnail_base64']), true) ?: null;
            if ($bytes) { $yt->setThumbnail($videoId, $bytes); $notes[] = 'thumbnail set'; }
        } catch (Throwable $e) { $notes[] = 'thumbnail failed: ' . $e->getMessage(); }
        // optional playlist
        if (!empty($meta['playlist_id'])) {
            try {
                $yt->api('POST', '/playlistItems', ['part' => 'snippet'], ['snippet' => [
                    'playlistId' => $meta['playlist_id'],
                    'resourceId' => ['kind' => 'youtube#video', 'videoId' => $videoId],
                ]]);
                $notes[] = 'added to playlist';
            } catch (Throwable $e) { $notes[] = 'playlist failed: ' . $e->getMessage(); }
        }
    }
    @unlink($u['path']);
    if (!empty($meta['thumbnail_path'])) @unlink($meta['thumbnail_path']);
    log_activity('system', 'video_uploaded', (int)$u['channel_id'], ['video_id' => $videoId, 'title' => $meta['title'] ?? null]);
    $meta['result_notes'] = $notes;
    $fails = array_values(array_filter($notes, fn($n) => strpos($n, 'failed') !== false));
    return upload_set($id, [
        'status' => 'done',
        'video_id' => $videoId,
        'yt_sent' => (int)$u['size'],
        'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        'error' => $fails ? implode('; ', $fails) : null,
    ]);
}

/** Downloads an image (thumbnail) from a URL with a size cap. */
function fetch_image_bytes(string $url): string
{
    if (!preg_match('#^https?://#i', $url)) throw new UserError('Image URL must start with http(s)://');
    $r = http_request('GET', $url, ['follow' => true, 'timeout' => 60]);
    if ($r['status'] >= 400) throw new UserError('Could not download image (HTTP ' . $r['status'] . ')');
    if (strlen($r['body']) > 2 * 1024 * 1024) throw new UserError('Image is larger than 2 MB.');
    return $r['body'];
}

/** Removes expired staged files. Called opportunistically. */
function uploads_cleanup(): void
{
    foreach (db_all("SELECT * FROM uploads WHERE expires < ? AND status NOT IN ('done','expired','cancelled')", [time()]) as $u) {
        @unlink($u['path']);
        $meta = json_decode($u['meta'] ?: '{}', true) ?: [];
        if (!empty($meta['thumbnail_path'])) @unlink($meta['thumbnail_path']);
        db_update('uploads', ['status' => 'expired', 'updated_at' => time()], 'id = ?', [$u['id']]);
    }
}
