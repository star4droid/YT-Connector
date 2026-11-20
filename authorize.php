<?php
/** OAuth authorization endpoint: admin login + consent screen. */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/oauth.php';
require __DIR__ . '/lib/i18n.php';
if (!is_installed()) { header('Location: install.php'); exit; }
app_start_session();

$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$p = [
    'response_type' => (string)($src['response_type'] ?? ''),
    'client_id' => (string)($src['client_id'] ?? ''),
    'redirect_uri' => (string)($src['redirect_uri'] ?? ''),
    'state' => (string)($src['state'] ?? ''),
    'scope' => (string)($src['scope'] ?? 'youtube'),
    'code_challenge' => (string)($src['code_challenge'] ?? ''),
    'code_challenge_method' => (string)($src['code_challenge_method'] ?? ''),
    'resource' => (string)($src['resource'] ?? ''),
];

function fail_page(string $msg): void
{
    http_response_code(400);
    page_head('Error');
    echo '<main class="auth-card"><h1>Authorization error</h1><div class="alert error">' . icon('alert') . '<div>' . h($msg) . '</div></div></main></body></html>';
    exit;
}

$client = $p['client_id'] ? oauth_client($p['client_id']) : null;
if (!$client) fail_page('Unknown client_id. Remove the connector in Claude and add it again.');
if (!$p['redirect_uri'] || !oauth_redirect_allowed($client, $p['redirect_uri'])) fail_page('redirect_uri is not registered for this client.');

function back(array $p, array $params): void
{
    if ($p['state'] !== '') $params['state'] = $p['state'];
    $sep = strpos($p['redirect_uri'], '?') === false ? '?' : '&';
    header('Location: ' . $p['redirect_uri'] . $sep . http_build_query($params));
    exit;
}

if ($p['response_type'] !== 'code') back($p, ['error' => 'unsupported_response_type']);
if ($p['code_challenge'] === '' || $p['code_challenge_method'] !== 'S256') back($p, ['error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required']);

$error = '';
$action = $_POST['action'] ?? '';

if ($action === 'login') {
    if (login_throttled()) $error = t('too_many');
    elseif (admin_password_ok((string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['admin_ver'] = setting('admin_pass_ver', '1');
    } else { login_failed(); $error = t('wrong_password'); }
} elseif ($action === 'allow' || $action === 'deny') {
    if (!is_admin() || !csrf_check($_POST['csrf'] ?? null)) fail_page('Session expired, please retry.');
    if ($action === 'deny') {
        log_activity('oauth', 'authorization_denied', null, ['client' => $client['name']], false);
        back($p, ['error' => 'access_denied']);
    }
    $code = random_token(32);
    db_insert('oauth_codes', [
        'code_hash' => token_hash($code),
        'client_id' => $client['client_id'],
        'redirect_uri' => $p['redirect_uri'],
        'code_challenge' => $p['code_challenge'],
        'scope' => $p['scope'],
        'resource' => $p['resource'],
        'expires' => time() + 600,
    ]);
    log_activity('oauth', 'authorization_granted', null, ['client' => $client['name']]);
    back($p, ['code' => $code]);
}

page_head(t('consent_title'));
$host = parse_url($p['redirect_uri'], PHP_URL_HOST) ?: $p['redirect_uri'];
$hidden = '';
foreach ($p as $k => $v) $hidden .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
?>
<main class="auth-card">
  <div class="auth-top">
    <div class="brand"><img src="assets/img/logo.svg" alt=""><span>YT Connector</span></div>
    <?= lang_switch() ?>
  </div>
  <?php if (!is_admin()): ?>
    <h1><?= h(t('login_title')) ?></h1>
    <p class="muted"><b><?= h($client['name']) ?></b> <?= h(t('consent_sub')) ?></p>
    <?php if ($error): ?><div class="alert error"><?= icon('alert') ?><div><?= h($error) ?></div></div><?php endif; ?>
    <form method="post" class="form">
      <?= $hidden ?><input type="hidden" name="action" value="login">
      <label class="field"><span><?= h(t('password')) ?></span><input type="password" name="password" required autofocus></label>
      <button class="btn primary block"><?= h(t('sign_in')) ?></button>
    </form>
  <?php else: ?>
    <div class="consent-hero">
      <div class="ibox ai"><?= icon('bot') ?></div><div class="consent-link"></div><div class="ibox brand"><?= icon('youtube') ?></div>
    </div>
    <h1><?= h(t('consent_title')) ?></h1>
    <p class="muted"><b><?= h($client['name']) ?></b> <?= h(t('consent_sub')) ?></p>
    <div class="consent-box">
      <div class="muted small"><?= h(t('consent_list')) ?></div>
      <ul class="checks">
        <li><?= icon('check') ?><span><?= h(t('consent_1')) ?></span></li>
        <li><?= icon('check') ?><span><?= h(t('consent_2')) ?></span></li>
        <li><?= icon('check') ?><span><?= h(t('consent_3')) ?></span></li>
      </ul>
      <div class="muted small"><?= h(t('redirect_to')) ?> <code dir="ltr"><?= h($host) ?></code></div>
    </div>
    <form method="post" class="consent-actions">
      <?= $hidden ?><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button class="btn ghost" name="action" value="deny"><?= h(t('deny')) ?></button>
      <button class="btn primary" name="action" value="allow"><?= h(t('allow')) ?></button>
    </form>
  <?php endif; ?>
</main>
</body></html>
