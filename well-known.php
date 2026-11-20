<?php
/** OAuth discovery documents (RFC 8414 + RFC 9728). Reached via .htaccess rewrites from /.well-known/… */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/oauth.php';
cors_headers();
if (!is_installed()) json_out(['error' => 'not_installed'], 503);

$base = base_url();
$type = $_GET['type'] ?? 'as';

if ($type === 'pr') {
    $path = preg_replace('/[^a-z.]/', '', (string)($_GET['path'] ?? 'mcp')) ?: 'mcp';
    if (!empty($_SERVER['REQUEST_URI']) && preg_match('#oauth-protected-resource/(.+)$#', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m)) {
        $path = preg_replace('/[^a-z.]/', '', $m[1]) ?: 'mcp';
    }
    json_out([
        'resource' => $base . '/' . $path,
        'authorization_servers' => [$base],
        'scopes_supported' => ['youtube'],
        'bearer_methods_supported' => ['header'],
        'resource_name' => 'YT Connector',
        'resource_documentation' => 'https://github.com/star4droid/YT-Connector',
    ]);
}

json_out([
    'issuer' => $base,
    'authorization_endpoint' => $base . '/authorize.php',
    'token_endpoint' => $base . '/token.php',
    'registration_endpoint' => $base . '/register.php',
    'revocation_endpoint' => $base . '/token.php?action=revoke',
    'scopes_supported' => ['youtube'],
    'response_types_supported' => ['code'],
    'response_modes_supported' => ['query'],
    'grant_types_supported' => ['authorization_code', 'refresh_token'],
    'code_challenge_methods_supported' => ['S256'],
    'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
    'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
    'service_documentation' => 'https://github.com/star4droid/YT-Connector',
]);
