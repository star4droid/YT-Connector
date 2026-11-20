<?php
/**
 * Raw binary upload endpoint for files the AI sends from its own sandbox
 * (created by the `upload_file_start` tool). Authenticated by the one-time token.
 *
 *   curl -X PUT --data-binary @video.mp4 "https://SITE/upload.php?t=TOKEN&offset=0"
 *
 * Send large files in several requests, each with offset = bytes already received.
 * When the last byte arrives the YouTube upload starts; the staged file is deleted
 * as soon as YouTube confirms the video.
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/oauth.php';
cors_headers();
if (!is_installed()) json_out(['error' => 'not_installed'], 503);

$token = (string)($_GET['t'] ?? '');
$u = $token !== '' ? upload_by_token($token) : null;
if (!$u || $u['source'] !== 'mcp' || (int)$u['expires'] < time()) json_out(['error' => 'Invalid or expired upload token'], 404);

$method = $_SERVER['REQUEST_METHOD'];
try {
    if ($method === 'GET') json_out(['upload' => upload_public($u)]);
    if ($method !== 'PUT' && $method !== 'POST') json_out(['error' => 'Use PUT (or POST) with the raw file bytes as the body'], 405);

    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : (int)$u['received'];
    if (!empty($_FILES)) {
        // multipart fallback: curl -F "file=@video.mp4"
        $f = reset($_FILES);
        if (empty($f['tmp_name'])) throw new UserError('No file received (check upload_max_filesize).');
        $u = upload_append($u, $offset, $f['tmp_name']);
    } else {
        $in = fopen('php://input', 'rb');
        $u = upload_append_stream($u, $offset, $in);
        fclose($in);
    }
    if ($u['status'] === 'ready') {
        log_activity('mcp', 'file_received', (int)$u['channel_id'], ['upload' => (int)$u['id'], 'file' => $u['filename'], 'bytes' => (int)$u['size']]);
        json_out(['upload' => upload_step((int)$u['id'], 25), 'next' => 'Call upload_status with upload_id until status is "done".']);
    }
    json_out(['upload' => upload_public($u), 'next_offset' => (int)$u['received']]);
} catch (UserError $e) {
    json_out(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('upload.php: ' . $e);
    json_out(['error' => 'Server error: ' . $e->getMessage()], 500);
}
