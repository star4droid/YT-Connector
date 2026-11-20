<?php
/** Dynamic Client Registration (RFC 7591). */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/oauth.php';
cors_headers();
if (!is_installed()) oauth_error('temporarily_unavailable', 'Not installed', 503);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') oauth_error('invalid_request', 'POST required', 405);
if (setting('allow_dcr', '1') !== '1') oauth_error('access_denied', 'Dynamic client registration is disabled on this server. Create a client in the dashboard (Connector page) instead.', 403);

$in = input_json();
$redirects = array_values(array_filter((array)($in['redirect_uris'] ?? []), 'is_string'));
if (!$redirects) oauth_error('invalid_redirect_uri', 'redirect_uris is required');
foreach ($redirects as $r) {
    $p = parse_url($r);
    $scheme = strtolower($p['scheme'] ?? '');
    $host = $p['host'] ?? '';
    if ($scheme === 'https') $ok = $host !== '';
    elseif ($scheme === 'http') $ok = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    else $ok = $scheme !== '' && !in_array($scheme, ['javascript', 'data', 'file', 'vbscript'], true); // native app schemes
    if (!$ok) oauth_error('invalid_redirect_uri', 'Invalid redirect URI: ' . $r);
}
$method = $in['token_endpoint_auth_method'] ?? 'none';
if (!in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) $method = 'none';

// limit runaway registrations
$recent = db_one('SELECT COUNT(*) AS c FROM oauth_clients WHERE dynamic = 1 AND created_at > ?', [time() - 3600]);
if ((int)$recent['c'] > 30) oauth_error('temporarily_unavailable', 'Too many registrations, try later', 429);

$name = (string)($in['client_name'] ?? 'MCP client');
$c = oauth_create_client($name, $redirects, $method !== 'none', true);
log_activity('oauth', 'client_registered', null, ['client' => $name, 'redirects' => $redirects]);

$resp = [
    'client_id' => $c['client_id'],
    'client_id_issued_at' => time(),
    'client_name' => $name,
    'redirect_uris' => $redirects,
    'grant_types' => ['authorization_code', 'refresh_token'],
    'response_types' => ['code'],
    'token_endpoint_auth_method' => $method,
    'scope' => 'youtube',
];
if ($c['client_secret']) {
    $resp['client_secret'] = $c['client_secret'];
    $resp['client_secret_expires_at'] = 0;
}
json_out($resp, 201);
