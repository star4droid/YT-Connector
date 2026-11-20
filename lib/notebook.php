<?php
/**
 * AI Notebook — a journal where the AI (or the user) stores daily/weekly summaries,
 * insights, plans and activity logs, with optional KPI tiles and charts.
 *
 * data JSON shape (all optional):
 * {
 *   "metrics": [ {"label":"Views","value":12345,"delta":12.5,"unit":""} ],
 *   "charts":  [ {"type":"line|bar|area|donut","title":"Views per day",
 *                 "labels":["Mon","Tue"], "series":[{"name":"Views","data":[10,20]}]} ]
 * }
 */
declare(strict_types=1);

const NOTE_KINDS = ['daily', 'weekly', 'monthly', 'report', 'insight', 'plan', 'task', 'log', 'note'];

function note_public(array $n): array
{
    $ch = $n['channel_id'] ? channel_get((int)$n['channel_id']) : null;
    return [
        'id' => (int)$n['id'],
        'channel_id' => $n['channel_id'] !== null ? (int)$n['channel_id'] : null,
        'channel_title' => $ch['title'] ?? null,
        'kind' => $n['kind'],
        'title' => $n['title'],
        'body' => $n['body'],
        'data' => $n['data'] ? json_decode($n['data'], true) : null,
        'tags' => $n['tags'] ? json_decode($n['tags'], true) : [],
        'pinned' => (bool)$n['pinned'],
        'author' => $n['author'],
        'period_start' => $n['period_start'],
        'period_end' => $n['period_end'],
        'created_at' => (int)$n['created_at'],
        'updated_at' => (int)$n['updated_at'],
    ];
}

function note_validate_data($data): ?string
{
    if ($data === null || $data === '' || $data === []) return null;
    if (is_string($data)) {
        $data = json_decode($data, true);
        if (!is_array($data)) throw new UserError('data must be a JSON object');
    }
    if (!is_array($data)) throw new UserError('data must be an object');
    $clean = [];
    if (!empty($data['metrics']) && is_array($data['metrics'])) {
        foreach (array_slice($data['metrics'], 0, 12) as $m) {
            if (!is_array($m) || !isset($m['label'])) continue;
            $clean['metrics'][] = [
                'label' => (string)$m['label'],
                'value' => $m['value'] ?? null,
                'delta' => isset($m['delta']) && is_numeric($m['delta']) ? (float)$m['delta'] : null,
                'unit' => isset($m['unit']) ? (string)$m['unit'] : '',
            ];
        }
    }
    if (!empty($data['charts']) && is_array($data['charts'])) {
        foreach (array_slice($data['charts'], 0, 8) as $c) {
            if (!is_array($c)) continue;
            $type = in_array($c['type'] ?? 'line', ['line', 'bar', 'area', 'donut'], true) ? $c['type'] : 'line';
            $series = [];
            foreach ((array)($c['series'] ?? []) as $s) {
                if (!is_array($s)) continue;
                $series[] = [
                    'name' => (string)($s['name'] ?? ''),
                    'data' => array_map(fn($v) => is_numeric($v) ? $v + 0 : 0, array_slice((array)($s['data'] ?? []), 0, 400)),
                ];
            }
            $clean['charts'][] = [
                'type' => $type,
                'title' => (string)($c['title'] ?? ''),
                'labels' => array_map('strval', array_slice((array)($c['labels'] ?? []), 0, 400)),
                'series' => array_slice($series, 0, 6),
            ];
        }
    }
    foreach ($data as $k => $v) {
        if (!in_array($k, ['metrics', 'charts'], true)) $clean['extra'][$k] = $v;
    }
    return $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
}

function note_create(array $f, string $author = 'ai'): array
{
    $kind = in_array($f['kind'] ?? 'note', NOTE_KINDS, true) ? $f['kind'] : 'note';
    $title = trim((string)($f['title'] ?? ''));
    if ($title === '') throw new UserError('title is required');
    $id = db_insert('notebook', [
        'channel_id' => $f['channel_id'] ?? null,
        'kind' => $kind,
        'title' => mb_substr($title, 0, 300),
        'body' => (string)($f['body'] ?? ''),
        'data' => note_validate_data($f['data'] ?? null),
        'tags' => json_encode(array_values(array_filter(array_map('strval', (array)($f['tags'] ?? []))))),
        'pinned' => !empty($f['pinned']) ? 1 : 0,
        'author' => $author,
        'period_start' => $f['period_start'] ?? null,
        'period_end' => $f['period_end'] ?? null,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    return note_public(db_one('SELECT * FROM notebook WHERE id = ?', [$id]));
}

function note_update(int $id, array $f): array
{
    $n = db_one('SELECT * FROM notebook WHERE id = ?', [$id]);
    if (!$n) throw new UserError('Notebook entry not found');
    $u = [];
    if (isset($f['title'])) $u['title'] = mb_substr((string)$f['title'], 0, 300);
    if (isset($f['body'])) $u['body'] = (string)$f['body'];
    if (!empty($f['append_body'])) $u['body'] = rtrim((string)($u['body'] ?? $n['body'])) . "\n\n" . $f['append_body'];
    if (isset($f['kind']) && in_array($f['kind'], NOTE_KINDS, true)) $u['kind'] = $f['kind'];
    if (array_key_exists('data', $f)) $u['data'] = note_validate_data($f['data']);
    if (isset($f['tags'])) $u['tags'] = json_encode(array_values(array_map('strval', (array)$f['tags'])));
    if (isset($f['pinned'])) $u['pinned'] = $f['pinned'] ? 1 : 0;
    if (array_key_exists('period_start', $f)) $u['period_start'] = $f['period_start'];
    if (array_key_exists('period_end', $f)) $u['period_end'] = $f['period_end'];
    $u['updated_at'] = time();
    db_update('notebook', $u, 'id = ?', [$id]);
    return note_public(db_one('SELECT * FROM notebook WHERE id = ?', [$id]));
}

function note_list(array $f): array
{
    $where = [];
    $p = [];
    if (!empty($f['channel_id'])) { $where[] = 'channel_id = ?'; $p[] = (int)$f['channel_id']; }
    if (!empty($f['kind'])) { $where[] = 'kind = ?'; $p[] = $f['kind']; }
    if (!empty($f['author'])) { $where[] = 'author = ?'; $p[] = $f['author']; }
    if (!empty($f['search'])) { $where[] = '(title LIKE ? OR body LIKE ? OR tags LIKE ?)'; $s = '%' . $f['search'] . '%'; array_push($p, $s, $s, $s); }
    if (!empty($f['since'])) { $where[] = 'created_at >= ?'; $p[] = is_numeric($f['since']) ? (int)$f['since'] : (int)strtotime((string)$f['since']); }
    $limit = max(1, min(200, (int)($f['limit'] ?? 30)));
    $offset = max(0, (int)($f['offset'] ?? 0));
    $sql = 'SELECT * FROM notebook' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY pinned DESC, created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
    return array_map('note_public', db_all($sql, $p));
}
