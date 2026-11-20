<?php
/**
 * Tool catalog shared by the MCP server (for Claude) and the dashboard API.
 * Each tool: name, title, description, schema, access ('none'|'read'|'full'), readOnly, destructive, run(args, ctx).
 */
declare(strict_types=1);

function S_str(string $desc, array $extra = []): array { return ['type' => 'string', 'description' => $desc] + $extra; }
function S_int(string $desc, array $extra = []): array { return ['type' => 'integer', 'description' => $desc] + $extra; }
function S_bool(string $desc): array { return ['type' => 'boolean', 'description' => $desc]; }
function S_arr(string $desc, array $items = ['type' => 'string']): array { return ['type' => 'array', 'description' => $desc, 'items' => $items]; }
function S_obj(array $props, array $required = []): array
{
    $o = ['type' => 'object', 'properties' => (object)$props];
    if ($required) $o['required'] = $required;
    return $o;
}

const CH_ARG_DESC = 'Channel to act on: internal id, YouTube channel id (UC…), @handle or title. Optional when only one channel exists.';

/** Throws when the AI is not allowed this level of access to a channel. */
function access_check(array $ch, string $need, array $ctx): void
{
    if (!empty($ctx['admin'])) return;
    $have = $ch['ai_access'] ?: 'full';
    $rank = ['none' => 0, 'read' => 1, 'full' => 2];
    if (($rank[$have] ?? 0) < ($rank[$need] ?? 2)) {
        $msg = $have === 'none'
            ? 'AI access to channel "' . $ch['title'] . '" is disabled by the owner.'
            : 'Channel "' . $ch['title'] . '" is read-only for AI. Ask the owner to grant full control in the dashboard (Channels → AI access).';
        throw new UserError($msg);
    }
}

function tool_channel(array $a, string $need, array $ctx): array
{
    $ch = channel_resolve($a['channel'] ?? null);
    access_check($ch, $need, $ctx);
    return $ch;
}

function tools_catalog(): array
{
    static $tools = null;
    if ($tools !== null) return $tools;
    $ch = ['channel' => S_str(CH_ARG_DESC)];
    $videoArg = ['video_id' => S_str('YouTube video id (11 chars) or a youtube.com / youtu.be URL.')];

    $tools = [
        /* ---------- channels ---------- */
        [
            'name' => 'list_channels', 'title' => 'List channels', 'access' => 'none', 'readOnly' => true,
            'description' => 'Lists every YouTube channel connected to this site with cached stats and the AI access level (none / read / full). Start here.',
            'schema' => S_obj([]),
            'run' => function ($a, $ctx) {
                $rows = db_all('SELECT * FROM channels ORDER BY id');
                if (empty($ctx['admin'])) $rows = array_values(array_filter($rows, fn($r) => $r['ai_access'] !== 'none'));
                return ['channels' => array_map('channel_public', $rows), 'count' => count($rows)];
            },
        ],
        [
            'name' => 'get_channel', 'title' => 'Get channel details', 'access' => 'read', 'readOnly' => true,
            'description' => 'Fetches live channel details from YouTube: title, description, keywords, statistics, branding, country, and refreshes cached stats.',
            'schema' => S_obj($ch),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'read', $ctx);
                $yt = new YouTube($c);
                $info = $yt->syncChannel();
                return [
                    'channel' => channel_public($yt->ch),
                    'youtube' => [
                        'id' => $info['id'],
                        'title' => $info['snippet']['title'] ?? '',
                        'description' => $info['snippet']['description'] ?? '',
                        'custom_url' => $info['snippet']['customUrl'] ?? null,
                        'published_at' => $info['snippet']['publishedAt'] ?? null,
                        'country' => $info['snippet']['country'] ?? null,
                        'keywords' => $info['brandingSettings']['channel']['keywords'] ?? '',
                        'default_language' => $info['brandingSettings']['channel']['defaultLanguage'] ?? null,
                        'statistics' => $info['statistics'] ?? [],
                        'topics' => $info['topicDetails']['topicCategories'] ?? [],
                        'made_for_kids' => $info['status']['madeForKids'] ?? null,
                        'url' => 'https://www.youtube.com/channel/' . $info['id'],
                    ],
                ];
            },
        ],
        [
            'name' => 'update_channel', 'title' => 'Update channel branding', 'access' => 'full', 'readOnly' => false,
            'description' => 'Updates the channel description, keywords (space separated, quote multi-word), country (ISO code), default language, or unsubscribed trailer video id.',
            'schema' => S_obj($ch + [
                'description' => S_str('New channel description (max 1000 chars).'),
                'keywords' => S_str('Channel keywords, space separated. Wrap multi-word keywords in double quotes.'),
                'country' => S_str('ISO 3166-1 alpha-2 country code, e.g. US, SA, SD.'),
                'default_language' => S_str('BCP-47 language code, e.g. en, ar.'),
                'unsubscribed_trailer' => S_str('Video id shown as trailer to non-subscribers.'),
            ]),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'full', $ctx);
                $yt = new YouTube($c);
                $yt->updateBranding([
                    'description' => $a['description'] ?? null,
                    'keywords' => $a['keywords'] ?? null,
                    'country' => $a['country'] ?? null,
                    'defaultLanguage' => $a['default_language'] ?? null,
                    'unsubscribedTrailer' => $a['unsubscribed_trailer'] ?? null,
                ]);
                return ['ok' => true, 'message' => 'Channel branding updated.'];
            },
        ],

        /* ---------- videos ---------- */
        [
            'name' => 'list_videos', 'title' => 'List channel videos', 'access' => 'read', 'readOnly' => true,
            'description' => 'Lists the channel\'s uploaded videos (public, unlisted, private and scheduled) newest first with views, likes, comments, duration, privacy and tags. Use page_token for more. Use query to search the channel (costs more quota).',
            'schema' => S_obj($ch + [
                'max_results' => S_int('1-50, default 25.'),
                'page_token' => S_str('Token from a previous call to get the next page.'),
                'query' => S_str('Optional search text within this channel\'s videos.'),
                'order' => S_str('With query only: date | viewCount | rating | title | relevance.'),
            ]),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'read', $ctx);
                return (new YouTube($c))->listVideos((int)($a['max_results'] ?? 25), $a['page_token'] ?? null, $a['query'] ?? null, $a['order'] ?? 'date');
            },
        ],
        [
            'name' => 'get_video', 'title' => 'Get video details', 'access' => 'read', 'readOnly' => true,
            'description' => 'Full details of one video: title, full description, tags, category, privacy, schedule, duration, definition, captions flag, stats, topics, processing status, file details and YouTube improvement suggestions (owner only). Works on any public video id too, for competitor research.',
            'schema' => S_obj($ch + $videoArg, ['video_id']),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'read', $ctx);
                $v = (new YouTube($c))->getVideo(video_id_from($a['video_id'] ?? ''));
                $v['url'] = 'https://youtu.be/' . $v['id'];
                $v['studio_url'] = 'https://studio.youtube.com/video/' . $v['id'] . '/edit';
                $v['duration_seconds'] = iso8601_duration_seconds($v['contentDetails']['duration'] ?? null);
                return $v;
            },
        ],
        [
            'name' => 'get_video_visuals', 'title' => 'See video thumbnail & frames', 'access' => 'read', 'readOnly' => true,
            'description' => 'Returns the current thumbnail plus 3 auto-captured frames from the start, middle and end of the video as images, so you can visually analyse the content and judge the thumbnail.',
            'schema' => S_obj($ch + $videoArg + ['include_frames' => S_bool('Also return the 3 auto frames (default true).')], ['video_id']),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'read', $ctx);
                $id = video_id_from($a['video_id'] ?? '');
                $v = (new YouTube($c))->getVideo($id, false);
                $th = $v['snippet']['thumbnails'] ?? [];
                $main = $th['maxres']['url'] ?? $th['standard']['url'] ?? $th['high']['url'] ?? ('https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg');
                $urls = ['Current thumbnail' => $main];
                if (($a['include_frames'] ?? true) !== false) {
                    foreach ([1 => 'Frame ~25%', 2 => 'Frame ~50%', 3 => 'Frame ~75%'] as $n => $label) {
                        $urls[$label] = 'https://i.ytimg.com/vi/' . $id . '/hq' . $n . '.jpg';
                    }
                }
                $content = [['type' => 'text', 'text' => 'Video: ' . ($v['snippet']['title'] ?? $id) . ' (' . $id . ')']];
                foreach ($urls as $label => $u) {
                    try {
                        $r = http_request('GET', $u, ['timeout' => 20]);
                        if ($r['status'] === 200 && strlen($r['body']) > 1500) {
                            $content[] = ['type' => 'text', 'text' => $label . ':'];
                            $content[] = ['type' => 'image', 'data' => base64_encode($r['body']), 'mimeType' => YouTube::sniffImage($r['body']) ?: 'image/jpeg'];
                        }
                    } catch (Throwable $e) { /* skip unavailable frame */ }
                }
                return ['__content' => $content];
            },
        ],
        [
            'name' => 'update_video', 'title' => 'Edit video metadata', 'access' => 'full', 'readOnly' => false,
            'description' => 'Edits a video. Only the fields you pass change; everything else is kept. Supports title, description, tags (replace) or add_tags/remove_tags, category, privacy (public|unlisted|private), publish_at (schedule — ISO date-time, sets privacy to private until then; pass empty string to clear), made_for_kids, default language, embeddable, license, public stats, synthetic-media disclosure, recording date.',
            'schema' => S_obj($ch + $videoArg + [
                'title' => S_str('Max 100 characters.'),
                'description' => S_str('Max 5000 characters.'),
                'tags' => S_arr('Replaces all tags (total ≤ 500 chars).'),
                'add_tags' => S_arr('Tags to add, keeping existing ones.'),
                'remove_tags' => S_arr('Tags to remove.'),
                'category_id' => S_str('Category id, see list_categories (e.g. 22 People & Blogs, 20 Gaming, 27 Education, 28 Science & Tech).'),
                'privacy' => S_str('public | unlisted | private', ['enum' => ['public', 'unlisted', 'private']]),
                'publish_at' => S_str('Schedule publish time, ISO 8601 e.g. 2026-12-01T18:00:00+03:00. Empty string removes the schedule.'),
                'made_for_kids' => S_bool('Self-declared made for kids.'),
                'default_language' => S_str('Language of title/description, e.g. ar, en.'),
                'default_audio_language' => S_str('Spoken language of the video.'),
                'embeddable' => S_bool('Allow embedding.'),
                'license' => S_str('youtube | creativeCommon', ['enum' => ['youtube', 'creativeCommon']]),
                'public_stats_viewable' => S_bool('Show like counts publicly.'),
                'contains_synthetic_media' => S_bool('Disclose altered or synthetic (AI) content.'),
                'recording_date' => S_str('Recording date, ISO 8601.'),
            ], ['video_id']),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'full', $ctx);
                $yt = new YouTube($c);
                $id = video_id_from($a['video_id'] ?? '');
                $res = $yt->updateVideo($id, $a);
                return ['ok' => true, 'video' => $yt->compactVideo($res + ['statistics' => [], 'contentDetails' => []])];
            },
        ],
        [
            'name' => 'set_thumbnail', 'title' => 'Set custom thumbnail', 'access' => 'full', 'readOnly' => false,
            'description' => 'Replaces the video thumbnail. Provide image_url (public JPEG/PNG link) or image_base64 (raw base64 or data URI). Recommended 1280×720, ≤ 2 MB. The channel must be verified to use custom thumbnails.',
            'schema' => S_obj($ch + $videoArg + [
                'image_url' => S_str('Public URL of a JPEG/PNG image.'),
                'image_base64' => S_str('Base64 image data (or data:image/png;base64,… URI).'),
            ], ['video_id']),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'full', $ctx);
                $id = video_id_from($a['video_id'] ?? '');
                if (!empty($a['image_base64'])) {
                    $bytes = base64_decode(preg_replace('#^data:[^,]+,#', '', (string)$a['image_base64']), true);
                    if ($bytes === false) throw new UserError('image_base64 is not valid base64.');
                } elseif (!empty($a['image_url'])) {
                    $bytes = fetch_image_bytes((string)$a['image_url']);
                } else {
                    throw new UserError('Pass image_url or image_base64.');
                }
                $r = (new YouTube($c))->setThumbnail($id, $bytes);
                return ['ok' => true, 'thumbnails' => $r['items'][0] ?? $r];
            },
        ],
        [
            'name' => 'delete_video', 'title' => 'Delete video', 'access' => 'full', 'readOnly' => false, 'destructive' => true,
            'description' => 'Permanently deletes a video from YouTube. Irreversible — confirm with the user first and pass confirm=true.',
            'schema' => S_obj($ch + $videoArg + ['confirm' => S_bool('Must be true.')], ['video_id', 'confirm']),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'full', $ctx);
                if (($a['confirm'] ?? false) !== true) throw new UserError('Set confirm=true after the user explicitly approved deleting this video.');
                $id = video_id_from($a['video_id'] ?? '');
                (new YouTube($c))->deleteVideo($id);
                return ['ok' => true, 'deleted' => $id];
            },
        ],

        /* ---------- uploads ---------- */
        [
            'name' => 'create_upload_link', 'title' => 'Create upload link', 'access' => 'full', 'readOnly' => false,
            'description' => 'Creates a one-time web page where the user can drop a video file from their device (valid 48h). Give the link to the user; after they upload, call upload_video with the returned upload_id and the metadata (or pass the metadata now so it publishes automatically).',
            'schema' => S_obj($ch + [
                'title' => S_str('Optional: video title. If given (with other fields) the video is published automatically after the file arrives.'),
                'description' => S_str('Optional description.'),
                'tags' => S_arr('Optional tags.'),
                'privacy' => S_str('private | unlisted | public (default private).'),
                'publish_at' => S_str('Optional schedule (ISO 8601).'),
                'category_id' => S_str('Optional category id.'),
                'made_for_kids' => S_bool('Default false.'),
            ]),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'full', $ctx);
                $meta = upload_meta_from($a);
                if (empty($meta['title'])) $meta['await_metadata'] = true;
                $u = upload_create((int)$c['id'], 'link', $meta);
                $p = upload_public($u);
                $p['instructions'] = 'Send this link to the user: ' . $p['upload_page'] . ' . When they finish, call upload_status (or upload_video with upload_id and metadata if no title was set).';
                return $p;
            },
        ],
        [
            'name' => 'upload_video', 'title' => 'Upload / publish video', 'access' => 'full', 'readOnly' => false,
            'description' => 'Uploads a video to YouTube. Source is either source_url (a direct, publicly downloadable video file link) or upload_id (a file the user dropped via create_upload_link or the dashboard). The transfer runs in the background in chunks; this call works for ~25s and returns progress. Call upload_status until status is "done". Optional thumbnail and playlist are applied when finished.',
            'schema' => S_obj($ch + [
                'source_url' => S_str('Direct link to the video file (mp4, mov, webm…).'),
                'upload_id' => S_int('Id from create_upload_link / dashboard staging.'),
                'title' => S_str('Max 100 characters.'),
                'description' => S_str('Max 5000 characters.'),
                'tags' => S_arr('Tags.'),
                'category_id' => S_str('Default 22.'),
                'privacy' => S_str('private | unlisted | public (default private).', ['enum' => ['private', 'unlisted', 'public']]),
                'publish_at' => S_str('Schedule (ISO 8601). Forces private until then.'),
                'made_for_kids' => S_bool('Default false.'),
                'default_language' => S_str('e.g. ar, en.'),
                'notify_subscribers' => S_bool('Default true.'),
                'contains_synthetic_media' => S_bool('Disclose AI-generated/altered content.'),
                'thumbnail_url' => S_str('Optional thumbnail image URL to set after upload.'),
                'playlist_id' => S_str('Optional playlist to add the video to.'),
            ], ['title']),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'full', $ctx);
                $meta = upload_meta_from($a);
                if (!empty($a['upload_id'])) {
                    $u = upload_get((int)$a['upload_id']);
                    if (!$u || (int)$u['channel_id'] !== (int)$c['id']) throw new UserError('upload_id not found for this channel.');
                    if ($u['status'] === 'waiting_file') throw new UserError('The user has not uploaded the file yet. Ask them to open: ' . base_url() . '/drop.php?t=' . $u['token']);
                    $old = json_decode($u['meta'] ?: '{}', true) ?: [];
                    unset($old['await_metadata']);
                    upload_set((int)$u['id'], ['meta' => json_encode(array_merge($old, $meta), JSON_UNESCAPED_UNICODE)]);
                    return upload_step((int)$u['id'], 25);
                }
                if (empty($a['source_url'])) throw new UserError('Pass source_url or upload_id. To get a file from the user, call create_upload_link first.');
                if (!preg_match('#^https?://#i', (string)$a['source_url'])) throw new UserError('source_url must be http(s).');
                $u = upload_create((int)$c['id'], 'url', $meta, ['source_url' => $a['source_url'], 'filename' => basename(parse_url((string)$a['source_url'], PHP_URL_PATH) ?: 'video')]);
                return upload_step((int)$u['id'], 25);
            },
        ],
        [
            'name' => 'upload_status', 'title' => 'Upload progress', 'access' => 'read', 'readOnly' => false,
            'description' => 'Checks an upload job and pushes it forward for up to ~25 seconds. Call repeatedly until status is "done" (then video_id is set) or "error". Without upload_id lists recent uploads.',
            'schema' => S_obj(['upload_id' => S_int('Upload job id.'), 'continue' => S_bool('Push more data now (default true).')]),
            'run' => function ($a, $ctx) {
                if (empty($a['upload_id'])) {
                    return ['uploads' => array_map('upload_public', db_all('SELECT * FROM uploads ORDER BY id DESC LIMIT 15'))];
                }
                $u = upload_get((int)$a['upload_id']);
                if (!$u) throw new UserError('Upload not found');
                access_check(channel_get((int)$u['channel_id']) ?? [], 'read', $ctx);
                if (($a['continue'] ?? true) && in_array($u['status'], ['downloading', 'ready', 'uploading'], true)) {
                    return upload_step((int)$u['id'], 25);
                }
                return upload_public($u);
            },
        ],

        [
            'name' => 'upload_file_start', 'title' => 'Upload a video file directly', 'access' => 'full', 'readOnly' => false,
            'description' => 'Uploads a video file that YOU have (e.g. in your sandbox / attached to the chat) with no public link needed. The file is staged on the server, sent to YouTube, then deleted from the server immediately. Step 1: call this with filename, size (bytes) and the video metadata. It returns upload_id and a one-time put_url. Step 2 (preferred, any size): send the raw bytes from your sandbox: curl -X PUT --data-binary @file "<put_url>&offset=0" (in chunks with offset for large files). Step 2 (alternative, small files only): call upload_file_chunk with base64 pieces, or pass the whole file as data_base64 here. When all bytes arrive the YouTube upload starts automatically; poll upload_status until "done".',
            'schema' => S_obj($ch + [
                'filename' => S_str('File name, e.g. clip.mp4.'),
                'size' => S_int('Exact file size in bytes.'),
                'mime' => S_str('MIME type, default video/mp4.'),
                'data_base64' => S_str('Optional: the whole file as base64 (only for small files).'),
                'title' => S_str('Max 100 characters.'),
                'description' => S_str('Max 5000 characters.'),
                'tags' => S_arr('Tags.'),
                'category_id' => S_str('Default 22.'),
                'privacy' => S_str('private | unlisted | public (default private).', ['enum' => ['private', 'unlisted', 'public']]),
                'publish_at' => S_str('Schedule (ISO 8601).'),
                'made_for_kids' => S_bool('Default false.'),
                'default_language' => S_str('e.g. ar, en.'),
                'notify_subscribers' => S_bool('Default true.'),
                'contains_synthetic_media' => S_bool('Disclose AI-generated/altered content.'),
                'thumbnail_url' => S_str('Optional thumbnail URL.'),
                'thumbnail_base64' => S_str('Optional thumbnail as base64 (JPEG/PNG ≤ 2 MB).'),
                'playlist_id' => S_str('Optional playlist to add the video to.'),
            ], ['size', 'title']),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'full', $ctx);
                $size = (int)($a['size'] ?? 0);
                if ($size <= 0) throw new UserError('size (bytes) is required.');
                $u = upload_create((int)$c['id'], 'mcp', upload_meta_from($a), [
                    'filename' => mb_substr((string)($a['filename'] ?? 'video.mp4'), 0, 200),
                    'size' => $size,
                    'mime' => (string)($a['mime'] ?? 'video/mp4'),
                    'expires' => time() + 6 * 3600,
                ]);
                if (!empty($a['data_base64'])) {
                    $u = upload_append_base64($u, 0, (string)$a['data_base64']);
                    if ($u['status'] === 'ready') return upload_step((int)$u['id'], 25);
                }
                $put = base_url() . '/upload.php?t=' . $u['token'];
                return upload_public($u) + [
                    'put_url' => $put,
                    'chunk_hint' => 'PUT raw bytes, 8–50 MB per request, offset = bytes already received.',
                    'curl_single' => 'curl -sS -X PUT --data-binary @"FILE" "' . $put . '&offset=0"',
                    'curl_chunked' => 'split -b 20M -d FILE part_ && o=0; for p in part_*; do curl -sS -X PUT --data-binary @"$p" "' . $put . '&offset=$o"; o=$((o+$(stat -c%s "$p"))); done',
                    'next' => 'After the bytes are sent the YouTube upload starts; call upload_status with upload_id until status is "done". The staged file is deleted right after.',
                ];
            },
        ],
        [
            'name' => 'upload_file_chunk', 'title' => 'Send file chunk (base64)', 'access' => 'full', 'readOnly' => false,
            'description' => 'Sends the next piece of a file started with upload_file_start, as base64. offset must equal the bytes already received (returned as "received"). Keep each chunk ≤ ~4 MB of raw data. When the last chunk arrives the YouTube upload begins automatically.',
            'schema' => S_obj([
                'upload_id' => S_int('From upload_file_start.'),
                'offset' => S_int('Byte offset of this chunk.'),
                'data_base64' => S_str('Chunk content in base64.'),
            ], ['upload_id', 'offset', 'data_base64']),
            'run' => function ($a, $ctx) {
                $u = upload_get((int)($a['upload_id'] ?? 0));
                if (!$u || $u['source'] !== 'mcp') throw new UserError('Upload not found (start with upload_file_start).');
                access_check(channel_get((int)$u['channel_id']) ?? ['ai_access' => 'none', 'title' => '?'], 'full', $ctx);
                $u = upload_append_base64($u, (int)($a['offset'] ?? 0), (string)($a['data_base64'] ?? ''));
                if ($u['status'] === 'ready') return upload_step((int)$u['id'], 20);
                return upload_public($u);
            },
        ],
        [
            'name' => 'upload_cancel', 'title' => 'Cancel upload', 'access' => 'full', 'readOnly' => false,
            'description' => 'Cancels an unfinished upload job and deletes its staged file from the server.',
            'schema' => S_obj(['upload_id' => S_int('Upload id.')], ['upload_id']),
            'run' => function ($a, $ctx) {
                $u = upload_get((int)($a['upload_id'] ?? 0));
                if (!$u) throw new UserError('Upload not found');
                access_check(channel_get((int)$u['channel_id']) ?? ['ai_access' => 'none', 'title' => '?'], 'full', $ctx);
                upload_discard($u, 'cancelled');
                return ['ok' => true, 'cancelled' => (int)$u['id']];
            },
        ],

        /* ---------- playlists ---------- */
        [
            'name' => 'list_playlists', 'title' => 'List playlists', 'access' => 'read', 'readOnly' => true,
            'description' => 'Lists the channel\'s playlists, or the videos inside one playlist when playlist_id is given.',
            'schema' => S_obj($ch + ['playlist_id' => S_str('If set, list items of this playlist.'), 'page_token' => S_str('Next page token.')]),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'read', $ctx);
                $yt = new YouTube($c);
                if (!empty($a['playlist_id'])) return $yt->playlistItems((string)$a['playlist_id'], 50, $a['page_token'] ?? null);
                return $yt->listPlaylists(50, $a['page_token'] ?? null);
            },
        ],
        [
            'name' => 'manage_playlist', 'title' => 'Create / edit playlists', 'access' => 'full', 'readOnly' => false,
            'description' => 'Playlist actions: create (title, description, privacy), update (playlist_id + fields), delete (playlist_id, confirm), add_video (playlist_id, video_id, optional position), remove_item (item_id from list_playlists), move_item (item_id, playlist_id, video_id, position).',
            'schema' => S_obj($ch + [
                'action' => S_str('create | update | delete | add_video | remove_item | move_item', ['enum' => ['create', 'update', 'delete', 'add_video', 'remove_item', 'move_item']]),
                'playlist_id' => S_str('Playlist id.'),
                'title' => S_str('Playlist title.'),
                'description' => S_str('Playlist description.'),
                'privacy' => S_str('public | unlisted | private'),
                'video_id' => S_str('Video id for add_video / move_item.'),
                'item_id' => S_str('Playlist item id for remove_item / move_item.'),
                'position' => S_int('Zero-based position.'),
                'confirm' => S_bool('Required true for delete.'),
            ], ['action']),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'full', $ctx);
                $yt = new YouTube($c);
                switch ($a['action'] ?? '') {
                    case 'create':
                        if (empty($a['title'])) throw new UserError('title required');
                        $r = $yt->api('POST', '/playlists', ['part' => 'snippet,status'], [
                            'snippet' => ['title' => $a['title'], 'description' => $a['description'] ?? ''],
                            'status' => ['privacyStatus' => $a['privacy'] ?? 'public'],
                        ]);
                        return ['ok' => true, 'playlist_id' => $r['id'] ?? null];
                    case 'update':
                        $cur = $yt->api('GET', '/playlists', ['part' => 'snippet,status', 'id' => $a['playlist_id'] ?? '']);
                        $p = $cur['items'][0] ?? null;
                        if (!$p) throw new UserError('Playlist not found');
                        $r = $yt->api('PUT', '/playlists', ['part' => 'snippet,status'], [
                            'id' => $p['id'],
                            'snippet' => ['title' => $a['title'] ?? $p['snippet']['title'], 'description' => $a['description'] ?? ($p['snippet']['description'] ?? '')],
                            'status' => ['privacyStatus' => $a['privacy'] ?? $p['status']['privacyStatus']],
                        ]);
                        return ['ok' => true, 'playlist_id' => $r['id'] ?? null];
                    case 'delete':
                        if (($a['confirm'] ?? false) !== true) throw new UserError('confirm=true is required to delete a playlist.');
                        $yt->request('DELETE', YT_API . '/playlists', ['id' => $a['playlist_id'] ?? '']);
                        return ['ok' => true];
                    case 'add_video':
                        $sn = ['playlistId' => $a['playlist_id'] ?? '', 'resourceId' => ['kind' => 'youtube#video', 'videoId' => video_id_from($a['video_id'] ?? '')]];
                        if (isset($a['position'])) $sn['position'] = (int)$a['position'];
                        $r = $yt->api('POST', '/playlistItems', ['part' => 'snippet'], ['snippet' => $sn]);
                        return ['ok' => true, 'item_id' => $r['id'] ?? null];
                    case 'remove_item':
                        $yt->request('DELETE', YT_API . '/playlistItems', ['id' => $a['item_id'] ?? '']);
                        return ['ok' => true];
                    case 'move_item':
                        $r = $yt->api('PUT', '/playlistItems', ['part' => 'snippet'], ['id' => $a['item_id'] ?? '', 'snippet' => [
                            'playlistId' => $a['playlist_id'] ?? '', 'position' => (int)($a['position'] ?? 0),
                            'resourceId' => ['kind' => 'youtube#video', 'videoId' => video_id_from($a['video_id'] ?? '')],
                        ]]);
                        return ['ok' => true];
                }
                throw new UserError('Unknown action');
            },
        ],

        /* ---------- comments ---------- */
        [
            'name' => 'list_comments', 'title' => 'Read comments', 'access' => 'read', 'readOnly' => true,
            'description' => 'Lists comment threads (with replies) on a video, or across the whole channel when video_id is omitted. Filter by search text or moderation status (published | heldForReview | likelySpam).',
            'schema' => S_obj($ch + [
                'video_id' => S_str('Optional video id.'),
                'max_results' => S_int('1-100, default 20.'),
                'page_token' => S_str('Next page.'),
                'search' => S_str('Only comments containing this text.'),
                'moderation_status' => S_str('published | heldForReview | likelySpam'),
                'order' => S_str('time | relevance'),
            ]),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'read', $ctx);
                $vid = !empty($a['video_id']) ? video_id_from($a['video_id']) : null;
                return (new YouTube($c))->listComments($vid, (int)($a['max_results'] ?? 20), $a['page_token'] ?? null, $a['search'] ?? null, $a['moderation_status'] ?? 'published', $a['order'] ?? 'time');
            },
        ],
        [
            'name' => 'manage_comment', 'title' => 'Reply / moderate comments', 'access' => 'full', 'readOnly' => false,
            'description' => 'Comment actions: reply (comment_id = top-level comment id, text), post (video_id, text — new top-level comment as the channel), edit (comment_id of your own comment, text), delete (comment_id), set_status (comment_id, status: published | heldForReview | rejected, optional ban_author).',
            'schema' => S_obj($ch + [
                'action' => S_str('reply | post | edit | delete | set_status', ['enum' => ['reply', 'post', 'edit', 'delete', 'set_status']]),
                'comment_id' => S_str('Comment id.'),
                'video_id' => S_str('Video id for post.'),
                'text' => S_str('Comment text.'),
                'status' => S_str('published | heldForReview | rejected'),
                'ban_author' => S_bool('With rejected: also ban the author.'),
            ], ['action']),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'full', $ctx);
                $yt = new YouTube($c);
                switch ($a['action'] ?? '') {
                    case 'reply':
                        $r = $yt->api('POST', '/comments', ['part' => 'snippet'], ['snippet' => ['parentId' => $a['comment_id'] ?? '', 'textOriginal' => (string)($a['text'] ?? '')]]);
                        return ['ok' => true, 'comment_id' => $r['id'] ?? null];
                    case 'post':
                        $r = $yt->api('POST', '/commentThreads', ['part' => 'snippet'], ['snippet' => [
                            'videoId' => video_id_from($a['video_id'] ?? ''),
                            'topLevelComment' => ['snippet' => ['textOriginal' => (string)($a['text'] ?? '')]],
                        ]]);
                        return ['ok' => true, 'thread_id' => $r['id'] ?? null];
                    case 'edit':
                        $r = $yt->api('PUT', '/comments', ['part' => 'snippet'], ['id' => $a['comment_id'] ?? '', 'snippet' => ['textOriginal' => (string)($a['text'] ?? '')]]);
                        return ['ok' => true];
                    case 'delete':
                        $yt->request('DELETE', YT_API . '/comments', ['id' => $a['comment_id'] ?? '']);
                        return ['ok' => true];
                    case 'set_status':
                        $q = ['id' => $a['comment_id'] ?? '', 'moderationStatus' => $a['status'] ?? 'published'];
                        if (!empty($a['ban_author']) && ($a['status'] ?? '') === 'rejected') $q['banAuthor'] = 'true';
                        $yt->request('POST', YT_API . '/comments/setModerationStatus', $q);
                        return ['ok' => true];
                }
                throw new UserError('Unknown action');
            },
        ],

        /* ---------- captions ---------- */
        [
            'name' => 'list_captions', 'title' => 'List captions', 'access' => 'read', 'readOnly' => true,
            'description' => 'Lists caption/subtitle tracks of a video (including auto-generated "asr" tracks).',
            'schema' => S_obj($ch + $videoArg, ['video_id']),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'read', $ctx);
                return ['captions' => (new YouTube($c))->listCaptions(video_id_from($a['video_id'] ?? ''))];
            },
        ],
        [
            'name' => 'get_transcript', 'title' => 'Read transcript', 'access' => 'read', 'readOnly' => true,
            'description' => 'Downloads a caption track as text so you can analyse what is said in the video (summaries, chapters, SEO descriptions, hooks). Picks the best track automatically if caption_id is omitted (prefers language if given).',
            'schema' => S_obj($ch + $videoArg + [
                'caption_id' => S_str('Specific track id from list_captions.'),
                'language' => S_str('Preferred language code, e.g. ar.'),
                'format' => S_str('srt (with timestamps, default) | vtt | sbv | plain', ['enum' => ['srt', 'vtt', 'sbv', 'plain']]),
            ], ['video_id']),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'read', $ctx);
                $yt = new YouTube($c);
                $vid = video_id_from($a['video_id'] ?? '');
                $capId = $a['caption_id'] ?? null;
                $chosen = null;
                if (!$capId) {
                    $list = $yt->listCaptions($vid);
                    if (!$list) throw new UserError('This video has no caption tracks yet.');
                    usort($list, function ($x, $y) use ($a) {
                        $lang = $a['language'] ?? '';
                        $sx = ($lang && stripos((string)$x['language'], $lang) === 0 ? 4 : 0) + ($x['is_auto'] ? 0 : 2);
                        $sy = ($lang && stripos((string)$y['language'], $lang) === 0 ? 4 : 0) + ($y['is_auto'] ? 0 : 2);
                        return $sy <=> $sx;
                    });
                    $chosen = $list[0];
                    $capId = $chosen['caption_id'];
                }
                $fmt = $a['format'] ?? 'srt';
                $text = $yt->downloadCaption($capId, $fmt === 'plain' ? 'srt' : $fmt);
                if ($fmt === 'plain') {
                    $text = preg_replace('/^\d+\s*$/m', '', $text);
                    $text = preg_replace('/^\d\d:\d\d:\d\d[,.]\d+ --> .*$/m', '', $text);
                    $text = trim(preg_replace("/\n{2,}/", "\n", $text));
                }
                return ['caption' => $chosen ?? ['caption_id' => $capId], 'format' => $fmt, 'length' => mb_strlen($text), 'text' => mb_substr($text, 0, 200000)];
            },
        ],
        [
            'name' => 'manage_caption', 'title' => 'Upload / delete captions', 'access' => 'full', 'readOnly' => false,
            'description' => 'upload: adds a caption track (content in SRT or WebVTT, language code, name). delete: removes a track (caption_id). Great for translated subtitles you write.',
            'schema' => S_obj($ch + [
                'action' => S_str('upload | delete', ['enum' => ['upload', 'delete']]),
                'video_id' => S_str('Video id (upload).'),
                'caption_id' => S_str('Track id (delete).'),
                'language' => S_str('Language code, e.g. ar, en.'),
                'name' => S_str('Track name shown to viewers (can be empty).'),
                'content' => S_str('SRT or VTT text.'),
                'draft' => S_bool('Upload as draft (hidden).'),
            ], ['action']),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'full', $ctx);
                $yt = new YouTube($c);
                if (($a['action'] ?? '') === 'delete') {
                    $yt->request('DELETE', YT_API . '/captions', ['id' => $a['caption_id'] ?? '']);
                    return ['ok' => true];
                }
                if (empty($a['content']) || empty($a['language'])) throw new UserError('content and language are required');
                $r = $yt->uploadCaption(video_id_from($a['video_id'] ?? ''), (string)$a['language'], (string)($a['name'] ?? ''), (string)$a['content'], !empty($a['draft']));
                return ['ok' => true, 'caption_id' => $r['id'] ?? null];
            },
        ],

        /* ---------- analytics ---------- */
        [
            'name' => 'analytics_summary', 'title' => 'Analytics summary', 'access' => 'read', 'readOnly' => true,
            'description' => 'Ready-made analytics for the channel (or one video): totals vs previous period (views, watch minutes, avg view duration/percentage, subs gained/lost, likes, comments, shares), daily series, traffic sources, top countries, devices, age/gender, top videos — or audience retention curve for a video. Data lags ~2 days.',
            'schema' => S_obj($ch + [
                'days' => S_int('Period length in days: 7, 28, 90, 365… (default 28).'),
                'video_id' => S_str('Optional: analyse a single video.'),
            ]),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'read', $ctx);
                $vid = !empty($a['video_id']) ? video_id_from($a['video_id']) : null;
                return analytics_summary(new YouTube($c), (int)($a['days'] ?? 28), $vid);
            },
        ],
        [
            'name' => 'query_analytics', 'title' => 'Custom analytics query', 'access' => 'read', 'readOnly' => true,
            'description' => 'Raw YouTube Analytics API v2 report for advanced questions. Examples — metrics: views,estimatedMinutesWatched,averageViewDuration,subscribersGained,likes,shares,cardClickRate,annotationClickThroughRate,estimatedRevenue (monetized channels); dimensions: day,month,video,country,deviceType,insightTrafficSourceType,insightTrafficSourceDetail,ageGroup,gender,playbackLocationType,subscribedStatus,creatorContentType; filters: video==ID;country==US.',
            'schema' => S_obj($ch + [
                'start_date' => S_str('YYYY-MM-DD (default 28 days ago).'),
                'end_date' => S_str('YYYY-MM-DD (default yesterday).'),
                'metrics' => S_str('Comma separated metrics.'),
                'dimensions' => S_str('Comma separated dimensions.'),
                'filters' => S_str('e.g. video==abc123;country==SA'),
                'sort' => S_str('e.g. -views'),
                'max_results' => S_int('Row limit.'),
                'currency' => S_str('For revenue metrics, e.g. USD.'),
            ], ['metrics']),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'read', $ctx);
                return (new YouTube($c))->report([
                    'startDate' => $a['start_date'] ?? gmdate('Y-m-d', time() - 29 * 86400),
                    'endDate' => $a['end_date'] ?? gmdate('Y-m-d', time() - 86400),
                    'metrics' => $a['metrics'] ?? 'views',
                    'dimensions' => $a['dimensions'] ?? null,
                    'filters' => $a['filters'] ?? null,
                    'sort' => $a['sort'] ?? null,
                    'maxResults' => $a['max_results'] ?? null,
                    'currency' => $a['currency'] ?? null,
                ]);
            },
        ],
        [
            'name' => 'list_categories', 'title' => 'Video categories', 'access' => 'read', 'readOnly' => true,
            'description' => 'Lists assignable video category ids for a region.',
            'schema' => S_obj($ch + ['region' => S_str('ISO country code, default US.'), 'language' => S_str('Display language, default en.')]),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'read', $ctx);
                return ['categories' => (new YouTube($c))->categories($a['region'] ?? 'US', $a['language'] ?? 'en')];
            },
        ],
        [
            'name' => 'search_youtube', 'title' => 'Search YouTube (research)', 'access' => 'read', 'readOnly' => true,
            'description' => 'Searches all of public YouTube for competitor / trend research. Returns videos with stats. Costs 100 quota units per call — use sparingly.',
            'schema' => S_obj($ch + [
                'query' => S_str('Search text.'),
                'max_results' => S_int('1-25, default 10.'),
                'order' => S_str('relevance | date | viewCount | rating'),
                'region' => S_str('ISO country code.'),
                'language' => S_str('Relevance language, e.g. ar.'),
                'published_after' => S_str('ISO date, e.g. 2026-01-01T00:00:00Z'),
                'duration' => S_str('any | short (<4m) | medium | long (>20m)'),
            ], ['query']),
            'run' => function ($a, $ctx) {
                $c = tool_channel($a, 'read', $ctx);
                $yt = new YouTube($c);
                $q = ['part' => 'id', 'type' => 'video', 'q' => $a['query'], 'maxResults' => max(1, min(25, (int)($a['max_results'] ?? 10))), 'order' => $a['order'] ?? 'relevance'];
                if (!empty($a['region'])) $q['regionCode'] = $a['region'];
                if (!empty($a['language'])) $q['relevanceLanguage'] = $a['language'];
                if (!empty($a['published_after'])) $q['publishedAfter'] = YouTube::isoTime($a['published_after']);
                if (!empty($a['duration'])) $q['videoDuration'] = $a['duration'];
                $d = $yt->api('GET', '/search', $q);
                $ids = array_map(fn($i) => $i['id']['videoId'], $d['items'] ?? []);
                $vids = $ids ? $yt->videosByIds($ids) : [];
                return ['results' => array_map(function ($v) use ($yt) {
                    $cv = $yt->compactVideo($v);
                    $cv['channel_title'] = $v['snippet']['channelTitle'] ?? '';
                    $cv['channel_id'] = $v['snippet']['channelId'] ?? '';
                    return $cv;
                }, $vids)];
            },
        ],

        /* ---------- stock media ---------- */
        [
            'name' => 'pixabay_search', 'title' => 'Search Pixabay images & videos', 'access' => 'none', 'readOnly' => true,
            'enabled' => fn() => media_source_enabled('pixabay') && pixabay_key(),
            'description' => 'Searches Pixabay for royalty-free photos, illustrations, vectors (type=image) or video clips (type=video) to use in thumbnails, B-roll, Shorts or intros. Returns URLs in several sizes (image: preview/web/large; video: tiny/small/medium/large with width, height, bytes), tags, author and a numbered preview grid so you can see the results. Use a result\'s best_url with set_thumbnail (image_url) or upload_video (source_url), or download it in your sandbox. Free to use without attribution, but mention that the media comes from Pixabay.',
            'schema' => S_obj([
                'query' => S_str('Search words (max 100 chars). English works best, or set lang.'),
                'type' => S_str('image (default) or video.', ['enum' => ['image', 'video']]),
                'image_type' => S_str('Images only: all | photo | illustration | vector.', ['enum' => ['all', 'photo', 'illustration', 'vector']]),
                'video_type' => S_str('Videos only: all | film | animation.', ['enum' => ['all', 'film', 'animation']]),
                'orientation' => S_str('Images only: all | horizontal | vertical (vertical = Shorts).', ['enum' => ['all', 'horizontal', 'vertical']]),
                'category' => S_str('Optional category.', ['enum' => PIXABAY_IMAGE_CATEGORIES]),
                'colors' => S_str('Images only, comma separated: grayscale, transparent, red, orange, yellow, green, turquoise, blue, lilac, pink, white, gray, black, brown.'),
                'min_width' => S_int('Minimum width in px (e.g. 1280 for thumbnails, 1920 for HD video).'),
                'min_height' => S_int('Minimum height in px.'),
                'editors_choice' => S_bool('Only Editor\'s Choice awards.'),
                'order' => S_str('popular (default) or latest.', ['enum' => ['popular', 'latest']]),
                'lang' => S_str('Query language code (en default; also de, fr, es, tr, ru, ja, ko, zh…). Arabic is not supported — translate the query to English.'),
                'per_page' => S_int('3-50 results (default 12).'),
                'page' => S_int('Page number.'),
                'include_preview' => S_bool('Return a numbered preview grid image (default true).'),
            ], ['query']),
            'run' => function ($a, $ctx) {
                $a['per_page'] = min(50, max(3, (int)($a['per_page'] ?? 12)));
                $res = pixabay_search($a);
                return media_result_content($res, ($a['include_preview'] ?? true) !== false && empty($ctx['admin']));
            },
        ],
        [
            'name' => 'wikimedia_search', 'title' => 'Search Wikimedia Commons', 'access' => 'none', 'readOnly' => true,
            'enabled' => fn() => media_source_enabled('wikimedia'),
            'description' => 'Searches Wikimedia Commons (no API key) for freely licensed images, videos or audio — great for real places, people, historical events, logos of public institutions, maps and diagrams. Returns file URL, thumbnail, size, author, license and a ready attribution line, plus a numbered preview grid. Set license=no_restrictions for public domain / CC0 only. Always credit the author unless public domain.',
            'schema' => S_obj([
                'query' => S_str('Search terms, e.g. "eiffel tower night".'),
                'media_type' => S_str('image (default) | video | audio.', ['enum' => ['image', 'video', 'audio']]),
                'license' => S_str('all (default) or no_restrictions (public domain / CC0 only).', ['enum' => ['all', 'no_restrictions']]),
                'limit' => S_int('Max results, default 9, max 50.'),
                'offset' => S_int('Skip results (pagination; use next_offset).'),
                'thumb_width' => S_int('Thumbnail width in px, default 400.'),
                'include_thumbnails' => S_bool('Return a numbered preview grid image (default true).'),
            ], ['query']),
            'run' => function ($a, $ctx) {
                $res = wikimedia_search($a);
                return media_result_content($res, ($a['include_thumbnails'] ?? true) !== false && empty($ctx['admin']));
            },
        ],
        [
            'name' => 'preview_image', 'title' => 'View an image', 'access' => 'none', 'readOnly' => true,
            'description' => 'Fetches one image (from Pixabay, Wikimedia, YouTube thumbnails) and returns it so you can look at it closely before using it — e.g. to check a thumbnail candidate.',
            'schema' => S_obj(['url' => S_str('Image URL (pixabay.com, wikimedia.org, ytimg.com).')], ['url']),
            'run' => function ($a, $ctx) {
                $url = (string)($a['url'] ?? '');
                if (!media_preview_allowed($url)) throw new UserError('Only https images from pixabay.com, wikimedia.org or YouTube can be previewed.');
                $r = http_request('GET', $url, ['follow' => true, 'timeout' => 30, 'headers' => ['User-Agent: ' . MEDIA_UA]]);
                if ($r['status'] !== 200) throw new UserError('Could not fetch image (HTTP ' . $r['status'] . ').');
                if (strlen($r['body']) > 4 * 1024 * 1024) throw new UserError('Image too large to preview (> 4 MB). Use a smaller size URL.');
                $mime = YouTube::sniffImage($r['body']) ?: (substr($r['body'], 8, 4) === 'WEBP' ? 'image/webp' : null);
                if (!$mime) throw new UserError('Not a supported image.');
                return ['__content' => [
                    ['type' => 'text', 'text' => $url . ' (' . round(strlen($r['body']) / 1024) . ' KB)'],
                    ['type' => 'image', 'data' => base64_encode($r['body']), 'mimeType' => $mime],
                ]];
            },
        ],

        /* ---------- AI notebook ---------- */
        [
            'name' => 'notebook_write', 'title' => 'Write to AI Notebook', 'access' => 'none', 'readOnly' => false,
            'description' => 'Adds an entry to the AI Notebook shown in the site dashboard — use it to keep daily/weekly summaries, reports, insights, plans, tasks and a log of what you did, so the user (and future you) can review it. body is Markdown. data can hold KPI tiles and charts: {"metrics":[{"label":"Views","value":1200,"delta":8.5}], "charts":[{"type":"line|bar|area|donut","title":"Views/day","labels":["11-01","11-02"],"series":[{"name":"Views","data":[100,140]}]}]}. Only write when the user asks for summaries/logging or has enabled it.',
            'schema' => S_obj([
                'channel' => S_str('Optional channel this entry is about (omit for general).'),
                'kind' => S_str('daily | weekly | monthly | report | insight | plan | task | log | note', ['enum' => NOTE_KINDS]),
                'title' => S_str('Short title.'),
                'body' => S_str('Markdown content.'),
                'data' => ['type' => 'object', 'description' => 'Optional metrics & charts (see description).'],
                'tags' => S_arr('Optional tags.'),
                'pinned' => S_bool('Pin to top.'),
                'period_start' => S_str('Optional YYYY-MM-DD the summary covers from.'),
                'period_end' => S_str('Optional YYYY-MM-DD to.'),
            ], ['kind', 'title']),
            'run' => function ($a, $ctx) {
                $chId = null;
                if (!empty($a['channel'])) {
                    $c = channel_resolve($a['channel']);
                    access_check($c, 'read', $ctx);
                    $chId = (int)$c['id'];
                }
                return note_create($a + ['channel_id' => $chId], !empty($ctx['admin']) ? 'user' : 'ai');
            },
        ],
        [
            'name' => 'notebook_read', 'title' => 'Read AI Notebook', 'access' => 'none', 'readOnly' => true,
            'description' => 'Reads AI Notebook entries (newest first, pinned on top). Filter by channel, kind, search text, since date. Read this to recall previous summaries and plans before writing new ones.',
            'schema' => S_obj([
                'channel' => S_str('Optional channel filter.'),
                'kind' => S_str('Optional kind filter.'),
                'search' => S_str('Text search.'),
                'since' => S_str('Only entries after this date (YYYY-MM-DD).'),
                'limit' => S_int('Default 20, max 200.'),
                'offset' => S_int('Pagination offset.'),
            ]),
            'run' => function ($a, $ctx) {
                $f = $a;
                if (!empty($a['channel'])) $f['channel_id'] = (int)channel_resolve($a['channel'])['id'];
                $f['limit'] = $a['limit'] ?? 20;
                return ['entries' => note_list($f)];
            },
        ],
        [
            'name' => 'notebook_update', 'title' => 'Edit / delete notebook entry', 'access' => 'none', 'readOnly' => false,
            'description' => 'Edits a notebook entry (title, body, append_body, kind, data, tags, pinned) or deletes it with delete=true.',
            'schema' => S_obj([
                'id' => S_int('Entry id.'),
                'title' => S_str('New title.'),
                'body' => S_str('Replace body.'),
                'append_body' => S_str('Append Markdown to the body.'),
                'kind' => S_str('New kind.'),
                'data' => ['type' => 'object', 'description' => 'Replace metrics/charts.'],
                'tags' => S_arr('Replace tags.'),
                'pinned' => S_bool('Pin / unpin.'),
                'delete' => S_bool('Delete the entry.'),
            ], ['id']),
            'run' => function ($a, $ctx) {
                $id = (int)($a['id'] ?? 0);
                if (!empty($a['delete'])) {
                    db_exec('DELETE FROM notebook WHERE id = ?', [$id]);
                    return ['ok' => true, 'deleted' => $id];
                }
                return note_update($id, $a);
            },
        ],
        [
            'name' => 'record_snapshot', 'title' => 'Record stats snapshot', 'access' => 'read', 'readOnly' => false,
            'description' => 'Fetches current subscriber/view/video counts for one channel (or all) and stores today\'s snapshot, which powers the growth charts in the dashboard. Returns recent snapshot history.',
            'schema' => S_obj(['channel' => S_str('Optional; omit for all channels.'), 'history_days' => S_int('How many days of history to return (default 30).')]),
            'run' => function ($a, $ctx) {
                $rows = !empty($a['channel']) ? [channel_resolve($a['channel'])] : db_all('SELECT * FROM channels ORDER BY id');
                $out = [];
                foreach ($rows as $c) {
                    if (empty($ctx['admin']) && $c['ai_access'] === 'none') continue;
                    try {
                        $yt = new YouTube($c);
                        $yt->syncChannel();
                        $hist = db_all('SELECT day, subscribers, views, videos FROM snapshots WHERE channel_id = ? ORDER BY day DESC LIMIT ' . max(1, min(365, (int)($a['history_days'] ?? 30))), [$c['id']]);
                        $out[] = ['channel' => channel_public($yt->ch), 'history' => array_reverse($hist)];
                    } catch (Throwable $e) {
                        $out[] = ['channel' => channel_public($c), 'error' => $e->getMessage()];
                    }
                }
                return ['snapshots' => $out];
            },
        ],
    ];
    return $tools;
}

function tool_find(string $name): ?array
{
    foreach (tools_catalog() as $t) if ($t['name'] === $name) return $t;
    return null;
}

function tool_run(string $name, array $args, array $ctx): array
{
    $t = tool_find($name);
    if (!$t) throw new UserError('Unknown tool: ' . $name);
    return ($t['run'])($args, $ctx);
}

function video_id_from(string $s): string
{
    $s = trim($s);
    if (preg_match('~(?:v=|youtu\.be/|shorts/|embed/|live/)([A-Za-z0-9_-]{11})~', $s, $m)) return $m[1];
    if (preg_match('~^[A-Za-z0-9_-]{11}$~', $s)) return $s;
    throw new UserError('Invalid video id: ' . $s);
}

function upload_meta_from(array $a): array
{
    $meta = [];
    foreach (['title', 'description', 'tags', 'category_id', 'privacy', 'publish_at', 'made_for_kids', 'default_language', 'notify_subscribers', 'contains_synthetic_media', 'thumbnail_url', 'playlist_id', 'thumbnail_base64'] as $k) {
        if (isset($a[$k]) && $a[$k] !== '') $meta[$k] = $a[$k];
    }
    return $meta;
}

/** Tools that are currently switched on (media sources can be disabled in the dashboard). */
function tools_enabled(): array
{
    return array_values(array_filter(tools_catalog(), function ($t) {
        if (!isset($t['enabled'])) return true;
        try { return (bool)($t['enabled'])(); } catch (Throwable $e) { return false; }
    }));
}

/** Wraps a media search result as MCP content: JSON text + optional numbered preview grid. */
function media_result_content(array $res, bool $withPreview): array
{
    if (!$withPreview || empty($res['results'])) return $res;
    $thumbs = array_map(fn($r) => $r['thumbnail'] ?? ($r['preview_url'] ?? null), $res['results']);
    $jpg = null;
    try { $jpg = media_composite($thumbs); } catch (Throwable $e) { $jpg = null; }
    $res['preview'] = $jpg ? 'The image below shows the results numbered 1..' . count($thumbs) . ' in the same order as "results".' : 'Preview grid unavailable (PHP GD extension missing) — use preview_image to view one result.';
    $content = [['type' => 'text', 'text' => json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]];
    if ($jpg) $content[] = ['type' => 'image', 'data' => base64_encode($jpg), 'mimeType' => 'image/jpeg'];
    return ['__content' => $content];
}
