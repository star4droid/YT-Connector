<?php
require __DIR__ . '/lib/bootstrap.php';
require_admin_page();
$lang = in_array($_COOKIE['ytc_lang'] ?? '', ['ar', 'en'], true)
    ? $_COOKIE['ytc_lang']
    : (stripos($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 'ar') === 0 ? 'ar' : 'en');
$v = APP_VERSION . '.' . @filemtime(__DIR__ . '/assets/js/app.js');
?><!doctype html>
<html lang="<?= $lang ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0b0d14">
<title>YT Connector</title>
<link rel="icon" href="assets/img/logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= h($v) ?>">
<script>
  try { const t = localStorage.getItem('ytc_theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}
  window.YTC = { csrf: <?= json_encode(csrf_token()) ?>, lang: <?= json_encode($lang) ?> };
</script>
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="#/overview"><img src="assets/img/logo.svg" alt=""><span>YT Connector</span></a>
    <nav class="nav" id="nav"></nav>
    <div class="side-channels" id="sideChannels"></div>
    <div class="side-foot">
      <button class="icon-btn" id="themeBtn" title="Theme">🌓</button>
      <button class="icon-btn" id="langBtn" title="Language">ع/E</button>
      <a class="icon-btn" href="logout.php" title="Logout">⏻</a>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu-btn" id="menuBtn">☰</button>
      <div class="crumbs" id="crumbs"></div>
      <div class="top-actions">
        <a class="btn sm ghost" href="#/connector" id="mcpPill"><span class="pulse"></span><span data-t="mcp_live">MCP</span></a>
        <a class="btn sm primary" href="#/upload">⬆ <span data-t="upload">Upload</span></a>
      </div>
    </header>
    <main class="view" id="view"><div class="loader"><span></span></div></main>
  </div>
</div>
<div class="toasts" id="toasts"></div>
<div class="modal-root" id="modalRoot"></div>
<script src="assets/js/i18n.js?v=<?= h($v) ?>"></script>
<script src="assets/js/charts.js?v=<?= h($v) ?>"></script>
<script src="assets/js/app.js?v=<?= h($v) ?>"></script>
</body>
</html>
