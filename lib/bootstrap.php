<?php
/**
 * YT Connector — bootstrap
 * Loads config, opens the database and exposes shared helpers.
 */
declare(strict_types=1);

define('APP_NAME', 'YT Connector');
define('APP_VERSION', '1.0.0');
define('ROOT_DIR', dirname(__DIR__));
define('DATA_DIR', ROOT_DIR . '/data');
define('CONFIG_FILE', DATA_DIR . '/config.php');
define('STAGING_DIR', DATA_DIR . '/staging');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', DATA_DIR . '/php-errors.log');
date_default_timezone_set('UTC');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/youtube.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/notebook.php';

function is_installed(): bool
{
    return is_file(CONFIG_FILE);
}

function config(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = is_installed() ? (require CONFIG_FILE) : [];
        if (!is_array($cfg)) $cfg = [];
    }
    if ($key === null) return $cfg;
    return $cfg[$key] ?? $default;
}

function app_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('ytc_sess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Base URL of the installation, e.g. https://yt.example.com (no trailing slash). */
function base_url(): string
{
    $saved = is_installed() ? (string)setting('base_url', '') : '';
    if ($saved !== '') return rtrim($saved, '/');
    return detect_base_url();
}

function detect_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    // strip known sub-folders so the base always points to the app root
    $dir = preg_replace('#/(oauth)$#', '', $dir);
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function setting(string $key, $default = null)
{
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $row = db_one('SELECT v FROM settings WHERE k = ?', [$key]);
    } catch (Throwable $e) {
        return $default;
    }
    $val = $row ? $row['v'] : $default;
    $cache[$key] = $val;
    return $val;
}

function setting_set(string $key, $value): void
{
    $exists = db_one('SELECT k FROM settings WHERE k = ?', [$key]);
    if ($exists) db_exec('UPDATE settings SET v = ? WHERE k = ?', [(string)$value, $key]);
    else db_exec('INSERT INTO settings (k, v) VALUES (?, ?)', [$key, (string)$value]);
}

/* ---------------- Admin authentication ---------------- */

function is_admin(): bool
{
    app_start_session();
    return !empty($_SESSION['admin']) && ($_SESSION['admin_ver'] ?? '') === setting('admin_pass_ver', '1');
}

function require_admin_page(): void
{
    if (!is_installed()) { header('Location: install.php'); exit; }
    if (!is_admin()) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: login.php' . ($next ? '?next=' . urlencode($next) : ''));
        exit;
    }
}

function csrf_token(): string
{
    app_start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): bool
{
    app_start_session();
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

function client_ip(): string
{
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Very small brute-force guard for the login form. */
function login_throttled(): bool
{
    $since = time() - 900;
    $row = db_one('SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND created_at > ?', [client_ip(), $since]);
    return ((int)($row['c'] ?? 0)) >= 8;
}

function login_failed(): void
{
    db_exec('INSERT INTO login_attempts (ip, created_at) VALUES (?, ?)', [client_ip(), time()]);
    db_exec('DELETE FROM login_attempts WHERE created_at < ?', [time() - 86400]);
}

function admin_password_ok(string $password): bool
{
    $hash = (string)setting('admin_pass_hash', '');
    return $hash !== '' && password_verify($password, $hash);
}

function log_activity(string $source, string $action, ?int $channelId = null, $detail = null, bool $ok = true): void
{
    try {
        if (!is_string($detail)) $detail = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($detail !== null && mb_strlen($detail) > 4000) $detail = mb_substr($detail, 0, 4000) . '…';
        db_exec(
            'INSERT INTO activity (source, action, channel_id, detail, ok, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$source, $action, $channelId, $detail, $ok ? 1 : 0, time()]
        );
    } catch (Throwable $e) {
        error_log('activity log failed: ' . $e->getMessage());
    }
}
