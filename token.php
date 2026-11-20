<?php
/** OAuth token endpoint (authorization_code + PKCE, refresh_token rotation) and revocation. */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/oauth.php';
cors_headers();
if (!is_installed()) oauth_error('temporarily_unavailable', 'Not installed', 503);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') oauth_error('invalid_request', 'POST required', 405);

$in = $_POST;
if (!$in && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'json') !== false) $in = input_json();

// client authentication: Basic header or body params
$clientId = (string)($in['client_id'] ?? '');
$clientSecret = isset($in['client_secret']) ? (string)$in['client_secret'] : null;
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Basic\s+(.+)$/i', $auth, $m)) {
    $dec = base64_decode($m[1], true);
    if ($dec !== false && strpos($dec, ':') !== false) {
        [$cid, $sec] = explode(':', $dec, 2);
        $clientId = urldecode($cid);
        $clientSecret = urldecode($sec);
    }
}
$client = $clientId ? oauth_client($clientId) : null;
if (!$client) oauth_error('invalid_client', 'Unknown client', 401);
if (!empty($client['client_secret_hash'])) {
    if ($clientSecret === null || !hash_equals($client['client_secret_hash'], token_hash($clientSecret))) {
        oauth_error('invalid_client', 'Client authentication failed', 401);
    }
}

if (($_GET['action'] ?? '') === 'revoke') {
    $tok = (string)($in['token'] ?? '');
    $row = db_one('SELECT * FROM oauth_tokens WHERE token_hash = ? AND client_id = ?', [token_hash($tok), $clientId]);
    if ($row) db_exec('UPDATE oauth_tokens SET revoked = 1 WHERE family = ?', [$row['family']]);
    http_response_code(200);
    exit;
}

oauth_cleanup();
$grant = (string)($in['grant_type'] ?? '');

if ($grant === 'authorization_code') {
    $code = (string)($in['code'] ?? '');
    $row = db_one('SELECT * FROM oauth_codes WHERE code_hash = ?', [token_hash($code)]);
    if (!$row || (int)$row['expires'] < time() || $row['client_id'] !== $clientId) oauth_error('invalid_grant', 'Invalid or expired code');
    db_exec('DELETE FROM oauth_codes WHERE code_hash = ?', [$row['code_hash']]); // single use
    if (isset($in['redirect_uri']) && $in['redirect_uri'] !== $row['redirect_uri']) oauth_error('invalid_grant', 'redirect_uri mismatch');
    $verifier = (string)($in['code_verifier'] ?? '');
    if ($verifier === '' || !hash_equals($row['code_challenge'], b64url(hash('sha256', $verifier, true)))) {
        oauth_error('invalid_grant', 'PKCE verification failed');
    }
    $tokens = oauth_issue_tokens($clientId);
    log_activity('oauth', 'token_issued', null, ['client' => $client['name']]);
    json_out($tokens);
}

if ($grant === 'refresh_token') {
    $rt = (string)($in['refresh_token'] ?? '');
    $row = db_one("SELECT * FROM oauth_tokens WHERE token_hash = ? AND kind = 'refresh'", [token_hash($rt)]);
    if (!$row || $row['client_id'] !== $clientId || (int)$row['expires'] < time()) oauth_error('invalid_grant', 'Invalid refresh token');
    if ((int)$row['revoked'] === 1) {
        // reuse of a rotated refresh token → revoke the whole family (token theft protection),
        // but tolerate a short grace window for clients that retry concurrently
        if ((int)$row['last_used'] < time() - 30) {
            db_exec('UPDATE oauth_tokens SET revoked = 1 WHERE family = ?', [$row['family']]);
            oauth_error('invalid_grant', 'Refresh token already used');
        }
    }
    db_exec('UPDATE oauth_tokens SET revoked = 1, last_used = ? WHERE id = ?', [time(), $row['id']]);
    // old access tokens of this family stay valid until they expire
    json_out(oauth_issue_tokens($clientId, $row['family']));
}

oauth_error('unsupported_grant_type', 'Use authorization_code or refresh_token');
