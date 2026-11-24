# YT Connector

**A self-hosted YouTube control center that plugs into Claude as a custom connector (remote MCP server).**

Install it on any PHP hosting (Hostinger, cPanel, any VPS), connect one or more YouTube channels with your own Google Cloud credentials, add the site to Claude, and then just ask Claude to upload, edit, analyse and manage your channels.

- 🔌 **Remote MCP server** (Streamable HTTP) with a built-in **OAuth 2.1** server (dynamic client registration + PKCE), so it works with Claude's "Add custom connector" button
- 📺 **Several channels**: add, re-authorize or remove them, and set the AI's access level for each one (full control, read only, or hidden)
- 🎬 **Video management**: list, upload (from a URL or a one-time drop link), edit the title, description, tags, category, privacy and schedule, change thumbnails, delete
- 👀 **Video analysis**: the AI sees the thumbnail plus 3 frames captured from the video, reads the transcript, and gets the stats and retention curve
- 📈 **Analytics**: ready-made summaries (traffic sources, countries, devices, audience, top videos) and custom YouTube Analytics queries
- 💬 **Comments, playlists, captions, channel branding**: full read and write access
- 🖼 **Stock media for the AI**: search Pixabay (royalty-free photos, illustrations, vectors and video clips, needs a free API key) and Wikimedia Commons (no key) for thumbnails, B-roll and backgrounds. Results come with a numbered preview grid the AI can look at.
- ✈️ **Telegram bot**: paste a bot token and the AI can send messages, photos, videos, files and albums to you, your groups or channels. It can also read what you send the bot, publish a video you sent it straight to YouTube, and call any Bot API method.
- 📓 **AI Notebook**: a journal in the dashboard where the AI keeps daily or weekly summaries, insights, plans and logs, with KPI tiles and charts
- 🖥 **Dashboard**: a responsive interface in Arabic and English with dark and light themes, including a video editor, resumable uploads, analytics charts and an activity log
- 🔐 **Security**: Google secrets are encrypted at rest (AES-256-GCM), tokens are hashed, the admin login is rate-limited, sessions can be revoked, and private folders are blocked

---

## Table of contents

1. [Requirements](#requirements)
2. [Install on Hostinger (or any shared hosting)](#install-on-hostinger-or-any-shared-hosting)
3. [Create Google Cloud credentials](#create-google-cloud-credentials)
4. [Add a channel](#add-a-channel)
5. [Connect to Claude](#connect-to-claude)
6. [Tools available to the AI](#tools-available-to-the-ai)
7. [Uploading videos](#uploading-videos)
8. [AI Notebook](#ai-notebook)
9. [Security notes](#security-notes)
10. [Troubleshooting](#troubleshooting)
11. [Project structure](#project-structure)

---

## Requirements

| | |
|---|---|
| PHP | 8.0 or newer, with `curl`, `openssl`, `pdo_sqlite` (or `pdo_mysql`), `mbstring`; `gd` recommended (media preview grids) |
| Web server | Apache or LiteSpeed with `.htaccess` (Hostinger works out of the box). Nginx also works if you port the rewrite rules. |
| HTTPS | **Required** by Claude connectors. A free SSL certificate from your host is fine. |
| Location | Install it at the **root of a domain or subdomain** (e.g. `https://yt.example.com`). OAuth discovery uses `/.well-known/…` at the domain root. |

No Composer and no build step. The whole thing is plain PHP, HTML, CSS and JS.

## Install on Hostinger (or any shared hosting)

1. **Create a subdomain** (recommended), e.g. `yt.yourdomain.com`, in hPanel → *Domains → Subdomains*. Make sure **SSL** is active for it (hPanel → *Security → SSL*).
2. **Upload the files** to the subdomain's folder (e.g. `public_html/yt` or `domains/yt.yourdomain.com/public_html`). Either:
   - download this repository as a ZIP → *File Manager* → upload → *Extract*, **or**
   - use hPanel → *Advanced → Git* to deploy straight from GitHub.

   Make sure hidden files (`.htaccess`, `.user.ini`) were uploaded too.
3. Check that the `data/` folder is writable (permission `755`, or `775` if needed).
4. Open `https://yt.yourdomain.com/` and the **installer** starts:
   - choose an admin password
   - confirm the site URL (it must start with `https://`)
   - choose the database. **SQLite** is recommended and needs no setup. MySQL also works: create the database in hPanel first and enter its details.
5. You land in the dashboard. Follow the 4-step setup shown on the welcome screen.

> **Upload limits:** `.user.ini` raises `upload_max_filesize` to 64 MB. Uploads are sent in chunks anyway, so even multi-GB videos work. On Hostinger you can also change PHP settings in hPanel → *Advanced → PHP Configuration*.

## Create Google Cloud credentials

Every channel needs an OAuth **Client ID** and **Client Secret** from Google Cloud. One OAuth client can serve several channels.

1. Go to [Google Cloud Console](https://console.cloud.google.com/projectcreate) and create a project.
2. Enable these APIs:
   - [YouTube Data API v3](https://console.cloud.google.com/apis/library/youtube.googleapis.com)
   - [YouTube Analytics API](https://console.cloud.google.com/apis/library/youtubeanalytics.googleapis.com)
3. Configure the **OAuth consent screen** ([Google Auth Platform → Branding](https://console.cloud.google.com/auth/branding)): choose *External* and fill in the app name and email.
   - While the app is in **Testing**, add each Google account that owns a channel under *Audience → Test users*. Note that refresh tokens of apps in testing **expire after 7 days**.
   - To get long-lived tokens, click **Publish app**. For personal use you can publish without verification. Google shows an "unverified app" warning that you can click through.
4. Create an **OAuth client** ([Clients → Create client](https://console.cloud.google.com/auth/clients)) with application type **Web application**.
5. Under **Authorized redirect URIs**, add:
   ```
   https://YOUR-SITE/google.php
   ```
   The exact value appears in the dashboard (Welcome screen and Settings).
6. Copy the **Client ID** and **Client Secret**.

### Scopes used

```
https://www.googleapis.com/auth/youtube
https://www.googleapis.com/auth/youtube.upload
https://www.googleapis.com/auth/youtube.force-ssl
https://www.googleapis.com/auth/yt-analytics.readonly
https://www.googleapis.com/auth/yt-analytics-monetary.readonly
```

## Add a channel

Dashboard → **Channels → Add channel**:

1. Paste the **Client ID** and **Client Secret**.
2. Get a refresh token in one of two ways:
   - **Option A (easiest):** click **Save & authorize**, sign in with the Google account that owns the channel, pick the channel (Brand Accounts are supported) and accept. The refresh token is stored automatically.
   - **Option B:** if you already have a **refresh token** (for example from the [OAuth 2.0 Playground](https://developers.google.com/oauthplayground) using your own client credentials and the scopes above), paste it and save.
3. Pick the **AI access** level:
   - **Full control**: the AI can upload, edit and delete
   - **Read only**: the AI can only view data and analytics
   - **Hidden**: the AI doesn't see this channel
4. Repeat for as many channels as you like. You can edit, re-authorize or delete them at any time.

## Connect to Claude

1. In Claude, open **Settings → Connectors → Add custom connector**.
2. Name it (e.g. *YouTube*) and paste your MCP URL:
   ```
   https://YOUR-SITE/mcp
   ```
   Leave *Advanced settings* empty.
3. Click **Connect**. A window opens on your site: enter the admin password and click **Allow access**.
4. In a chat, enable the connector from the tools menu and start asking:
   - *"Show my last 10 videos and tell me which performed best this month."*
   - *"Look at the thumbnail and frames of my latest video and suggest a better thumbnail."*
   - *"Read the transcript of my latest video and rewrite the title, description and tags for SEO."*
   - *"Schedule my private video to go public on Friday at 18:00."*
   - *"Write a weekly analytics report to the AI Notebook with charts."*

**Managing access:** the **Claude Connector** page in the dashboard lists connected sessions (you can revoke any of them), registered OAuth clients and all tools. It also has these options:
- **Dynamic client registration**: on by default. You can turn it off after connecting to lock the server down.
- **Manual OAuth client**: if you disable dynamic registration, create a client here and paste its ID and secret into Claude's *Advanced settings*.
- **Static API key**: for other MCP clients that don't support OAuth. Send it as `Authorization: Bearer <key>` or add `?key=<key>` to the URL.

## Tools available to the AI

| Tool | What it does | Access |
|---|---|---|
| `list_channels` | Connected channels, cached stats and AI access level | – |
| `get_channel` / `update_channel` | Live channel info / update description, keywords, country, language, trailer | read / full |
| `list_videos` | All uploads (public, unlisted, private, scheduled) with stats; search within the channel | read |
| `get_video` | Full details incl. processing info and YouTube suggestions; works on any public video too | read |
| `get_video_visuals` | Returns the thumbnail plus 3 auto frames **as images** so the AI can see the video | read |
| `update_video` | Title, description, tags (replace/add/remove), category, privacy, schedule, made-for-kids, license, embedding, AI-content disclosure… | full |
| `set_thumbnail` | Custom thumbnail from a URL or base64 | full |
| `delete_video` | Permanent delete (requires `confirm=true`) | full |
| `create_upload_link` | One-time page where the user drops a video file from any device | full |
| `upload_file_start` / `upload_file_chunk` | Upload a file **the AI itself has** (e.g. attached to the chat): raw bytes via `PUT upload.php` or base64 chunks; deleted from the server right after publishing | full |
| `upload_video` / `upload_status` / `upload_cancel` | Resumable upload from a URL or a dropped file, with optional thumbnail and playlist; progress; cancel and delete the staged file | full |
| `list_playlists` / `manage_playlist` | Read and create, edit, delete playlists; add, remove, move items | read / full |
| `list_comments` / `manage_comment` | Read threads (also held/spam) / reply, post, edit, delete, moderate | read / full |
| `list_captions` / `get_transcript` / `manage_caption` | Caption tracks, transcript text, upload or delete subtitles | read / full |
| `analytics_summary` | Totals vs the previous period, daily series, traffic, countries, devices, demographics, top videos, retention | read |
| `query_analytics` | Any YouTube Analytics v2 report | read |
| `search_youtube` | Public search for competitor and trend research (100 quota units) | read |
| `list_categories` | Video category ids | read |
| `pixabay_search` | Pixabay images or videos (filters: type, orientation, category, colors, min size, editor's choice…), URLs in every size plus a numbered preview grid. Shown only when a key is saved and the source is enabled | – |
| `wikimedia_search` | Wikimedia Commons images, videos or audio, with license, author, a ready attribution line and an optional public-domain/CC0-only filter, plus a preview grid | – |
| `preview_image` | Lets the AI look closely at one image from Pixabay, Wikimedia or YouTube | – |
| `telegram_chats` | Bot info, default chat and known chats (refreshes from recent updates) | – |
| `telegram_send_message` | Text with HTML/Markdown, inline buttons, silent / protected / reply options; long texts are split | – |
| `telegram_send_file` / `telegram_send_album` | Photo, video, document, audio, GIF, voice, video note, sticker, or a 2–10 item album. Source can be a URL, `file_id`, a file the AI sent (`upload_id`) or base64 | – |
| `telegram_file_start` | Lets the AI send a file it has (≤ 50 MB) via `PUT upload.php`; deleted from the server after sending | – |
| `telegram_read_messages` / `telegram_get_file` | Read incoming messages and button presses; view a received image or text file, or upload a received video to YouTube | – |
| `telegram_manage_message` | Edit, delete, pin, unpin, forward, copy, react | – |
| `telegram_api` | Any Bot API method (polls, chat settings, invite links, commands…). `logOut` / `close` are blocked | – |
| `notebook_write` / `notebook_read` / `notebook_update` | The AI Notebook | – |
| `record_snapshot` | Stores today's subscriber, view and video counts for the growth charts | read |

> **Quota:** the YouTube Data API gives 10,000 units/day by default. Most reads cost 1 unit, updates 50, an upload ~100, and a search 100. You can request more quota in Google Cloud.

## Uploading videos

Videos are always staged on your server first and then pushed to YouTube with the **resumable upload protocol** in 8 MB chunks. Each PHP request stays short, so hosting time limits don't matter and interrupted uploads can be resumed.

- **From the dashboard:** Upload page → drag a file and fill in the details. The browser sends the file in chunks.
- **Through Claude, with a file on your device:** Claude calls `create_upload_link`. You open the link on any device and drop the file. Claude then publishes it with the title, description and tags it wrote.
- **Through Claude, from a URL:** `upload_video` with a direct download link (`.mp4`, etc.).
- **Through Claude, with a file Claude has itself** (attached to the chat or generated in its sandbox): Claude calls `upload_file_start` (file name, exact size, metadata) and gets a one-time `put_url`. It then sends the bytes from its sandbox:
  ```bash
  curl -X PUT --data-binary @video.mp4 "https://YOUR-SITE/upload.php?t=TOKEN&offset=0"
  ```
  Large files go in several requests, each with `offset` = bytes received so far. When the last byte arrives, the server pushes the video to YouTube and **deletes its copy immediately** after YouTube confirms it.
  - Claude's sandbox needs network access to your domain. In claude.ai this depends on the code-execution network settings, and you may need to allow your domain there.
  - Small files can also go through the tool itself as base64 (`data_base64` or `upload_file_chunk`). Base64 is sent as text inside the conversation, so it only makes sense for files of a few hundred KB.

Staged files are deleted after publishing, or 48 hours after they expire.

## Media sources (Pixabay & Wikimedia Commons)

Dashboard → **Media sources**:

- **Pixabay**:
  1. Create a free account at [pixabay.com](https://pixabay.com/accounts/register/).
  2. Copy your key from the [API documentation page](https://pixabay.com/api/docs/) (*Parameters → key*).
  3. Paste it into the dashboard. The key is tested before it is saved, and stored encrypted.

  Results are cached for 24 hours, as Pixabay requires. The default limit is 100 requests per minute. Pixabay media is free to use, but when showing results you should mention that they come from Pixabay.
- **Wikimedia Commons**: works without a key. Each file has its own license (often CC BY or CC BY-SA), so the AI receives an `attribution` line to credit the author. Use `license=no_restrictions` for public domain / CC0 only.
- You can turn either source off. Disabled sources are hidden from Claude.
- Each search returns a **numbered preview grid** (built with PHP GD), so the AI can see the candidates and choose one. It then passes the URL to `set_thumbnail` (`image_url`) or `upload_video` (`source_url`).
- The page includes a search box so you can see exactly what Claude gets.

## Telegram bot

Dashboard → **Telegram**:

1. Open [@BotFather](https://t.me/BotFather), send `/newbot` and copy the token.
2. Paste the token and save. It is verified with `getMe`, then stored encrypted. The AI never sees it.
3. Send `/start` to your bot, or add it to a group, or make it an admin of a channel. Then click **Find chats**.
4. Pick a **default chat**. Optionally, restrict the bot to an **allow-list** of chats.

Notes:
- Bots can send files up to **50 MB** and download files up to **20 MB** (Telegram limits).
- Reading messages uses `getUpdates`, which doesn't work while the bot has a webhook set. Use a dedicated bot, or remove the webhook.
- Files the AI sends from its sandbox are staged on your server and deleted right after Telegram receives them. If a send fails, the file is kept for 6 hours so it can be retried.

## AI Notebook

A journal shown in the dashboard where the AI can store:

- daily, weekly or monthly summaries and reports
- insights, plans, tasks and a log of what it changed
- **KPI tiles** and **charts** (line, area, bar, donut) through the `data` field:

```json
{
  "metrics": [{ "label": "Views", "value": 12400, "delta": 8.5 }],
  "charts": [{
    "type": "line",
    "title": "Views per day",
    "labels": ["11-01", "11-02", "11-03"],
    "series": [{ "name": "Views", "data": [380, 420, 510] }]
  }]
}
```

The notebook is optional. The AI writes to it when you ask (for example *"keep a daily log in the notebook"*). You can also add, edit, pin or delete entries yourself.

## Security notes

- Google client secrets and refresh tokens are encrypted with AES-256-GCM using a random key generated at install (`data/config.php`). **Back up `data/`** and keep it private.
- `data/` and `lib/` are blocked by `.htaccess`. Check that `https://YOUR-SITE/data/config.php` returns **403**.
- OAuth access tokens are stored as SHA-256 hashes. Refresh tokens rotate on every use, and reusing an old one revokes the whole session.
- The admin login and the OAuth consent screen are rate-limited (8 failed attempts per 15 minutes per IP).
- Changing the admin password logs out all other browser sessions. Revoke AI sessions from the Connector page.
- Each channel's AI access level is enforced on the server for every tool call.

## Troubleshooting

| Problem | Fix |
|---|---|
| Claude says it can't connect / 401 loop | Make sure the site is on **HTTPS** and installed at the domain root. Open `https://YOUR-SITE/.well-known/oauth-authorization-server`: it should return JSON. If it returns 404, `.htaccess` was not uploaded or `mod_rewrite` is off. |
| `Authorization` header missing | Already handled in `.htaccess` (`E=HTTP_AUTHORIZATION`). On Nginx add `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`. |
| Google `redirect_uri_mismatch` | The redirect URI in Google Cloud must match the one shown in Settings **exactly** (`https://…/google.php`). |
| `invalid_grant` after a few days | Your OAuth app is in *Testing* mode, so tokens expire after 7 days. Publish the app, then re-authorize. |
| No refresh token returned | Remove the app at [myaccount.google.com/permissions](https://myaccount.google.com/permissions) and authorize again. |
| `quotaExceeded` | The daily API quota is used up. Wait for the reset (midnight Pacific time) or request more quota. |
| Custom thumbnail rejected | The channel must be verified (phone verification in YouTube Studio). The image must be JPG/PNG, ≤ 2 MB. |
| Analytics empty | YouTube Analytics data lags 24–72 h, and new channels have little data. |
| Upload stuck | Open the dashboard → Upload → *Recent uploads* → **Resume**. |

## Project structure

```
├── index.php          Dashboard shell (SPA)
├── install.php        First-run installer
├── login.php / logout.php
├── api.php            Dashboard JSON API (session + CSRF)
├── mcp.php            MCP endpoint  →  /mcp
├── authorize.php      OAuth authorize + consent  →  /authorize
├── token.php          OAuth token / revoke       →  /token
├── register.php       Dynamic client registration →  /register
├── well-known.php     /.well-known/oauth-* discovery
├── google.php         Google OAuth helper (gets refresh tokens)
├── drop.php           One-time public upload page
├── upload.php         Raw-bytes upload endpoint for files the AI sends to YouTube or Telegram (token auth)
├── lib/
│   ├── bootstrap.php  config, session, settings, auth helpers
│   ├── db.php         SQLite / MySQL layer + schema
│   ├── crypto.php     AES-GCM secrets, tokens
│   ├── youtube.php    YouTube Data + Analytics client, analytics summary
│   ├── uploads.php    staged / resumable upload jobs
│   ├── notebook.php   AI Notebook
│   ├── media.php      Pixabay & Wikimedia Commons search, preview grid
│   ├── telegram.php   Telegram Bot API (send, read, files, albums, raw calls)
│   ├── tools.php      MCP tool catalog (shared with the dashboard)
│   ├── oauth.php      OAuth server helpers
│   └── i18n.php       translations for server pages
├── assets/            CSS, JS (app, charts, i18n), logo
└── data/              database, config, staging (private, created at install)
```

## Updating

Upload the new files over the old ones, but **keep the `data/` folder**. Database changes are applied automatically on the next page load.

## License

MIT
