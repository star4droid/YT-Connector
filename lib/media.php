<?php
/**
 * Stock media sources the AI can search: Pixabay (API key) and Wikimedia Commons (no key).
 * Results can be fed straight into set_thumbnail (image_url) or upload_video (source_url).
 */
declare(strict_types=1);

const PIXABAY_IMAGE_CATEGORIES = ['backgrounds', 'fashion', 'nature', 'science', 'education', 'feelings', 'health', 'people', 'religion', 'places', 'animals', 'industry', 'computer', 'food', 'sports', 'transportation', 'travel', 'buildings', 'business', 'music'];
const PIXABAY_LANGS = ['cs', 'da', 'de', 'en', 'es', 'fr', 'id', 'it', 'hu', 'nl', 'no', 'pl', 'pt', 'ro', 'sk', 'fi', 'sv', 'tr', 'vi', 'th', 'bg', 'ru', 'el', 'ja', 'ko', 'zh'];
const MEDIA_UA = 'YT-Connector/1.0 (https://github.com/star4droid/YT-Connector)';

/* ---------------- settings ---------------- */

function media_source_enabled(string $src): bool
{
    return setting('src_' . $src, '1') === '1';
}

function pixabay_key(): ?string
{
    $enc = (string)setting('pixabay_key_enc', '');
    return $enc !== '' ? decrypt_str($enc) : null;
}

function media_settings_public(): array
{
    $k = pixabay_key();
    return [
        'pixabay' => [
            'enabled' => media_source_enabled('pixabay'),
            'has_key' => (bool)$k,
            'key_hint' => $k ? substr($k, 0, 4) . '…' . substr($k, -4) : null,
            'safesearch' => setting('pixabay_safesearch', '1') === '1',
        ],
        'wikimedia' => ['enabled' => media_source_enabled('wikimedia')],
        'gd' => extension_loaded('gd'),
    ];
}

/* ---------------- tiny cache (Pixabay asks for 24h caching) ---------------- */

function cache_get(string $key)
{
    try {
        $row = db_one('SELECT v, expires FROM cache WHERE k = ?', [hash('sha256', $key)]);
    } catch (Throwable $e) { return null; }
    if (!$row || (int)$row['expires'] < time()) return null;
    return json_decode($row['v'], true);
}

function cache_set(string $key, $value, int $ttl): void
{
    try {
        $k = hash('sha256', $key);
        db_exec('DELETE FROM cache WHERE k = ?', [$k]);
        db_insert('cache', ['k' => $k, 'v' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'expires' => time() + $ttl]);
        if (mt_rand(1, 30) === 1) db_exec('DELETE FROM cache WHERE expires < ?', [time()]);
    } catch (Throwable $e) { /* cache is optional */ }
}

/* ---------------- Pixabay ---------------- */

function pixabay_search(array $a, ?string $keyOverride = null): array
{
    if (!$keyOverride && !media_source_enabled('pixabay')) throw new UserError('Pixabay is disabled in the dashboard (Media sources).');
    $key = $keyOverride ?: pixabay_key();
    if (!$key) throw new UserError('No Pixabay API key yet. Add it in the dashboard → Media sources (get one free at https://pixabay.com/api/docs/).');
    $video = ($a['type'] ?? 'image') === 'video';
    $q = [
        'key' => $key,
        'q' => mb_substr(trim((string)($a['query'] ?? '')), 0, 100),
        'per_page' => max(3, min(200, (int)($a['per_page'] ?? 12))),
        'page' => max(1, (int)($a['page'] ?? 1)),
        'order' => in_array($a['order'] ?? '', ['popular', 'latest'], true) ? $a['order'] : 'popular',
        'safesearch' => array_key_exists('safesearch', $a) ? ($a['safesearch'] ? 'true' : 'false') : (setting('pixabay_safesearch', '1') === '1' ? 'true' : 'false'),
    ];
    if (!empty($a['id'])) $q['id'] = (string)$a['id'];
    if (!empty($a['lang']) && in_array($a['lang'], PIXABAY_LANGS, true)) $q['lang'] = $a['lang'];
    if (!empty($a['category']) && in_array($a['category'], PIXABAY_IMAGE_CATEGORIES, true)) $q['category'] = $a['category'];
    if (!empty($a['min_width'])) $q['min_width'] = (int)$a['min_width'];
    if (!empty($a['min_height'])) $q['min_height'] = (int)$a['min_height'];
    if (!empty($a['editors_choice'])) $q['editors_choice'] = 'true';
    if ($video) {
        if (!empty($a['video_type']) && in_array($a['video_type'], ['all', 'film', 'animation'], true)) $q['video_type'] = $a['video_type'];
    } else {
        if (!empty($a['image_type']) && in_array($a['image_type'], ['all', 'photo', 'illustration', 'vector'], true)) $q['image_type'] = $a['image_type'];
        if (!empty($a['orientation']) && in_array($a['orientation'], ['all', 'horizontal', 'vertical'], true)) $q['orientation'] = $a['orientation'];
        if (!empty($a['colors'])) {
            $allowed = ['grayscale', 'transparent', 'red', 'orange', 'yellow', 'green', 'turquoise', 'blue', 'lilac', 'pink', 'white', 'gray', 'black', 'brown'];
            $cols = array_values(array_intersect(array_map('trim', is_array($a['colors']) ? $a['colors'] : explode(',', (string)$a['colors'])), $allowed));
            if ($cols) $q['colors'] = implode(',', $cols);
        }
    }
    $url = 'https://pixabay.com/api/' . ($video ? 'videos/' : '') . '?' . http_build_query($q);
    $cacheKey = 'pixabay:' . preg_replace('/key=[^&]+/', '', $url);
    $data = $keyOverride ? null : cache_get($cacheKey);
    $rate = null;
    if ($data === null) {
        $r = http_request('GET', $url, ['timeout' => 30]);
        if ($r['status'] === 429) throw new UserError('Pixabay rate limit reached (100 requests/minute). Try again in a minute.');
        if ($r['status'] >= 400) throw new UserError('Pixabay error ' . $r['status'] . ': ' . trim(strip_tags($r['body'])));
        $data = json_decode($r['body'], true);
        if (!is_array($data)) throw new UserError('Unexpected Pixabay response.');
        $rate = ['limit' => $r['headers']['x-ratelimit-limit'] ?? null, 'remaining' => $r['headers']['x-ratelimit-remaining'] ?? null];
        if (!$keyOverride) cache_set($cacheKey, $data, 86400);
    }
    $hits = [];
    foreach ($data['hits'] ?? [] as $hit) {
        $base = [
            'source' => 'pixabay',
            'id' => $hit['id'],
            'page_url' => $hit['pageURL'] ?? null,
            'tags' => $hit['tags'] ?? '',
            'user' => $hit['user'] ?? '',
            'views' => $hit['views'] ?? 0,
            'downloads' => $hit['downloads'] ?? 0,
            'likes' => $hit['likes'] ?? 0,
            'attribution' => ($video ? 'Video' : 'Image') . ' by ' . ($hit['user'] ?? 'unknown') . ' from Pixabay',
            'license' => 'Pixabay Content License (free for commercial use, no attribution required)',
        ];
        if ($video) {
            $sizes = [];
            foreach (['large', 'medium', 'small', 'tiny'] as $s) {
                $v = $hit['videos'][$s] ?? null;
                if ($v && !empty($v['url'])) $sizes[$s] = ['url' => $v['url'], 'width' => $v['width'], 'height' => $v['height'], 'bytes' => $v['size'] ?? 0, 'thumbnail' => $v['thumbnail'] ?? null];
            }
            $first = reset($sizes) ?: [];
            $hits[] = $base + [
                'type' => $hit['type'] ?? 'film',
                'duration' => $hit['duration'] ?? 0,
                'thumbnail' => $sizes['medium']['thumbnail'] ?? ($first['thumbnail'] ?? null),
                'videos' => $sizes,
                'best_url' => $sizes['medium']['url'] ?? ($first['url'] ?? null),
            ];
        } else {
            $hits[] = $base + [
                'type' => $hit['type'] ?? 'photo',
                'width' => $hit['imageWidth'] ?? null,
                'height' => $hit['imageHeight'] ?? null,
                'preview_url' => $hit['previewURL'] ?? null,
                'thumbnail' => $hit['webformatURL'] ?? null,
                'web_url' => $hit['webformatURL'] ?? null,
                'large_url' => $hit['largeImageURL'] ?? null,
                'full_hd_url' => $hit['fullHDURL'] ?? null,
                'best_url' => $hit['largeImageURL'] ?? ($hit['webformatURL'] ?? null),
            ];
        }
    }
    return [
        'source' => 'pixabay',
        'type' => $video ? 'video' : 'image',
        'query' => $q['q'],
        'total' => (int)($data['totalHits'] ?? 0),
        'page' => $q['page'],
        'per_page' => $q['per_page'],
        'results' => $hits,
        'rate_limit' => $rate,
        'notes' => 'Show users the results come from Pixabay. Image URLs are temporary (webformat valid 24h) — to use one permanently, download it (set_thumbnail and upload_video do this automatically).',
    ];
}

/* ---------------- Wikimedia Commons ---------------- */

function wikimedia_search(array $a): array
{
    if (!media_source_enabled('wikimedia')) throw new UserError('Wikimedia Commons is disabled in the dashboard (Media sources).');
    $query = trim((string)($a['query'] ?? ''));
    if ($query === '') throw new UserError('query is required');
    $limit = max(1, min(50, (int)($a['limit'] ?? 9)));
    $offset = max(0, (int)($a['offset'] ?? 0));
    $media = in_array($a['media_type'] ?? 'image', ['image', 'video', 'audio'], true) ? ($a['media_type'] ?? 'image') : 'image';
    $free = ($a['license'] ?? 'all') === 'no_restrictions';
    $thumbW = max(120, min(1280, (int)($a['thumb_width'] ?? 400)));
    $filetype = ['image' => 'filetype:bitmap|drawing', 'video' => 'filetype:video', 'audio' => 'filetype:audio'][$media];
    // fetch extra rows when filtering licenses afterwards
    $fetch = $free ? min(50, $limit * 3) : $limit;
    $params = [
        'action' => 'query',
        'format' => 'json',
        'formatversion' => '2',
        'generator' => 'search',
        'gsrsearch' => $query . ' ' . $filetype,
        'gsrnamespace' => '6',
        'gsrlimit' => (string)$fetch,
        'gsroffset' => (string)$offset,
        'prop' => 'imageinfo',
        'iiprop' => 'url|size|mime|extmetadata',
        'iiurlwidth' => (string)$thumbW,
        'iiextmetadatafilter' => 'LicenseShortName|LicenseUrl|Artist|Credit|ImageDescription|AttributionRequired|UsageTerms|DateTimeOriginal|ObjectName',
        'iiextmetadatalanguage' => $a['lang'] ?? 'en',
    ];
    $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query($params);
    $data = cache_get('wm:' . $url);
    if ($data === null) {
        for ($try = 0; $try < 4; $try++) {
            $r = http_request('GET', $url, ['timeout' => 30, 'headers' => ['User-Agent: ' . MEDIA_UA, 'Api-User-Agent: ' . MEDIA_UA]]);
            if ($r['status'] !== 429 && $r['status'] < 500) break;
            usleep((int)(min(4, (int)($r['headers']['retry-after'] ?? 0) ?: (1 + $try)) * 1000000));
        }
        if ($r['status'] === 429) throw new UserError('Wikimedia Commons is rate-limiting this server right now. Try again in a minute.');
        if ($r['status'] >= 400) throw new UserError('Wikimedia Commons error ' . $r['status']);
        $data = json_decode($r['body'], true);
        if (!is_array($data)) throw new UserError('Unexpected Wikimedia response.');
        cache_set('wm:' . $url, $data, 6 * 3600);
    }
    $pages = $data['query']['pages'] ?? [];
    usort($pages, fn($x, $y) => ($x['index'] ?? 0) <=> ($y['index'] ?? 0));
    $clean = fn($s) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $out = [];
    foreach ($pages as $p) {
        $ii = $p['imageinfo'][0] ?? null;
        if (!$ii) continue;
        $m = $ii['extmetadata'] ?? [];
        $lic = $clean($m['LicenseShortName']['value'] ?? '');
        $isFree = (bool)preg_match('/(cc0|public domain|pd[- ]|no restrictions)/i', $lic . ' ' . ($m['UsageTerms']['value'] ?? ''));
        if ($free && !$isFree) continue;
        $author = $clean($m['Artist']['value'] ?? '');
        $title = preg_replace('/^File:/', '', (string)$p['title']);
        $out[] = [
            'source' => 'wikimedia',
            'title' => $title,
            'page_url' => $ii['descriptionurl'] ?? null,
            'url' => $ii['url'] ?? null,
            'thumbnail' => $ii['thumburl'] ?? null,
            'best_url' => ($media === 'image' && ($ii['width'] ?? 0) > 2000 && !empty($ii['thumburl'])) ? preg_replace('#/\d+px-#', '/1920px-', $ii['thumburl']) : ($ii['url'] ?? null),
            'width' => $ii['width'] ?? null,
            'height' => $ii['height'] ?? null,
            'duration' => isset($ii['duration']) ? round((float)$ii['duration'], 1) : null,
            'size_bytes' => $ii['size'] ?? null,
            'mime' => $ii['mime'] ?? null,
            'license' => $lic ?: 'see page',
            'license_url' => $m['LicenseUrl']['value'] ?? null,
            'public_domain_or_cc0' => $isFree,
            'attribution_required' => strtolower((string)($m['AttributionRequired']['value'] ?? 'true')) !== 'false',
            'author' => mb_substr($author, 0, 200),
            'description' => mb_substr($clean($m['ImageDescription']['value'] ?? ''), 0, 300),
            'attribution' => ($author ?: 'Unknown author') . ', ' . ($lic ?: 'see license') . ', via Wikimedia Commons',
        ];
        if (count($out) >= $limit) break;
    }
    return [
        'source' => 'wikimedia',
        'media_type' => $media,
        'query' => $query,
        'offset' => $offset,
        'next_offset' => $data['continue']['gsroffset'] ?? null,
        'results' => $out,
        'notes' => 'Respect each file\'s license: credit the author as in "attribution" unless the file is public domain / CC0.',
    ];
}

/* ---------------- preview composite (one image with numbered thumbnails) ---------------- */

/** Downloads several images in parallel. Returns [index => bytes]. */
function fetch_many(array $urls, int $timeout = 15): array
{
    $mh = curl_multi_init();
    $hs = [];
    foreach ($urls as $i => $u) {
        if (!$u) continue;
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => MEDIA_UA, CURLOPT_MAXFILESIZE => 3 * 1024 * 1024]);
        curl_multi_add_handle($mh, $ch);
        $hs[$i] = $ch;
    }
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $i => $ch) {
        $body = curl_multi_getcontent($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && $body) $out[$i] = $body;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * Builds a JPEG grid of the result thumbnails with index numbers (1-based),
 * so the AI can look at many candidates in a single image.
 */
function media_composite(array $thumbUrls): ?string
{
    if (!$thumbUrls || !extension_loaded('gd')) return null;
    $imgs = fetch_many(array_values($thumbUrls));
    if (!$imgs) return null;
    $n = count($thumbUrls);
    $cols = $n <= 4 ? 2 : ($n <= 9 ? 3 : 4);
    $rows = (int)ceil($n / $cols);
    $cw = 320; $chh = 220; $gap = 6;
    $W = $cols * $cw + ($cols + 1) * $gap;
    $H = $rows * $chh + ($rows + 1) * $gap;
    $canvas = imagecreatetruecolor($W, $H);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 24, 26, 34));
    $white = imagecolorallocate($canvas, 255, 255, 255);
    $badge = imagecolorallocate($canvas, 240, 48, 79);
    foreach (array_values($thumbUrls) as $i => $u) {
        $x = $gap + ($i % $cols) * ($cw + $gap);
        $y = $gap + intdiv($i, $cols) * ($chh + $gap);
        if (isset($imgs[$i])) {
            $src = @imagecreatefromstring($imgs[$i]);
            if ($src) {
                $sw = imagesx($src); $sh = imagesy($src);
                $scale = min($cw / $sw, $chh / $sh);
                $dw = (int)($sw * $scale); $dh = (int)($sh * $scale);
                imagecopyresampled($canvas, $src, $x + intdiv($cw - $dw, 2), $y + intdiv($chh - $dh, 2), 0, 0, $dw, $dh, $sw, $sh);
                imagedestroy($src);
            }
        }
        $label = (string)($i + 1);
        $bw = 12 + strlen($label) * 10;
        imagefilledrectangle($canvas, $x, $y, $x + $bw, $y + 22, $badge);
        imagestring($canvas, 5, $x + 6, $y + 3, $label, $white);
    }
    ob_start();
    imagejpeg($canvas, null, 78);
    imagedestroy($canvas);
    return ob_get_clean() ?: null;
}

/** Hosts whose images the AI may preview through the server (prevents SSRF to internal hosts). */
function media_preview_allowed(string $url): bool
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if (!preg_match('#^https://#i', $url)) return false;
    foreach (['pixabay.com', 'wikimedia.org', 'ytimg.com', 'ggpht.com', 'googleusercontent.com'] as $ok) {
        if ($host === $ok || str_ends_with($host, '.' . $ok)) return true;
    }
    return false;
}
