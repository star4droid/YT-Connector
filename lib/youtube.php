<?php
/**
 * YouTube Data API v3 + YouTube Analytics API v2 client for one stored channel.
 */
declare(strict_types=1);

const GOOGLE_SCOPES = [
    'https://www.googleapis.com/auth/youtube',
    'https://www.googleapis.com/auth/youtube.upload',
    'https://www.googleapis.com/auth/youtube.force-ssl',
    'https://www.googleapis.com/auth/yt-analytics.readonly',
    'https://www.googleapis.com/auth/yt-analytics-monetary.readonly',
];

const YT_API = 'https://www.googleapis.com/youtube/v3';
const YT_UPLOAD = 'https://www.googleapis.com/upload/youtube/v3';
const YT_ANALYTICS = 'https://youtubeanalytics.googleapis.com/v2/reports';

class YouTube
{
    public array $ch;

    public function __construct(array $channelRow)
    {
        $this->ch = $channelRow;
    }

    public static function forId(int $id): self
    {
        $c = channel_get($id);
        if (!$c) throw new UserError('Channel not found');
        return new self($c);
    }

    /* ---------------- auth ---------------- */

    public function accessToken(bool $force = false): string
    {
        $c = $this->ch;
        if (!$force && !empty($c['access_token_enc']) && (int)$c['token_expires'] > time() + 60) {
            $t = decrypt_str($c['access_token_enc']);
            if ($t) return $t;
        }
        $refresh = decrypt_str($c['refresh_token_enc'] ?? null);
        $secret = decrypt_str($c['client_secret_enc'] ?? null);
        if (!$refresh) throw new UserError('This channel has no refresh token yet. Authorize it from the dashboard (Channels → Authorize with Google).');
        if (!$c['client_id'] || !$secret) throw new UserError('Missing Google client id / secret for this channel.');
        $r = http_request('POST', 'https://oauth2.googleapis.com/token', ['form' => [
            'client_id' => $c['client_id'],
            'client_secret' => $secret,
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]]);
        $d = json_decode($r['body'], true) ?: [];
        if ($r['status'] !== 200 || empty($d['access_token'])) {
            $msg = ($d['error'] ?? 'token_error') . ': ' . ($d['error_description'] ?? $r['body']);
            db_update('channels', ['status' => 'error', 'last_error' => $msg, 'updated_at' => time()], 'id = ?', [$c['id']]);
            throw new UserError('Google refused the refresh token (' . $msg . '). Re-authorize the channel from the dashboard.');
        }
        $exp = time() + (int)($d['expires_in'] ?? 3600);
        db_update('channels', [
            'access_token_enc' => encrypt_str($d['access_token']),
            'token_expires' => $exp,
        ], 'id = ?', [$c['id']]);
        $this->ch['access_token_enc'] = encrypt_str($d['access_token']);
        $this->ch['token_expires'] = $exp;
        return $d['access_token'];
    }

    /* ---------------- generic request ---------------- */

    public function request(string $method, string $url, array $query = [], array $opts = [], bool $retried = false): array
    {
        if ($query) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        $orig = $opts;
        $opts['headers'] = array_merge($opts['headers'] ?? [], ['Authorization: Bearer ' . $this->accessToken()]);
        $r = http_request($method, $url, $opts);
        if ($r['status'] === 401 && !$retried) {
            $this->accessToken(true);
            return $this->request($method, $url, [], $orig, true);
        }
        if ($r['status'] >= 400) {
            $d = json_decode($r['body'], true);
            $msg = $d['error']['message'] ?? ($d['error_description'] ?? substr($r['body'], 0, 300));
            $reason = $d['error']['errors'][0]['reason'] ?? ($d['error']['status'] ?? '');
            throw new UserError('YouTube API error ' . $r['status'] . ($reason ? " ($reason)" : '') . ': ' . strip_tags((string)$msg));
        }
        return $r;
    }

    public function api(string $method, string $path, array $query = [], $json = null): array
    {
        $opts = [];
        if ($json !== null) $opts['json'] = $json;
        $r = $this->request($method, YT_API . $path, $query, $opts);
        if ($r['body'] === '') return [];
        return json_decode($r['body'], true) ?: [];
    }

    /* ---------------- channel ---------------- */

    public function channelInfo(): array
    {
        $d = $this->api('GET', '/channels', [
            'part' => 'snippet,statistics,brandingSettings,contentDetails,status,topicDetails',
            'mine' => 'true',
        ]);
        if (empty($d['items'][0])) throw new UserError('No YouTube channel found for these credentials.');
        return $d['items'][0];
    }

    /** Refresh cached title / avatar / stats in the DB. */
    public function syncChannel(): array
    {
        $info = $this->channelInfo();
        $s = $info['statistics'] ?? [];
        $stats = [
            'subscribers' => (int)($s['subscriberCount'] ?? 0),
            'views' => (int)($s['viewCount'] ?? 0),
            'videos' => (int)($s['videoCount'] ?? 0),
            'hidden_subscribers' => !empty($s['hiddenSubscriberCount']),
            'uploads_playlist' => $info['contentDetails']['relatedPlaylists']['uploads'] ?? null,
            'country' => $info['snippet']['country'] ?? null,
            'published_at' => $info['snippet']['publishedAt'] ?? null,
            'banner' => $info['brandingSettings']['image']['bannerExternalUrl'] ?? null,
            'synced_at' => time(),
        ];
        db_update('channels', [
            'yt_channel_id' => $info['id'],
            'title' => $info['snippet']['title'] ?? '',
            'handle' => $info['snippet']['customUrl'] ?? null,
            'thumbnail' => $info['snippet']['thumbnails']['high']['url'] ?? ($info['snippet']['thumbnails']['default']['url'] ?? null),
            'stats_json' => json_encode($stats),
            'status' => 'connected',
            'last_error' => null,
            'updated_at' => time(),
        ], 'id = ?', [$this->ch['id']]);
        $this->ch = channel_get((int)$this->ch['id']);
        snapshot_store((int)$this->ch['id'], $stats);
        return $info;
    }

    public function updateBranding(array $f): array
    {
        $info = $this->channelInfo();
        $b = $info['brandingSettings'] ?? [];
        $chan = $b['channel'] ?? [];
        foreach (['description', 'keywords', 'country', 'defaultLanguage', 'unsubscribedTrailer'] as $k) {
            if (array_key_exists($k, $f) && $f[$k] !== null) $chan[$k] = $f[$k];
        }
        if (isset($f['title'])) $chan['title'] = $f['title'];
        $body = ['id' => $info['id'], 'brandingSettings' => ['channel' => $chan]];
        return $this->api('PUT', '/channels', ['part' => 'brandingSettings'], $body);
    }

    /* ---------------- videos ---------------- */

    public function uploadsPlaylist(): string
    {
        $stats = $this->ch['stats_json'] ? json_decode($this->ch['stats_json'], true) : [];
        if (!empty($stats['uploads_playlist'])) return $stats['uploads_playlist'];
        $info = $this->syncChannel();
        return $info['contentDetails']['relatedPlaylists']['uploads'];
    }

    /** Lists the channel's videos (all privacy states) with stats. */
    public function listVideos(int $max = 25, ?string $pageToken = null, ?string $query = null, string $order = 'date'): array
    {
        $max = max(1, min(50, $max));
        if ($query) {
            $q = ['part' => 'id', 'forMine' => 'true', 'type' => 'video', 'maxResults' => $max, 'q' => $query, 'order' => $order];
            if ($pageToken) $q['pageToken'] = $pageToken;
            $d = $this->api('GET', '/search', $q);
            $ids = array_map(fn($i) => $i['id']['videoId'], $d['items'] ?? []);
        } else {
            $q = ['part' => 'contentDetails', 'playlistId' => $this->uploadsPlaylist(), 'maxResults' => $max];
            if ($pageToken) $q['pageToken'] = $pageToken;
            $d = $this->api('GET', '/playlistItems', $q);
            $ids = array_map(fn($i) => $i['contentDetails']['videoId'], $d['items'] ?? []);
        }
        $videos = $ids ? $this->videosByIds($ids) : [];
        return [
            'videos' => array_map([$this, 'compactVideo'], $videos),
            'next_page_token' => $d['nextPageToken'] ?? null,
            'prev_page_token' => $d['prevPageToken'] ?? null,
            'total' => $d['pageInfo']['totalResults'] ?? null,
        ];
    }

    public function videosByIds(array $ids, string $parts = 'snippet,statistics,status,contentDetails'): array
    {
        $out = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            $d = $this->api('GET', '/videos', ['part' => $parts, 'id' => implode(',', $chunk), 'maxResults' => 50]);
            foreach ($d['items'] ?? [] as $v) $out[$v['id']] = $v;
        }
        // keep the original order
        $sorted = [];
        foreach ($ids as $id) if (isset($out[$id])) $sorted[] = $out[$id];
        return $sorted;
    }

    public function getVideo(string $id, bool $full = true): array
    {
        $parts = $full
            ? 'snippet,statistics,status,contentDetails,topicDetails,recordingDetails,localizations,processingDetails,fileDetails,suggestions'
            : 'snippet,statistics,status,contentDetails';
        try {
            $v = $this->videosByIds([$id], $parts);
        } catch (UserError $e) {
            // fileDetails/suggestions/processingDetails are owner-only; retry with public parts
            $v = $this->videosByIds([$id], 'snippet,statistics,status,contentDetails,topicDetails');
        }
        if (!$v) throw new UserError('Video not found: ' . $id);
        return $v[0];
    }

    public function compactVideo(array $v): array
    {
        $sn = $v['snippet'] ?? [];
        $st = $v['statistics'] ?? [];
        $thumbs = $sn['thumbnails'] ?? [];
        $thumb = $thumbs['maxres']['url'] ?? $thumbs['high']['url'] ?? $thumbs['medium']['url'] ?? $thumbs['default']['url'] ?? null;
        return [
            'id' => $v['id'],
            'title' => $sn['title'] ?? '',
            'description' => str_limit($sn['description'] ?? '', 300),
            'published_at' => $sn['publishedAt'] ?? null,
            'thumbnail' => $thumb,
            'tags' => $sn['tags'] ?? [],
            'category_id' => $sn['categoryId'] ?? null,
            'privacy' => $v['status']['privacyStatus'] ?? null,
            'publish_at' => $v['status']['publishAt'] ?? null,
            'upload_status' => $v['status']['uploadStatus'] ?? null,
            'made_for_kids' => $v['status']['madeForKids'] ?? null,
            'duration' => $v['contentDetails']['duration'] ?? null,
            'duration_seconds' => iso8601_duration_seconds($v['contentDetails']['duration'] ?? null),
            'is_short' => iso8601_duration_seconds($v['contentDetails']['duration'] ?? null) <= 180,
            'views' => (int)($st['viewCount'] ?? 0),
            'likes' => (int)($st['likeCount'] ?? 0),
            'comments' => (int)($st['commentCount'] ?? 0),
            'url' => 'https://youtu.be/' . $v['id'],
        ];
    }

    /**
     * Updates any combination of snippet/status fields. Unspecified fields are preserved.
     */
    public function updateVideo(string $id, array $f): array
    {
        $cur = $this->getVideo($id, false);
        $sn = $cur['snippet'];
        $status = $cur['status'];
        $snippet = [
            'title' => $sn['title'],
            'description' => $sn['description'] ?? '',
            'categoryId' => $sn['categoryId'] ?? '22',
            'tags' => $sn['tags'] ?? [],
        ];
        if (!empty($sn['defaultLanguage'])) $snippet['defaultLanguage'] = $sn['defaultLanguage'];
        if (!empty($sn['defaultAudioLanguage'])) $snippet['defaultAudioLanguage'] = $sn['defaultAudioLanguage'];

        if (isset($f['title'])) $snippet['title'] = mb_substr((string)$f['title'], 0, 100);
        if (isset($f['description'])) $snippet['description'] = mb_substr((string)$f['description'], 0, 5000);
        if (isset($f['category_id'])) $snippet['categoryId'] = (string)$f['category_id'];
        if (isset($f['default_language'])) $snippet['defaultLanguage'] = $f['default_language'];
        if (isset($f['default_audio_language'])) $snippet['defaultAudioLanguage'] = $f['default_audio_language'];
        if (isset($f['tags'])) $snippet['tags'] = self::cleanTags($f['tags']);
        if (!empty($f['add_tags'])) $snippet['tags'] = self::cleanTags(array_merge($snippet['tags'], (array)$f['add_tags']));
        if (!empty($f['remove_tags'])) {
            $rm = array_map('mb_strtolower', (array)$f['remove_tags']);
            $snippet['tags'] = array_values(array_filter($snippet['tags'], fn($t) => !in_array(mb_strtolower($t), $rm, true)));
        }

        $newStatus = [
            'privacyStatus' => $status['privacyStatus'],
            'embeddable' => $status['embeddable'] ?? true,
            'license' => $status['license'] ?? 'youtube',
            'publicStatsViewable' => $status['publicStatsViewable'] ?? true,
            'selfDeclaredMadeForKids' => $status['madeForKids'] ?? false,
        ];
        if (!empty($status['publishAt'])) $newStatus['publishAt'] = $status['publishAt'];
        if (isset($f['privacy'])) $newStatus['privacyStatus'] = $f['privacy'];
        if (array_key_exists('publish_at', $f)) {
            if ($f['publish_at']) {
                $newStatus['publishAt'] = self::isoTime((string)$f['publish_at']);
                $newStatus['privacyStatus'] = 'private';
            } else {
                unset($newStatus['publishAt']);
            }
        }
        if (isset($f['made_for_kids'])) $newStatus['selfDeclaredMadeForKids'] = (bool)$f['made_for_kids'];
        if (isset($f['embeddable'])) $newStatus['embeddable'] = (bool)$f['embeddable'];
        if (isset($f['license'])) $newStatus['license'] = $f['license'];
        if (isset($f['public_stats_viewable'])) $newStatus['publicStatsViewable'] = (bool)$f['public_stats_viewable'];
        if (isset($f['contains_synthetic_media'])) $newStatus['containsSyntheticMedia'] = (bool)$f['contains_synthetic_media'];

        $body = ['id' => $id, 'snippet' => $snippet, 'status' => $newStatus];
        $parts = 'snippet,status';
        if (!empty($f['recording_date'])) {
            $body['recordingDetails'] = ['recordingDate' => self::isoTime((string)$f['recording_date'])];
            $parts .= ',recordingDetails';
        }
        return $this->api('PUT', '/videos', ['part' => $parts], $body);
    }

    public static function cleanTags($tags): array
    {
        if (is_string($tags)) $tags = preg_split('/\s*,\s*/', $tags);
        $out = [];
        $total = 0;
        foreach ((array)$tags as $t) {
            $t = trim(str_replace(['<', '>'], '', (string)$t));
            if ($t === '' || in_array(mb_strtolower($t), array_map('mb_strtolower', $out), true)) continue;
            $cost = mb_strlen($t) + (strpos($t, ' ') !== false ? 2 : 0) + 1;
            if ($total + $cost > 500) break; // YouTube's 500 char tag limit
            $total += $cost;
            $out[] = $t;
        }
        return $out;
    }

    public static function isoTime(string $s): string
    {
        $ts = strtotime($s);
        if ($ts === false) throw new UserError('Invalid date/time: ' . $s);
        return gmdate('Y-m-d\TH:i:s.000\Z', $ts);
    }

    public function deleteVideo(string $id): void
    {
        $this->request('DELETE', YT_API . '/videos', ['id' => $id]);
    }

    public function rateVideo(string $id, string $rating): void
    {
        $this->request('POST', YT_API . '/videos/rate', ['id' => $id, 'rating' => $rating]);
    }

    /** Sets a custom thumbnail from raw image bytes (JPEG/PNG, ≤ 2 MB). */
    public function setThumbnail(string $videoId, string $bytes): array
    {
        if (strlen($bytes) > 2 * 1024 * 1024) throw new UserError('Thumbnail must be ≤ 2 MB (YouTube limit).');
        $mime = self::sniffImage($bytes);
        if (!$mime) throw new UserError('Thumbnail must be a JPEG, PNG or GIF/BMP image.');
        $r = $this->request('POST', YT_UPLOAD . '/thumbnails/set', ['videoId' => $videoId, 'uploadType' => 'media'], [
            'body' => $bytes,
            'headers' => ['Content-Type: ' . $mime],
        ]);
        return json_decode($r['body'], true) ?: [];
    }

    public static function sniffImage(string $b): ?string
    {
        if (strncmp($b, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
        if (strncmp($b, "\x89PNG", 4) === 0) return 'image/png';
        if (strncmp($b, 'GIF8', 4) === 0) return 'image/gif';
        if (strncmp($b, 'BM', 2) === 0) return 'image/bmp';
        if (substr($b, 8, 4) === 'WEBP') return null;
        return null;
    }

    /* ---------------- resumable upload ---------------- */

    public function startUpload(array $meta, int $size, string $mime): string
    {
        $snippet = [
            'title' => mb_substr((string)($meta['title'] ?? 'Untitled'), 0, 100),
            'description' => mb_substr((string)($meta['description'] ?? ''), 0, 5000),
            'tags' => self::cleanTags($meta['tags'] ?? []),
            'categoryId' => (string)($meta['category_id'] ?? '22'),
        ];
        if (!empty($meta['default_language'])) $snippet['defaultLanguage'] = $meta['default_language'];
        $status = [
            'privacyStatus' => $meta['privacy'] ?? 'private',
            'selfDeclaredMadeForKids' => (bool)($meta['made_for_kids'] ?? false),
            'embeddable' => true,
            'license' => 'youtube',
        ];
        if (!empty($meta['publish_at'])) {
            $status['publishAt'] = self::isoTime((string)$meta['publish_at']);
            $status['privacyStatus'] = 'private';
        }
        if (isset($meta['contains_synthetic_media'])) $status['containsSyntheticMedia'] = (bool)$meta['contains_synthetic_media'];
        $q = ['uploadType' => 'resumable', 'part' => 'snippet,status'];
        if (isset($meta['notify_subscribers'])) $q['notifySubscribers'] = $meta['notify_subscribers'] ? 'true' : 'false';
        $r = $this->request('POST', YT_UPLOAD . '/videos', $q, [
            'json' => ['snippet' => $snippet, 'status' => $status],
            'headers' => [
                'X-Upload-Content-Length: ' . $size,
                'X-Upload-Content-Type: ' . ($mime ?: 'video/*'),
            ],
        ]);
        $loc = $r['headers']['location'] ?? '';
        if (!$loc) throw new UserError('YouTube did not return an upload session URL.');
        return $loc;
    }

    /**
     * Sends one chunk of the file to the resumable session.
     * Returns ['done'=>bool, 'sent'=>int, 'video'=>array|null]
     */
    public function pushChunk(string $session, string $path, int $offset, int $total, int $chunk): array
    {
        $len = min($chunk, $total - $offset);
        $fh = fopen($path, 'rb');
        if (!$fh) throw new RuntimeException('Cannot open staged file');
        fseek($fh, $offset);
        $end = $offset + $len - 1;
        $r = http_request('PUT', $session, [
            'file' => [$fh, $len],
            'timeout' => 600,
            'headers' => [
                'Authorization: Bearer ' . $this->accessToken(),
                'Content-Length: ' . $len,
                'Content-Range: bytes ' . $offset . '-' . $end . '/' . $total,
            ],
        ]);
        fclose($fh);
        return $this->parseUploadResponse($r, $offset);
    }

    public function queryUpload(string $session, int $total): array
    {
        $r = http_request('PUT', $session, [
            'body' => '',
            'headers' => [
                'Authorization: Bearer ' . $this->accessToken(),
                'Content-Length: 0',
                'Content-Range: bytes */' . $total,
            ],
        ]);
        return $this->parseUploadResponse($r, 0);
    }

    private function parseUploadResponse(array $r, int $offset): array
    {
        if ($r['status'] === 200 || $r['status'] === 201) {
            $v = json_decode($r['body'], true);
            return ['done' => true, 'sent' => PHP_INT_MAX, 'video' => $v];
        }
        if ($r['status'] === 308) {
            $range = $r['headers']['range'] ?? '';
            $sent = 0;
            if (preg_match('/bytes=0-(\d+)/', $range, $m)) $sent = (int)$m[1] + 1;
            return ['done' => false, 'sent' => $sent, 'video' => null];
        }
        $d = json_decode($r['body'], true);
        $msg = $d['error']['message'] ?? substr($r['body'], 0, 300);
        throw new UserError('Upload failed (' . $r['status'] . '): ' . strip_tags((string)$msg));
    }

    /* ---------------- playlists ---------------- */

    public function listPlaylists(int $max = 50, ?string $pageToken = null): array
    {
        $q = ['part' => 'snippet,status,contentDetails', 'mine' => 'true', 'maxResults' => max(1, min(50, $max))];
        if ($pageToken) $q['pageToken'] = $pageToken;
        $d = $this->api('GET', '/playlists', $q);
        return [
            'playlists' => array_map(fn($p) => [
                'id' => $p['id'],
                'title' => $p['snippet']['title'] ?? '',
                'description' => $p['snippet']['description'] ?? '',
                'privacy' => $p['status']['privacyStatus'] ?? null,
                'item_count' => $p['contentDetails']['itemCount'] ?? 0,
                'thumbnail' => $p['snippet']['thumbnails']['medium']['url'] ?? null,
                'published_at' => $p['snippet']['publishedAt'] ?? null,
            ], $d['items'] ?? []),
            'next_page_token' => $d['nextPageToken'] ?? null,
        ];
    }

    public function playlistItems(string $playlistId, int $max = 50, ?string $pageToken = null): array
    {
        $q = ['part' => 'snippet,contentDetails,status', 'playlistId' => $playlistId, 'maxResults' => max(1, min(50, $max))];
        if ($pageToken) $q['pageToken'] = $pageToken;
        $d = $this->api('GET', '/playlistItems', $q);
        return [
            'items' => array_map(fn($i) => [
                'item_id' => $i['id'],
                'video_id' => $i['contentDetails']['videoId'] ?? null,
                'title' => $i['snippet']['title'] ?? '',
                'position' => $i['snippet']['position'] ?? null,
                'thumbnail' => $i['snippet']['thumbnails']['medium']['url'] ?? null,
                'privacy' => $i['status']['privacyStatus'] ?? null,
            ], $d['items'] ?? []),
            'next_page_token' => $d['nextPageToken'] ?? null,
        ];
    }

    /* ---------------- comments ---------------- */

    public function listComments(?string $videoId, int $max = 20, ?string $pageToken = null, ?string $search = null, string $moderation = 'published', string $order = 'time'): array
    {
        $q = ['part' => 'snippet,replies', 'maxResults' => max(1, min(100, $max)), 'order' => $order, 'textFormat' => 'plainText'];
        if ($videoId) $q['videoId'] = $videoId;
        else $q['allThreadsRelatedToChannelId'] = $this->ch['yt_channel_id'];
        if ($pageToken) $q['pageToken'] = $pageToken;
        if ($search) $q['searchTerms'] = $search;
        if ($moderation && $moderation !== 'published') $q['moderationStatus'] = $moderation;
        $d = $this->api('GET', '/commentThreads', $q);
        $map = function ($c) {
            $s = $c['snippet'] ?? [];
            return [
                'comment_id' => $c['id'],
                'author' => $s['authorDisplayName'] ?? '',
                'author_channel_id' => $s['authorChannelId']['value'] ?? null,
                'author_avatar' => $s['authorProfileImageUrl'] ?? null,
                'text' => $s['textDisplay'] ?? ($s['textOriginal'] ?? ''),
                'likes' => $s['likeCount'] ?? 0,
                'published_at' => $s['publishedAt'] ?? null,
                'updated_at' => $s['updatedAt'] ?? null,
            ];
        };
        return [
            'threads' => array_map(function ($t) use ($map) {
                $top = $map($t['snippet']['topLevelComment']);
                $top['thread_id'] = $t['id'];
                $top['video_id'] = $t['snippet']['videoId'] ?? null;
                $top['reply_count'] = $t['snippet']['totalReplyCount'] ?? 0;
                $top['replies'] = array_map($map, $t['replies']['comments'] ?? []);
                return $top;
            }, $d['items'] ?? []),
            'next_page_token' => $d['nextPageToken'] ?? null,
        ];
    }

    /* ---------------- captions ---------------- */

    public function listCaptions(string $videoId): array
    {
        $d = $this->api('GET', '/captions', ['part' => 'snippet', 'videoId' => $videoId]);
        return array_map(fn($c) => [
            'caption_id' => $c['id'],
            'language' => $c['snippet']['language'] ?? null,
            'name' => $c['snippet']['name'] ?? '',
            'track_kind' => $c['snippet']['trackKind'] ?? null,
            'is_auto' => ($c['snippet']['trackKind'] ?? '') === 'asr',
            'is_draft' => $c['snippet']['isDraft'] ?? false,
            'last_updated' => $c['snippet']['lastUpdated'] ?? null,
        ], $d['items'] ?? []);
    }

    public function downloadCaption(string $captionId, string $format = 'srt'): string
    {
        $r = $this->request('GET', YT_API . '/captions/' . rawurlencode($captionId), ['tfmt' => $format]);
        return $r['body'];
    }

    public function uploadCaption(string $videoId, string $language, string $name, string $content, bool $draft = false): array
    {
        $boundary = 'ytc' . bin2hex(random_bytes(8));
        $meta = json_encode(['snippet' => [
            'videoId' => $videoId, 'language' => $language, 'name' => $name, 'isDraft' => $draft,
        ]]);
        $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n"
            . "--$boundary\r\nContent-Type: application/octet-stream\r\n\r\n$content\r\n--$boundary--";
        $r = $this->request('POST', YT_UPLOAD . '/captions', ['part' => 'snippet', 'uploadType' => 'multipart'], [
            'body' => $body,
            'headers' => ['Content-Type: multipart/related; boundary=' . $boundary],
        ]);
        return json_decode($r['body'], true) ?: [];
    }

    /* ---------------- analytics ---------------- */

    public function report(array $q): array
    {
        $q = array_merge(['ids' => 'channel==MINE'], array_filter($q, fn($v) => $v !== null && $v !== ''));
        $r = $this->request('GET', YT_ANALYTICS, $q);
        $d = json_decode($r['body'], true) ?: [];
        $cols = array_map(fn($h) => $h['name'], $d['columnHeaders'] ?? []);
        $rows = [];
        foreach ($d['rows'] ?? [] as $row) $rows[] = array_combine($cols, $row);
        return ['columns' => $cols, 'rows' => $rows];
    }

    public function categories(string $region = 'US', string $hl = 'en'): array
    {
        $d = $this->api('GET', '/videoCategories', ['part' => 'snippet', 'regionCode' => $region, 'hl' => $hl]);
        $out = [];
        foreach ($d['items'] ?? [] as $c) {
            if (!empty($c['snippet']['assignable'])) $out[] = ['id' => $c['id'], 'title' => $c['snippet']['title']];
        }
        return $out;
    }
}

/* ---------------- helpers outside the class ---------------- */

function snapshot_store(int $channelId, array $stats): void
{
    $day = gmdate('Y-m-d');
    $row = db_one('SELECT id FROM snapshots WHERE channel_id = ? AND day = ?', [$channelId, $day]);
    $data = [
        'subscribers' => (int)($stats['subscribers'] ?? 0),
        'views' => (int)($stats['views'] ?? 0),
        'videos' => (int)($stats['videos'] ?? 0),
        'created_at' => time(),
    ];
    if ($row) db_update('snapshots', $data, 'id = ?', [$row['id']]);
    else db_insert('snapshots', $data + ['channel_id' => $channelId, 'day' => $day]);
}

/**
 * Ready-made analytics bundle used by the dashboard and the analytics_summary tool.
 */
function analytics_summary(YouTube $yt, int $days = 28, ?string $videoId = null): array
{
    $days = max(1, min(365, $days));
    $end = gmdate('Y-m-d', time() - 86400);           // analytics lag ~1-2 days
    $start = gmdate('Y-m-d', strtotime($end) - ($days - 1) * 86400);
    $pEnd = gmdate('Y-m-d', strtotime($start) - 86400);
    $pStart = gmdate('Y-m-d', strtotime($pEnd) - ($days - 1) * 86400);
    $filters = $videoId ? 'video==' . $videoId : null;
    $metrics = 'views,estimatedMinutesWatched,averageViewDuration,averageViewPercentage,subscribersGained,subscribersLost,likes,comments,shares';

    $safe = function (callable $fn) {
        try { return $fn(); } catch (Throwable $e) { return ['error' => $e->getMessage(), 'rows' => []]; }
    };

    $totals = $safe(fn() => $yt->report(['startDate' => $start, 'endDate' => $end, 'metrics' => $metrics, 'filters' => $filters]));
    $prev = $safe(fn() => $yt->report(['startDate' => $pStart, 'endDate' => $pEnd, 'metrics' => $metrics, 'filters' => $filters]));
    $daily = $safe(fn() => $yt->report([
        'startDate' => $start, 'endDate' => $end, 'dimensions' => 'day', 'sort' => 'day', 'filters' => $filters,
        'metrics' => 'views,estimatedMinutesWatched,subscribersGained,subscribersLost,likes',
    ]));
    $traffic = $safe(fn() => $yt->report([
        'startDate' => $start, 'endDate' => $end, 'dimensions' => 'insightTrafficSourceType',
        'metrics' => 'views,estimatedMinutesWatched', 'sort' => '-views', 'filters' => $filters,
    ]));
    $geo = $safe(fn() => $yt->report([
        'startDate' => $start, 'endDate' => $end, 'dimensions' => 'country',
        'metrics' => 'views,estimatedMinutesWatched', 'sort' => '-views', 'maxResults' => 10, 'filters' => $filters,
    ]));
    $devices = $safe(fn() => $yt->report([
        'startDate' => $start, 'endDate' => $end, 'dimensions' => 'deviceType',
        'metrics' => 'views', 'sort' => '-views', 'filters' => $filters,
    ]));
    $demo = $safe(fn() => $yt->report([
        'startDate' => $start, 'endDate' => $end, 'dimensions' => 'ageGroup,gender',
        'metrics' => 'viewerPercentage', 'filters' => $filters,
    ]));

    $out = [
        'period' => ['start' => $start, 'end' => $end, 'days' => $days],
        'previous_period' => ['start' => $pStart, 'end' => $pEnd],
        'totals' => $totals['rows'][0] ?? new stdClass(),
        'previous_totals' => $prev['rows'][0] ?? new stdClass(),
        'daily' => $daily['rows'] ?? [],
        'traffic_sources' => $traffic['rows'] ?? [],
        'top_countries' => $geo['rows'] ?? [],
        'devices' => $devices['rows'] ?? [],
        'demographics' => $demo['rows'] ?? [],
    ];

    if ($videoId) {
        $ret = $safe(fn() => $yt->report([
            'startDate' => $start, 'endDate' => $end, 'dimensions' => 'elapsedVideoTimeRatio',
            'metrics' => 'audienceWatchRatio,relativeRetentionPerformance', 'filters' => $filters,
        ]));
        $out['retention'] = $ret['rows'] ?? [];
    } else {
        $top = $safe(fn() => $yt->report([
            'startDate' => $start, 'endDate' => $end, 'dimensions' => 'video',
            'metrics' => 'views,estimatedMinutesWatched,averageViewPercentage,subscribersGained,likes',
            'sort' => '-views', 'maxResults' => 10,
        ]));
        $rows = $top['rows'] ?? [];
        if ($rows) {
            try {
                $meta = [];
                foreach ($yt->videosByIds(array_column($rows, 'video'), 'snippet') as $v) {
                    $meta[$v['id']] = ['title' => $v['snippet']['title'], 'thumbnail' => $v['snippet']['thumbnails']['medium']['url'] ?? null];
                }
                foreach ($rows as &$r) {
                    $r['title'] = $meta[$r['video']]['title'] ?? $r['video'];
                    $r['thumbnail'] = $meta[$r['video']]['thumbnail'] ?? null;
                }
                unset($r);
            } catch (Throwable $e) { /* titles are optional */ }
        }
        $out['top_videos'] = $rows;
    }
    $errors = array_filter([
        'totals' => $totals['error'] ?? null, 'daily' => $daily['error'] ?? null,
    ]);
    if ($errors) $out['errors'] = $errors;
    return $out;
}
