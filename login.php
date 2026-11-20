<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/i18n.php';

if (!is_installed()) { header('Location: install.php'); exit; }

$next = (string)($_GET['next'] ?? $_POST['next'] ?? 'index.php');
// only allow local redirects
if (!preg_match('#^/?[A-Za-z0-9_\-./?=&%\#]*$#', $next) || strpos($next, '//') === 0) $next = 'index.php';

if (is_admin()) { header('Location: ' . $next); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (login_throttled()) {
        $error = t('too_many');
    } elseif (admin_password_ok((string)($_POST['password'] ?? ''))) {
        app_start_session();
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['admin_ver'] = setting('admin_pass_ver', '1');
        log_activity('web', 'login', null, ['ip' => client_ip()]);
        header('Location: ' . $next);
        exit;
    } else {
        login_failed();
        log_activity('web', 'login_failed', null, ['ip' => client_ip()], false);
        $error = t('wrong_password');
    }
}
page_head(t('sign_in'));
?>
<main class="auth-card">
  <div class="auth-top">
    <div class="brand"><img src="assets/img/logo.svg" alt=""><span>YT Connector</span></div>
    <?= lang_switch() ?>
  </div>
  <h1><?= h(t('login_title')) ?></h1>
  <p class="muted"><?= h(t('login_sub')) ?></p>
  <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <label class="field"><span><?= h(t('password')) ?></span>
      <input type="password" name="password" required autofocus autocomplete="current-password"></label>
    <button class="btn primary block"><?= h(t('sign_in')) ?></button>
  </form>
</main>
</body></html>
