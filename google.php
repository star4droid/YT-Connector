<?php
/**
 * Google OAuth helper: obtains a refresh token for a channel automatically.
 *  - google.php?channel=ID   → redirects to Google consent
 *  - google.php?code=…&state=… (Google redirect back) → stores refresh token
 * Authorized redirect URI to register in Google Cloud:  {SITE_URL}/google.php
 */
require __DIR__ . '/lib/bootstrap.php';
require_admin_page();

$redirect = base_url() . '/google.php';

if (isset($_GET['error'])) {
    header('Location: index.php#/channels?google_error=' . urlencode((string)$_GET['error']));
    exit;
}

if (isset($_GET['code'])) {
    $state = (string)($_GET['state'] ?? '');
    $expected = $_SESSION['g_state'] ?? null;
    if (!$expected || !hash_equals($expected['s'], $state)) {
        http_response_code(400);
        exit('Invalid state. Please start the authorization again from the dashboard.');
    }
    unset($_SESSION['g_state']);
    $c = channel_get((int)$expected['c']);
    if (!$c) exit('Channel not found');
    $r = http_request('POST', 'https://oauth2.googleapis.com/token', ['form' => [
        'code' => (string)$_GET['code'],
        'client_id' => $c['client_id'],
        'client_secret' => decrypt_str($c['client_secret_enc']),
        'redirect_uri' => $redirect,
        'grant_type' => 'authorization_code',
    ]]);
    $d = json_decode($r['body'], true) ?: [];
    if (empty($d['access_token'])) {
        $msg = ($d['error'] ?? 'error') . ': ' . ($d['error_description'] ?? '');
        db_update('channels', ['status' => 'error', 'last_error' => $msg, 'updated_at' => time()], 'id = ?', [$c['id']]);
        header('Location: index.php#/channels?google_error=' . urlencode($msg));
        exit;
    }
    $upd = [
        'access_token_enc' => encrypt_str($d['access_token']),
        'token_expires' => time() + (int)($d['expires_in'] ?? 3600),
        'updated_at' => time(),
    ];
    if (!empty($d['refresh_token'])) $upd['refresh_token_enc'] = encrypt_str($d['refresh_token']);
    db_update('channels', $upd, 'id = ?', [$c['id']]);
    try {
        $yt = YouTube::forId((int)$c['id']);
        $yt->syncChannel();
        log_activity('web', 'channel_authorized', (int)$c['id'], ['title' => $yt->ch['title']]);
    } catch (Throwable $e) {
        db_update('channels', ['status' => 'error', 'last_error' => $e->getMessage()], 'id = ?', [$c['id']]);
    }
    if (empty($d['refresh_token']) && empty($c['refresh_token_enc'])) {
        header('Location: index.php#/channels?google_error=' . urlencode('Google did not return a refresh token. Remove the app from https://myaccount.google.com/permissions and try again.'));
        exit;
    }
    header('Location: index.php#/channel/' . (int)$c['id']);
    exit;
}

$c = channel_get((int)($_GET['channel'] ?? 0));
if (!$c) { http_response_code(404); exit('Channel not found'); }
$state = random_token(18);
$_SESSION['g_state'] = ['s' => $state, 'c' => (int)$c['id']];
$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => $c['client_id'],
    'redirect_uri' => $redirect,
    'response_type' => 'code',
    'scope' => implode(' ', GOOGLE_SCOPES),
    'access_type' => 'offline',
    'prompt' => 'consent select_account',
    'include_granted_scopes' => 'true',
    'state' => $state,
]);
header('Location: ' . $url);
