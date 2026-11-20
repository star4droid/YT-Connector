<?php
/**
 * Minimal OAuth 2.1 authorization server used by Claude custom connectors:
 * dynamic client registration (RFC 7591), authorization code + PKCE (S256),
 * refresh-token rotation, bearer validation for the MCP endpoint.
 */
declare(strict_types=1);

const CLAUDE_CALLBACKS = [
    'https://claude.ai/api/mcp/auth_callback',
    'https://claude.com/api/mcp/auth_callback',
];

function oauth_client(string $clientId): ?array
{
    return db_one('SELECT * FROM oauth_clients WHERE client_id = ?', [$clientId]);
}

function oauth_client_redirects(array $client): array
{
    $list = json_decode($client['redirect_uris'] ?: '[]', true) ?: [];
    return $list ?: CLAUDE_CALLBACKS;
}

function oauth_redirect_allowed(array $client, string $uri): bool
{
    foreach (oauth_client_redirects($client) as $r) {
        if (hash_equals($r, $uri)) return true;
        // loopback clients may use any port (RFC 8252)
        $pr = parse_url($r);
        $pu = parse_url($uri);
        if (($pr['host'] ?? '') === ($pu['host'] ?? '-') && in_array($pu['host'] ?? '', ['127.0.0.1', 'localhost'], true)
            && ($pr['path'] ?? '') === ($pu['path'] ?? '')) return true;
    }
    return false;
}

function oauth_create_client(string $name, array $redirects, bool $confidential, bool $dynamic): array
{
    $clientId = 'ytc_' . random_token(16);
    $secret = $confidential ? random_token(32) : null;
    db_insert('oauth_clients', [
        'client_id' => $clientId,
        'client_secret_hash' => $secret ? token_hash($secret) : null,
        'name' => mb_substr($name ?: 'MCP client', 0, 120),
        'redirect_uris' => json_encode(array_values($redirects)),
        'dynamic' => $dynamic ? 1 : 0,
        'created_at' => time(),
    ]);
    return ['client_id' => $clientId, 'client_secret' => $secret];
}

function oauth_issue_tokens(string $clientId, ?string $family = null): array
{
    $family = $family ?: random_token(12);
    $ttl = max(300, (int)setting('access_token_ttl', '86400'));
    $access = 'ytca_' . random_token(32);
    $refresh = 'ytcr_' . random_token(32);
    db_insert('oauth_tokens', [
        'token_hash' => token_hash($access), 'kind' => 'access', 'client_id' => $clientId,
        'family' => $family, 'expires' => time() + $ttl, 'created_at' => time(),
    ]);
    db_insert('oauth_tokens', [
        'token_hash' => token_hash($refresh), 'kind' => 'refresh', 'client_id' => $clientId,
        'family' => $family, 'expires' => time() + 86400 * 90, 'created_at' => time(),
    ]);
    db_exec('UPDATE oauth_clients SET last_used = ? WHERE client_id = ?', [time(), $clientId]);
    return [
        'access_token' => $access,
        'token_type' => 'Bearer',
        'expires_in' => $ttl,
        'refresh_token' => $refresh,
        'scope' => 'youtube',
    ];
}

/** Returns auth context array or null. Accepts OAuth access tokens and the optional static API key. */
function oauth_authenticate(): ?array
{
    $tok = bearer_token();
    if (!$tok && isset($_GET['key'])) $tok = (string)$_GET['key'];
    if (!$tok) return null;
    $apiHash = (string)setting('api_key_hash', '');
    if ($apiHash !== '' && hash_equals($apiHash, token_hash($tok))) {
        return ['client_id' => 'api_key', 'client_name' => 'API key'];
    }
    $row = db_one("SELECT * FROM oauth_tokens WHERE token_hash = ? AND kind = 'access'", [token_hash($tok)]);
    if (!$row || (int)$row['revoked'] === 1 || (int)$row['expires'] < time()) return null;
    if ((int)$row['last_used'] < time() - 60) db_exec('UPDATE oauth_tokens SET last_used = ? WHERE id = ?', [time(), $row['id']]);
    $client = oauth_client($row['client_id']);
    return ['client_id' => $row['client_id'], 'client_name' => $client['name'] ?? 'client', 'family' => $row['family']];
}

function oauth_cleanup(): void
{
    db_exec('DELETE FROM oauth_codes WHERE expires < ?', [time()]);
    db_exec('DELETE FROM oauth_tokens WHERE expires < ?', [time() - 86400 * 7]);
}

function cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID');
    header('Access-Control-Expose-Headers: Mcp-Session-Id, WWW-Authenticate');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

function oauth_error(string $error, string $desc = '', int $status = 400): void
{
    json_out(['error' => $error, 'error_description' => $desc], $status);
}
