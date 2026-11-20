<?php
/**
 * MCP server endpoint (Streamable HTTP transport, JSON responses).
 * Add this URL as a custom connector in Claude:  https://your-site/mcp
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/oauth.php';
require __DIR__ . '/lib/tools.php';

cors_headers();
const MCP_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

if (!is_installed()) json_out(['error' => 'not_installed'], 503);

$auth = oauth_authenticate();
if (!$auth) {
    $path = (strpos($_SERVER['REQUEST_URI'] ?? '', 'mcp.php') !== false) ? 'mcp.php' : 'mcp';
    $meta = base_url() . '/well-known.php?type=pr&path=' . $path;
    json_out(
        ['jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => 'Unauthorized: connect this server with OAuth (Claude → Connectors) or a valid API key.'], 'id' => null],
        401,
        ['WWW-Authenticate' => 'Bearer realm="yt-connector", resource_metadata="' . $meta . '"']
    );
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    // no server-initiated stream
    header('Allow: POST, DELETE, OPTIONS');
    json_out(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'SSE stream not supported; use POST.'], 'id' => null], 405);
}
if ($method === 'DELETE') { http_response_code(204); exit; }
if ($method !== 'POST') json_out(['error' => 'method not allowed'], 405);

$raw = file_get_contents('php://input');
$msg = json_decode((string)$raw, true);
if (!is_array($msg)) {
    json_out(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error'], 'id' => null], 400);
}

if (mt_rand(1, 50) === 1) { uploads_cleanup(); oauth_cleanup(); }

$batch = array_is_list_compat($msg) ? $msg : [$msg];
$responses = [];
$sessionHeader = null;
foreach ($batch as $m) {
    if (!is_array($m)) continue;
    $r = mcp_handle($m, $auth, $sessionHeader);
    if ($r !== null) $responses[] = $r;
}
if (!$responses) { http_response_code(202); exit; }
$headers = $sessionHeader ? ['Mcp-Session-Id' => $sessionHeader] : [];
json_out(array_is_list_compat($msg) ? $responses : $responses[0], 200, $headers);

/* ------------------------------------------------------------------------- */

function array_is_list_compat(array $a): bool
{
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
}

function mcp_instructions(): string
{
    return <<<TXT
YT Connector lets you fully manage the user's YouTube channels.
• Start with list_channels. Each channel has ai_access: "full" (read + write), "read" (read only) or hidden.
• Videos: list_videos, get_video, get_video_visuals (see thumbnail + frames), get_transcript (what is said), update_video, set_thumbnail, delete_video (only after explicit user confirmation, confirm=true).
• Uploading: if YOU have the video file (attached to the chat or created in your sandbox), call upload_file_start, then send the raw bytes with curl to the returned put_url (or, for tiny files, base64 via upload_file_chunk); the server pushes it to YouTube and deletes its copy right after. If the user has the file on their device, call create_upload_link and give them the link. For a public direct link use upload_video with source_url. Then poll upload_status until "done".
• Analytics: analytics_summary for ready-made reports, query_analytics for custom questions. Data lags ~2 days.
• Comments, playlists, captions and channel branding are also available.
• AI Notebook (notebook_write / notebook_read): a journal shown in the dashboard. When the user asks for daily/weekly summaries, reports or to log your work, write entries there (Markdown body + optional metrics/charts). Read previous entries first to stay consistent.
• Default new uploads to private unless the user says otherwise. Respect the 100-char title and 5000-char description limits. Quota is limited (10,000 units/day by default); search costs 100 units, so prefer list_videos.
TXT;
}

function mcp_handle(array $m, array $auth, ?string &$sessionHeader): ?array
{
    $id = $m['id'] ?? null;
    $method = $m['method'] ?? '';
    $params = $m['params'] ?? [];
    $isNotification = !array_key_exists('id', $m);

    if (!$method) {
        // a response from the client (we never send requests) — ignore
        return null;
    }
    if ($isNotification) return null;

    $ok = fn($result) => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    $err = fn(int $code, string $message) => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];

    switch ($method) {
        case 'initialize':
            $want = (string)($params['protocolVersion'] ?? MCP_VERSIONS[1]);
            $version = in_array($want, MCP_VERSIONS, true) ? $want : MCP_VERSIONS[1];
            $sessionHeader = random_token(16);
            log_activity('mcp', 'initialize', null, ['client' => $auth['client_name'], 'info' => $params['clientInfo'] ?? null]);
            return $ok([
                'protocolVersion' => $version,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'yt-connector', 'title' => 'YT Connector', 'version' => APP_VERSION],
                'instructions' => mcp_instructions(),
            ]);
        case 'ping':
            return $ok(new stdClass());
        case 'tools/list':
            $list = [];
            foreach (tools_catalog() as $t) {
                $list[] = [
                    'name' => $t['name'],
                    'title' => $t['title'],
                    'description' => $t['description'],
                    'inputSchema' => $t['schema'],
                    'annotations' => [
                        'title' => $t['title'],
                        'readOnlyHint' => (bool)$t['readOnly'],
                        'destructiveHint' => (bool)($t['destructive'] ?? false),
                        'idempotentHint' => (bool)$t['readOnly'],
                        'openWorldHint' => true,
                    ],
                ];
            }
            return $ok(['tools' => $list]);
        case 'tools/call':
            $name = (string)($params['name'] ?? '');
            $args = $params['arguments'] ?? [];
            if (!is_array($args)) $args = [];
            $tool = tool_find($name);
            if (!$tool) return $err(-32602, 'Unknown tool: ' . $name);
            $chId = null;
            try { if (!empty($args['channel'])) $chId = (int)channel_resolve($args['channel'])['id']; } catch (Throwable $e) {}
            $started = microtime(true);
            try {
                $res = ($tool['run'])($args, ['admin' => false, 'auth' => $auth]);
                $logArgs = $args;
                foreach (['image_base64', 'content', 'body', 'description'] as $big) {
                    if (isset($logArgs[$big]) && is_string($logArgs[$big]) && strlen($logArgs[$big]) > 300) $logArgs[$big] = substr($logArgs[$big], 0, 300) . '…';
                }
                log_activity('mcp', $name, $chId, ['args' => $logArgs, 'ms' => (int)((microtime(true) - $started) * 1000)]);
                if (isset($res['__content'])) return $ok(['content' => $res['__content'], 'isError' => false]);
                return $ok([
                    'content' => [['type' => 'text', 'text' => json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]],
                    'isError' => false,
                ]);
            } catch (UserError $e) {
                log_activity('mcp', $name, $chId, ['args' => $args, 'error' => $e->getMessage()], false);
                return $ok(['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true]);
            } catch (Throwable $e) {
                error_log('tool ' . $name . ' failed: ' . $e);
                log_activity('mcp', $name, $chId, ['args' => $args, 'error' => $e->getMessage()], false);
                return $ok(['content' => [['type' => 'text', 'text' => 'Server error: ' . $e->getMessage()]], 'isError' => true]);
            }
        case 'resources/list':
            return $ok(['resources' => []]);
        case 'resources/templates/list':
            return $ok(['resourceTemplates' => []]);
        case 'prompts/list':
            return $ok(['prompts' => []]);
        case 'logging/setLevel':
            return $ok(new stdClass());
    }
    return $err(-32601, 'Method not found: ' . $method);
}
