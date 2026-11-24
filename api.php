<?php
/**
 * JSON API used by the dashboard (session auth + CSRF).
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/oauth.php';
require __DIR__ . '/lib/tools.php';

if (!is_installed()) json_out(['error' => 'not_installed'], 503);
if (!is_admin()) json_out(['error' => 'unauthorized'], 401);

$a = (string)($_GET['a'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost && !csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null))) json_out(['error' => 'csrf'], 419);
$in = $isPost ? (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'json') !== false ? input_json() : $_POST) : $_GET;
session_write_close(); // don't block parallel requests (uploads)

function ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; }
    return $n;
}

try {
    switch ($a) {
        /* ---------------- general ---------------- */
        case 'boot':
            $maxPost = min(ini_bytes((string)ini_get('upload_max_filesize')) ?: PHP_INT_MAX, ini_bytes((string)ini_get('post_max_size')) ?: PHP_INT_MAX);
            json_out([
                'version' => APP_VERSION,
                'base_url' => base_url(),
                'mcp_url' => base_url() . '/mcp',
                'google_redirect' => base_url() . '/google.php',
                'csrf' => csrf_token(),
                'chunk_size' => (int)max(512 * 1024, min(8 * 1024 * 1024, $maxPost - 64 * 1024)),
                'settings' => [
                    'allow_dcr' => setting('allow_dcr', '1') === '1',
                    'access_token_ttl' => (int)setting('access_token_ttl', '86400'),
                    'has_api_key' => setting('api_key_hash', '') !== '',
                ],
                'channels' => array_map('channel_public', db_all('SELECT * FROM channels ORDER BY id')),
                'php' => PHP_VERSION,
                'https' => strpos(base_url(), 'https://') === 0,
            ]);

        case 'overview':
            $chs = db_all('SELECT * FROM channels ORDER BY id');
            $out = [];
            $tot = ['subscribers' => 0, 'views' => 0, 'videos' => 0];
            foreach ($chs as $c) {
                $p = channel_public($c);
                $p['history'] = array_reverse(db_all('SELECT day, subscribers, views, videos FROM snapshots WHERE channel_id = ? ORDER BY day DESC LIMIT 30', [$c['id']]));
                foreach ($tot as $k => $v) $tot[$k] += (int)($p['stats'][$k] ?? 0);
                $out[] = $p;
            }
            $since = time() - 86400;
            json_out([
                'totals' => $tot,
                'channels' => $out,
                'notes' => note_list(['limit' => 4]),
                'activity' => db_all('SELECT * FROM activity ORDER BY id DESC LIMIT 10'),
                'ai_calls_24h' => (int)(db_one("SELECT COUNT(*) c FROM activity WHERE source = 'mcp' AND created_at > ?", [$since])['c'] ?? 0),
                'uploads' => array_map('upload_public', db_all("SELECT * FROM uploads WHERE status NOT IN ('expired') ORDER BY id DESC LIMIT 5")),
                'clients' => (int)(db_one('SELECT COUNT(DISTINCT family) c FROM oauth_tokens WHERE revoked = 0 AND expires > ?', [time()])['c'] ?? 0),
            ]);

        /* ---------------- channels ---------------- */
        case 'channel.save':
            $id = (int)($in['id'] ?? 0);
            $data = ['updated_at' => time()];
            foreach (['client_id', 'notes', 'color'] as $k) if (isset($in[$k])) $data[$k] = trim((string)$in[$k]);
            if (isset($in['ai_access']) && in_array($in['ai_access'], ['none', 'read', 'full'], true)) $data['ai_access'] = $in['ai_access'];
            if (!empty($in['client_secret'])) $data['client_secret_enc'] = encrypt_str(trim((string)$in['client_secret']));
            if (!empty($in['refresh_token'])) {
                $data['refresh_token_enc'] = encrypt_str(trim((string)$in['refresh_token']));
                $data['access_token_enc'] = null;
                $data['token_expires'] = 0;
            }
            if ($id) {
                if (!channel_get($id)) throw new UserError('Channel not found');
                db_update('channels', $data, 'id = ?', [$id]);
            } else {
                if (empty($data['client_id']) || empty($data['client_secret_enc'])) throw new UserError('Client ID and Client Secret are required.');
                $data += ['title' => 'New channel', 'status' => 'pending', 'ai_access' => $data['ai_access'] ?? 'full', 'created_at' => time()];
                $id = db_insert('channels', $data);
            }
            log_activity('web', $in['id'] ?? null ? 'channel_updated' : 'channel_added', $id);
            $c = channel_get($id);
            $sync = null;
            if (!empty($c['refresh_token_enc']) && (!empty($in['refresh_token']) || !empty($in['client_secret']) || $c['status'] !== 'connected')) {
                try { (new YouTube($c))->syncChannel(); } catch (Throwable $e) { $sync = $e->getMessage(); }
            }
            json_out(['channel' => channel_public(channel_get($id)), 'sync_error' => $sync]);

        case 'channel.sync':
            $c = channel_get((int)$in['id']);
            if (!$c) throw new UserError('Channel not found');
            $yt = new YouTube($c);
            $yt->syncChannel();
            json_out(['channel' => channel_public($yt->ch)]);

        case 'channel.delete':
            $id = (int)$in['id'];
            $c = channel_get($id);
            if (!$c) throw new UserError('Channel not found');
            // best effort: revoke the Google token
            try {
                $rt = decrypt_str($c['refresh_token_enc']);
                if ($rt && !empty($in['revoke'])) http_request('POST', 'https://oauth2.googleapis.com/revoke', ['form' => ['token' => $rt]]);
            } catch (Throwable $e) {}
            db_exec('DELETE FROM channels WHERE id = ?', [$id]);
            db_exec('DELETE FROM snapshots WHERE channel_id = ?', [$id]);
            if (!empty($in['delete_notes'])) db_exec('DELETE FROM notebook WHERE channel_id = ?', [$id]);
            log_activity('web', 'channel_deleted', null, ['title' => $c['title']]);
            json_out(['ok' => true]);

        case 'snapshots':
            $days = max(2, min(365, (int)($in['days'] ?? 30)));
            json_out(['history' => array_reverse(db_all('SELECT day, subscribers, views, videos FROM snapshots WHERE channel_id = ? ORDER BY day DESC LIMIT ' . $days, [(int)$in['channel']]))]);

        /* ---------------- tools (same as Claude, admin privileges) ---------------- */
        case 'tool':
            $name = (string)($in['name'] ?? '');
            $args = $in['args'] ?? [];
            if (is_string($args)) $args = json_decode($args, true) ?: [];
            $res = tool_run($name, $args, ['admin' => true]);
            if (!in_array($name, ['list_channels', 'list_videos', 'get_video', 'analytics_summary', 'list_playlists', 'list_comments', 'list_captions', 'list_categories', 'notebook_read', 'query_analytics', 'upload_status', 'get_transcript'], true)) {
                log_activity('web', $name, isset($args['channel']) ? (int)$args['channel'] : null, array_diff_key($args, ['image_base64' => 1, 'content' => 1]));
            }
            json_out(['result' => $res]);

        case 'thumbnail':
            if (empty($_FILES['file']['tmp_name'])) throw new UserError('No image received');
            $c = channel_resolve($in['channel'] ?? null);
            $bytes = file_get_contents($_FILES['file']['tmp_name']);
            $r = (new YouTube($c))->setThumbnail(video_id_from((string)$in['video_id']), $bytes);
            log_activity('web', 'set_thumbnail', (int)$c['id'], ['video_id' => $in['video_id']]);
            json_out(['ok' => true, 'result' => $r]);

        /* ---------------- uploads from the browser ---------------- */
        case 'upload.init':
            $c = channel_resolve($in['channel'] ?? null);
            $meta = upload_meta_from($in['meta'] ?? []);
            $u = upload_create((int)$c['id'], 'browser', $meta, [
                'filename' => mb_substr((string)($in['filename'] ?? 'video'), 0, 200),
                'size' => (int)($in['size'] ?? 0),
                'mime' => (string)($in['mime'] ?? 'video/*'),
            ]);
            json_out(['upload' => upload_public($u)]);

        case 'upload.chunk':
            $u = upload_get((int)($in['id'] ?? 0));
            if (!$u) throw new UserError('Upload not found');
            if (empty($_FILES['chunk']['tmp_name'])) throw new UserError('No data received (check upload_max_filesize).');
            $u = upload_append($u, (int)($in['offset'] ?? 0), $_FILES['chunk']['tmp_name']);
            json_out(['upload' => upload_public($u)]);

        case 'upload.thumb':
            $u = upload_get((int)($in['id'] ?? 0));
            if (!$u || empty($_FILES['file']['tmp_name'])) throw new UserError('Missing upload or file');
            $dest = staging_dir() . '/' . $u['token'] . '.thumb';
            move_uploaded_file($_FILES['file']['tmp_name'], $dest);
            $meta = json_decode($u['meta'] ?: '{}', true) ?: [];
            $meta['thumbnail_path'] = $dest;
            upload_set((int)$u['id'], ['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
            json_out(['ok' => true]);

        case 'upload.meta':
            $u = upload_get((int)($in['id'] ?? 0));
            if (!$u) throw new UserError('Upload not found');
            $meta = array_merge(json_decode($u['meta'] ?: '{}', true) ?: [], upload_meta_from($in['meta'] ?? []));
            unset($meta['await_metadata']);
            upload_set((int)$u['id'], ['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
            json_out(['upload' => upload_public(upload_get((int)$u['id']))]);

        case 'upload.step':
            json_out(['upload' => upload_step((int)($in['id'] ?? 0), 20)]);

        case 'upload.list':
            json_out(['uploads' => array_map('upload_public', db_all('SELECT * FROM uploads ORDER BY id DESC LIMIT 30'))]);

        case 'upload.cancel':
            $u = upload_get((int)($in['id'] ?? 0));
            if ($u) {
                @unlink($u['path']);
                db_exec('DELETE FROM uploads WHERE id = ?', [$u['id']]);
            }
            json_out(['ok' => true]);

        /* ---------------- notebook ---------------- */
        case 'notes':
            json_out(['entries' => note_list($in)]);

        case 'note.save':
            $f = $in;
            $f['channel_id'] = !empty($in['channel_id']) ? (int)$in['channel_id'] : null;
            if (isset($f['tags']) && is_string($f['tags'])) $f['tags'] = array_filter(array_map('trim', explode(',', $f['tags'])));
            $note = !empty($in['id']) ? note_update((int)$in['id'], $f) : note_create($f, 'user');
            json_out(['entry' => $note]);

        case 'note.delete':
            db_exec('DELETE FROM notebook WHERE id = ?', [(int)$in['id']]);
            json_out(['ok' => true]);

        /* ---------------- connector / OAuth ---------------- */
        case 'connector':
            $clients = db_all('SELECT * FROM oauth_clients ORDER BY id DESC');
            $fams = db_all("SELECT family, client_id, MIN(created_at) AS created_at, MAX(last_used) AS last_used, MAX(expires) AS expires
                            FROM oauth_tokens WHERE revoked = 0 AND expires > ? GROUP BY family, client_id ORDER BY created_at DESC", [time()]);
            $names = [];
            foreach ($clients as $c) $names[$c['client_id']] = $c['name'];
            json_out([
                'mcp_url' => base_url() . '/mcp',
                'clients' => array_map(fn($c) => [
                    'id' => (int)$c['id'],
                    'client_id' => $c['client_id'],
                    'name' => $c['name'],
                    'redirect_uris' => json_decode($c['redirect_uris'] ?: '[]', true),
                    'confidential' => !empty($c['client_secret_hash']),
                    'dynamic' => (bool)$c['dynamic'],
                    'created_at' => (int)$c['created_at'],
                    'last_used' => (int)$c['last_used'],
                ], $clients),
                'sessions' => array_map(fn($f) => $f + ['client_name' => $names[$f['client_id']] ?? $f['client_id']], $fams),
                'tools' => array_map(fn($t) => ['name' => $t['name'], 'title' => $t['title'], 'description' => $t['description'], 'access' => $t['access'], 'read_only' => $t['readOnly'], 'destructive' => $t['destructive'] ?? false], tools_enabled()),
            ]);

        case 'client.create':
            $redir = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($in['redirect_uris'] ?? '')))));
            $c = oauth_create_client((string)($in['name'] ?? 'Claude'), $redir ?: CLAUDE_CALLBACKS, !empty($in['confidential']), false);
            log_activity('web', 'oauth_client_created', null, ['name' => $in['name'] ?? '']);
            json_out($c);

        case 'client.delete':
            $c = db_one('SELECT * FROM oauth_clients WHERE id = ?', [(int)$in['id']]);
            if ($c) {
                db_exec('DELETE FROM oauth_tokens WHERE client_id = ?', [$c['client_id']]);
                db_exec('DELETE FROM oauth_codes WHERE client_id = ?', [$c['client_id']]);
                db_exec('DELETE FROM oauth_clients WHERE id = ?', [$c['id']]);
            }
            json_out(['ok' => true]);

        case 'session.revoke':
            if (!empty($in['all'])) db_exec('UPDATE oauth_tokens SET revoked = 1');
            else db_exec('UPDATE oauth_tokens SET revoked = 1 WHERE family = ?', [(string)$in['family']]);
            log_activity('web', 'sessions_revoked', null, $in);
            json_out(['ok' => true]);

        case 'apikey':
            if (!empty($in['revoke'])) { setting_set('api_key_hash', ''); json_out(['ok' => true]); }
            $key = 'ytck_' . random_token(32);
            setting_set('api_key_hash', token_hash($key));
            log_activity('web', 'api_key_generated');
            json_out(['key' => $key]);

        case 'settings.save':
            if (isset($in['allow_dcr'])) setting_set('allow_dcr', $in['allow_dcr'] ? '1' : '0');
            if (isset($in['access_token_ttl'])) setting_set('access_token_ttl', (string)max(300, min(86400 * 30, (int)$in['access_token_ttl'])));
            if (!empty($in['base_url'])) {
                if (!preg_match('#^https?://[^\s]+$#', (string)$in['base_url'])) throw new UserError('Invalid URL');
                setting_set('base_url', rtrim((string)$in['base_url'], '/'));
            }
            json_out(['ok' => true]);

        case 'password':
            if (!admin_password_ok((string)($in['current'] ?? ''))) throw new UserError('Current password is wrong.');
            if (strlen((string)($in['new'] ?? '')) < 8) throw new UserError('New password must be at least 8 characters.');
            setting_set('admin_pass_hash', password_hash((string)$in['new'], PASSWORD_DEFAULT));
            $ver = (string)((int)setting('admin_pass_ver', '1') + 1);
            setting_set('admin_pass_ver', $ver);
            app_start_session();
            $_SESSION['admin_ver'] = $ver; // keep this session, log out others
            log_activity('web', 'password_changed');
            json_out(['ok' => true]);

        /* ---------------- media sources ---------------- */
        case 'media.settings':
            json_out(media_settings_public());

        case 'media.save':
            if (isset($in['pixabay_enabled'])) setting_set('src_pixabay', $in['pixabay_enabled'] ? '1' : '0');
            if (isset($in['wikimedia_enabled'])) setting_set('src_wikimedia', $in['wikimedia_enabled'] ? '1' : '0');
            if (isset($in['pixabay_safesearch'])) setting_set('pixabay_safesearch', $in['pixabay_safesearch'] ? '1' : '0');
            if (!empty($in['remove_pixabay_key'])) setting_set('pixabay_key_enc', '');
            if (!empty($in['pixabay_key'])) {
                $key = trim((string)$in['pixabay_key']);
                pixabay_search(['query' => 'nature', 'per_page' => 3], $key); // throws if the key is invalid
                setting_set('pixabay_key_enc', encrypt_str($key));
                log_activity('web', 'pixabay_key_saved');
            }
            json_out(media_settings_public());

        case 'media.search':
            $src = (string)($in['source'] ?? 'pixabay');
            $args = is_array($in['args'] ?? null) ? $in['args'] : [];
            json_out(['result' => $src === 'wikimedia' ? wikimedia_search($args) : pixabay_search($args)]);

        /* ---------------- Telegram ---------------- */
        case 'tg.settings':
            json_out(tg_settings_public());

        case 'tg.save':
            if (!empty($in['remove_token'])) {
                foreach (['tg_token_enc', 'tg_bot', 'tg_offset', 'tg_chats'] as $k) setting_set($k, '');
                log_activity('web', 'telegram_token_removed');
            }
            if (!empty($in['token'])) {
                $tok = trim((string)$in['token']);
                if (!preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $tok)) throw new UserError('This does not look like a bot token (format 123456789:ABC…).');
                $me = tg_api('getMe', [], [], $tok);
                setting_set('tg_token_enc', encrypt_str($tok));
                setting_set('tg_bot', json_encode(['id' => $me['id'], 'name' => $me['first_name'] ?? '', 'username' => $me['username'] ?? ''], JSON_UNESCAPED_UNICODE));
                setting_set('tg_offset', '0');
                log_activity('web', 'telegram_token_saved', null, ['bot' => '@' . ($me['username'] ?? '')]);
            }
            if (isset($in['enabled'])) setting_set('tg_enabled', $in['enabled'] ? '1' : '0');
            if (isset($in['default_chat'])) setting_set('tg_default_chat', trim((string)$in['default_chat']));
            if (isset($in['allowed'])) setting_set('tg_allowed', trim((string)$in['allowed']));
            json_out(tg_settings_public());

        case 'tg.refresh':
            $msgs = tg_updates(100, false);
            json_out(tg_settings_public() + ['recent' => array_slice(array_reverse($msgs), 0, 10)]);

        case 'tg.test':
            $chat = tg_chat($in['chat'] ?? null);
            $r = tg_api('sendMessage', ['chat_id' => $chat, 'text' => (string)($in['text'] ?? 'YT Connector ✓'), 'parse_mode' => 'HTML']);
            log_activity('web', 'telegram_test_message', null, ['chat' => $chat]);
            json_out(tg_sent_public($r));

        /* ---------------- activity ---------------- */
        case 'activity':
            $where = [];
            $p = [];
            if (!empty($in['source'])) { $where[] = 'source = ?'; $p[] = $in['source']; }
            if (!empty($in['channel'])) { $where[] = 'channel_id = ?'; $p[] = (int)$in['channel']; }
            if (isset($in['ok']) && $in['ok'] !== '') { $where[] = 'ok = ?'; $p[] = (int)$in['ok']; }
            if (!empty($in['q'])) { $where[] = '(action LIKE ? OR detail LIKE ?)'; $p[] = '%' . $in['q'] . '%'; $p[] = '%' . $in['q'] . '%'; }
            $limit = max(10, min(200, (int)($in['limit'] ?? 50)));
            $offset = max(0, (int)($in['offset'] ?? 0));
            $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
            $rows = db_all('SELECT * FROM activity' . $w . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $p);
            $total = (int)db_one('SELECT COUNT(*) c FROM activity' . $w, $p)['c'];
            // 14-day histogram of AI calls
            $hist = [];
            for ($i = 13; $i >= 0; $i--) $hist[gmdate('Y-m-d', time() - $i * 86400)] = ['mcp' => 0, 'web' => 0];
            foreach (db_all('SELECT source, created_at FROM activity WHERE created_at > ?', [time() - 14 * 86400]) as $r) {
                $d = gmdate('Y-m-d', (int)$r['created_at']);
                if (isset($hist[$d])) $hist[$d][$r['source'] === 'mcp' ? 'mcp' : 'web']++;
            }
            json_out(['rows' => $rows, 'total' => $total, 'histogram' => $hist]);

        case 'activity.clear':
            db_exec('DELETE FROM activity');
            json_out(['ok' => true]);
    }
    json_out(['error' => 'unknown_action'], 404);
} catch (UserError $e) {
    json_out(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('api ' . $a . ': ' . $e);
    json_out(['error' => 'Server error: ' . $e->getMessage()], 500);
}
