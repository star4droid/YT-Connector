<?php
/**
 * Public one-time upload page created by the `create_upload_link` tool.
 * Access is granted by the unguessable token in the URL (valid 48h, single file).
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/i18n.php';
if (!is_installed()) { http_response_code(404); exit; }

$token = (string)($_GET['t'] ?? '');
$u = $token !== '' ? upload_by_token($token) : null;
$valid = $u && $u['source'] === 'link' && (int)$u['expires'] > time() && in_array($u['status'], ['waiting_file', 'receiving', 'ready', 'uploading', 'done'], true);

if (isset($_GET['a'])) {
    if (!$valid) json_out(['error' => t('drop_expired')], 404);
    try {
        switch ($_GET['a']) {
            case 'init':
                if (!in_array($u['status'], ['waiting_file', 'receiving'], true)) throw new UserError('File already received.');
                $size = (int)($_POST['size'] ?? 0);
                if ($size <= 0) throw new UserError('Empty file');
                if ($u['status'] === 'waiting_file' || (int)$u['size'] !== $size) {
                    @unlink($u['path']);
                    $u = upload_set((int)$u['id'], [
                        'filename' => mb_substr((string)($_POST['name'] ?? 'video'), 0, 200),
                        'size' => $size,
                        'mime' => (string)($_POST['type'] ?? 'video/*'),
                        'received' => 0,
                        'status' => 'receiving',
                    ]);
                }
                json_out(['upload' => upload_public($u)]);
            case 'chunk':
                if (empty($_FILES['chunk']['tmp_name'])) throw new UserError('No data received');
                $u = upload_append($u, (int)($_POST['offset'] ?? 0), $_FILES['chunk']['tmp_name']);
                if ($u['status'] === 'ready') log_activity('system', 'file_dropped', (int)$u['channel_id'], ['upload' => (int)$u['id'], 'file' => $u['filename']]);
                json_out(['upload' => upload_public($u)]);
            case 'step':
                $meta = json_decode($u['meta'] ?: '{}', true) ?: [];
                if (!empty($meta['await_metadata']) && empty($meta['title'])) json_out(['upload' => upload_public($u)]);
                json_out(['upload' => upload_step((int)$u['id'], 20)]);
            case 'status':
                json_out(['upload' => upload_public($u)]);
        }
    } catch (UserError $e) {
        json_out(['error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        json_out(['error' => $e->getMessage()], 500);
    }
    json_out(['error' => 'unknown'], 404);
}

page_head(t('drop_title'));
$ch = $valid ? channel_get((int)$u['channel_id']) : null;
$chunk = 4 * 1024 * 1024;
?>
<main class="auth-card wide">
  <div class="auth-top">
    <div class="brand"><img src="assets/img/logo.svg" alt=""><span>YT Connector</span></div>
    <?= lang_switch() ?>
  </div>
  <?php if (!$valid): ?>
    <h1>⏳</h1><div class="alert error"><?= h(t('drop_expired')) ?></div>
  <?php else: ?>
    <h1><?= h(t('drop_title')) ?></h1>
    <p class="muted"><?= h(t('drop_sub')) ?></p>
    <?php if ($ch): ?>
      <div class="drop-channel">
        <?php if ($ch['thumbnail']): ?><img src="<?= h($ch['thumbnail']) ?>" alt=""><?php endif; ?>
        <div><small class="muted"><?= h(t('channel')) ?></small><b><?= h($ch['title']) ?></b></div>
      </div>
    <?php endif; ?>
    <label class="dropzone big" id="dz">
      <input type="file" accept="video/*" id="file" hidden>
      <div class="dz-icon">⬆</div>
      <div class="dz-text"><?= h(t('drop_choose')) ?></div>
      <div class="dz-sub muted">MP4 · MOV · WEBM · AVI · MKV</div>
    </label>
    <div class="progress-wrap" id="pw" hidden>
      <div class="pw-head"><span id="pname"></span><span id="ppct">0%</span></div>
      <div class="progress"><div id="pbar"></div></div>
      <div class="muted small" id="pstate"></div>
    </div>
    <div class="alert success" id="done" hidden></div>
    <div class="alert error" id="err" hidden></div>
  <?php endif; ?>
</main>
<?php if ($valid): ?>
<script>
const T = <?= json_encode($token) ?>, CHUNK = <?= $chunk ?>, DONE_TXT = <?= json_encode(t('drop_done')) ?>;
const $ = id => document.getElementById(id);
const dz = $('dz'), inp = $('file');
['dragover','dragenter'].forEach(e => dz.addEventListener(e, ev => { ev.preventDefault(); dz.classList.add('over'); }));
['dragleave','drop'].forEach(e => dz.addEventListener(e, ev => { ev.preventDefault(); dz.classList.remove('over'); }));
dz.addEventListener('drop', ev => ev.dataTransfer.files[0] && start(ev.dataTransfer.files[0]));
inp.addEventListener('change', () => inp.files[0] && start(inp.files[0]));
const fmt = b => b > 1073741824 ? (b/1073741824).toFixed(2)+' GB' : (b/1048576).toFixed(1)+' MB';
async function post(a, data) {
  const r = await fetch('drop.php?t=' + encodeURIComponent(T) + '&a=' + a, { method: 'POST', body: data });
  const j = await r.json(); if (!r.ok) throw new Error(j.error || r.status); return j.upload;
}
function show(p, txt) { $('pbar').style.width = p + '%'; $('ppct').textContent = p + '%'; $('pstate').textContent = txt; }
async function start(file) {
  dz.hidden = true; $('pw').hidden = false; $('pname').textContent = file.name + ' · ' + fmt(file.size);
  try {
    const fd = new FormData(); fd.append('size', file.size); fd.append('name', file.name); fd.append('type', file.type || 'video/*');
    let u = await post('init', fd), off = u.received;
    while (off < file.size) {
      const c = new FormData(); c.append('offset', off); c.append('chunk', file.slice(off, off + CHUNK));
      let tries = 0;
      for (;;) { try { u = await post('chunk', c); break; } catch (e) { if (++tries > 4) throw e; await new Promise(r => setTimeout(r, 1500 * tries)); } }
      off = u.received;
      show(Math.floor(off / file.size * 100), fmt(off) + ' / ' + fmt(file.size));
    }
    // push to YouTube if metadata is already known
    for (;;) {
      u = await post('step', new FormData());
      if (u.status === 'uploading') show(u.progress_percent, 'YouTube ' + u.progress_percent + '%');
      if (u.status === 'error') throw new Error(u.error);
      if (u.status === 'done' || u.awaiting_metadata || u.status === 'ready') break;
    }
    $('pw').hidden = true; $('done').hidden = false;
    $('done').innerHTML = '✅ ' + DONE_TXT + (u.video_url ? '<br><a href="' + u.video_url + '" target="_blank">' + u.video_url + '</a>' : '');
  } catch (e) { $('err').hidden = false; $('err').textContent = e.message; }
}
</script>
<?php endif; ?>
</body></html>
