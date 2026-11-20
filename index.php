<?php
require __DIR__ . '/lib/bootstrap.php';
require_admin_page();
$lang = in_array($_COOKIE['ytc_lang'] ?? '', ['ar', 'en'], true)
    ? $_COOKIE['ytc_lang']
    : (stripos($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 'ar') === 0 ? 'ar' : 'en');
$v = APP_VERSION . '.' . @filemtime(__DIR__ . '/assets/js/app.js') . @filemtime(__DIR__ . '/assets/css/app.css');
?><!doctype html>
<html lang="<?= $lang ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#f4f5f9" id="themeColor">
<title>YT Connector</title>
<link rel="icon" href="assets/img/logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= h($v) ?>">
<script>
  (function () {
    var pref = 'auto';
    try { pref = localStorage.getItem('ytc_theme') || 'auto'; } catch (e) {}
    var dark = pref === 'dark' || (pref === 'auto' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    document.documentElement.dataset.themePref = pref;
  })();
  window.YTC = { csrf: <?= json_encode(csrf_token()) ?>, lang: <?= json_encode($lang) ?> };
</script>
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar" aria-label="Navigation">
    <div class="side-head">
      <a class="brand" href="#/overview"><img src="assets/img/logo.svg" alt=""><span>YT Connector</span></a>
      <button class="icon-btn ghost side-close" id="sideClose" aria-label="Close"></button>
    </div>
    <nav class="nav" id="nav"></nav>
    <div class="side-channels" id="sideChannels"></div>
    <div class="side-foot">
      <button class="icon-btn" id="langBtn" title="Language"></button>
      <button class="icon-btn" id="themeBtn" title="Theme"></button>
      <a class="icon-btn" href="logout.php" id="logoutBtn" title="Logout"></a>
    </div>
  </aside>
  <div class="backdrop" id="backdrop"></div>
  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu-btn" id="menuBtn" aria-label="Menu"></button>
      <div class="crumbs" id="crumbs"></div>
      <div class="top-actions">
        <button class="icon-btn" id="themeBtn2" title="Theme"></button>
        <a class="btn sm ghost hide-m" href="#/connector" id="mcpPill"><span class="pulse"></span><span data-t="mcp_live">MCP</span></a>
        <a class="btn sm primary hide-m" href="#/upload" id="topUpload"></a>
      </div>
    </header>
    <main class="view" id="view"><div class="loader"><span></span></div></main>
  </div>
  <nav class="bottombar" id="bottombar"></nav>
</div>
<div class="toasts" id="toasts"></div>
<div class="modal-root" id="modalRoot"></div>
<script src="assets/js/icons.js?v=<?= h($v) ?>"></script>
<script src="assets/js/i18n.js?v=<?= h($v) ?>"></script>
<script src="assets/js/charts.js?v=<?= h($v) ?>"></script>
<script src="assets/js/app.js?v=<?= h($v) ?>"></script>
</body>
</html>
