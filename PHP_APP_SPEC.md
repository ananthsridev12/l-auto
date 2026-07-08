# LinkedIn Scheduler — PHP Web App Specification

## Overview

A self-hosted web app running on shared hosting (PHP + MySQL) that lets users
schedule and auto-post LinkedIn content. Supports two content generation modes:
Claude API (in-app generation) and Manual/CSV (content prepared externally via
Claude Web or Claude Code).

Built to be multi-user: each person logs in, connects their own LinkedIn profile,
manages their own content calendar, and posts independently.

---

## Tech Stack

| Layer       | Technology                        |
|-------------|-----------------------------------|
| Server      | PHP 8.x (shared hosting)          |
| Database    | MySQL 5.7+                        |
| Auth        | Session-based (PHP sessions)      |
| Scheduling  | cPanel Cron Job (daily trigger)   |
| HTTP calls  | PHP cURL (LinkedIn API, Claude API)|
| Frontend    | Vanilla HTML/CSS/JS (no framework)|
| Styles      | Custom CSS — clean, LinkedIn-like UI |

---

## Two Content Modes

### Mode A — Claude API
- User enters their Anthropic API key in Settings
- App has a Generate tab: user inputs Topic, Format, Pillar, Target Persona, CTA
- App calls Claude API (`claude-sonnet-5` or latest) → returns caption + creative content
- Generated content saves to the posts table as a draft
- User reviews, edits with the LinkedIn text formatter, then schedules

### Mode B — Manual / CSV Upload
- User prepares content externally (using Claude Web or Claude Code desktop)
- CSV format matches the existing SolidPro calendar format:
  `Campaign_ID, Date, Final_Format, Topic/Title, Creative Content, Post Caption, ...`
- User uploads CSV to the app
- App parses it, creates draft posts for each row that has a Post Caption filled
- User reviews each post, edits caption, schedules

Both modes feed into the same Posts table and the same scheduling/posting engine.

---

## Core Features

1. **User accounts** — register/login, each user isolated
2. **LinkedIn OAuth** — connect personal LinkedIn profile (access token stored per user)
3. **Content calendar** — view scheduled posts by month
4. **Caption editor** — textarea with LinkedIn text formatter (Unicode bold/italic)
5. **Post now** — immediate publish to LinkedIn
6. **Auto-schedule** — set a date/time, cron job posts it automatically
7. **Slide images** — upload PNG slides (rendered locally via Python, uploaded here)
8. **Claude API generation** — in-app content generation (optional, requires API key)
9. **CSV import** — bulk import from existing content calendar CSV
10. **Post history** — log of all published posts with status

---

## Database Schema

```sql
-- Users
CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name          VARCHAR(255),
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- LinkedIn tokens (one per user)
CREATE TABLE linkedin_tokens (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL REFERENCES users(id),
  access_token  TEXT NOT NULL,
  person_urn    VARCHAR(255) NOT NULL,
  display_name  VARCHAR(255),
  expires_at    DATETIME,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- User settings (API keys, preferences)
CREATE TABLE user_settings (
  user_id           INT PRIMARY KEY REFERENCES users(id),
  anthropic_api_key VARCHAR(255),
  li_client_id      VARCHAR(255),
  li_client_secret  VARCHAR(255)
);

-- Posts
CREATE TABLE posts (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL REFERENCES users(id),
  campaign_id   VARCHAR(50),
  title         VARCHAR(500),
  format        ENUM('Single Image','Carousel','Text Post','Poll') NOT NULL,
  caption       TEXT,
  scheduled_at  DATETIME,
  posted_at     DATETIME,
  status        ENUM('draft','scheduled','posted','failed') DEFAULT 'draft',
  li_post_urn   VARCHAR(255),
  error_message TEXT,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Slide images (one post can have many slides)
CREATE TABLE post_slides (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  post_id     INT NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
  slide_order INT NOT NULL,
  filename    VARCHAR(255) NOT NULL,
  filepath    VARCHAR(500) NOT NULL
);

-- Generation log (tracks Claude API usage)
CREATE TABLE generation_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL REFERENCES users(id),
  post_id    INT REFERENCES posts(id),
  prompt     TEXT,
  response   TEXT,
  model      VARCHAR(100),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## File & Folder Structure

```
/linkedin-scheduler/          ← web root (public_html or subdomain)
├── index.php                 ← login/register page
├── logout.php
├── dashboard.php             ← redirects to /today
│
├── auth/
│   ├── linkedin_start.php    ← starts OAuth flow
│   └── linkedin_callback.php ← handles LinkedIn redirect
│
├── pages/
│   ├── today.php             ← today's post (main screen)
│   ├── calendar.php          ← monthly calendar view
│   ├── post.php              ← view/edit a specific post (?id=)
│   ├── generate.php          ← Claude API content generation tab
│   ├── import.php            ← CSV upload and import
│   ├── settings.php          ← LinkedIn credentials, Anthropic API key
│   └── history.php           ← published posts log
│
├── api/
│   ├── post_now.php          ← AJAX: post to LinkedIn immediately
│   ├── save_post.php         ← AJAX: save caption edits
│   ├── generate_content.php  ← AJAX: call Claude API
│   └── upload_slides.php     ← AJAX: upload PNG slide files
│
├── cron/
│   └── auto_post.php         ← called by cron job daily — posts scheduled content
│
├── includes/
│   ├── db.php                ← PDO database connection
│   ├── auth.php              ← session helpers, login check
│   ├── linkedin_api.php      ← LinkedIn API functions (upload image, upload doc, create post)
│   ├── claude_api.php        ← Anthropic API functions (generate caption)
│   ├── csv_parser.php        ← CSV import logic
│   └── helpers.php           ← shared utilities
│
├── assets/
│   ├── css/style.css
│   └── js/
│       ├── formatter.js      ← LinkedIn Unicode text formatter (bold/italic)
│       └── app.js            ← slide preview, AJAX post, char counter
│
├── uploads/
│   └── {user_id}/
│       └── {campaign_id}/    ← uploaded slide PNGs stored here
│           ├── slide_01.png
│           └── slide_02.png
│
└── config.php                ← DB credentials, app URL, LinkedIn version
```

---

## LinkedIn API Integration

Uses LinkedIn REST API with version header `202506` (or latest supported).

### OAuth Flow
1. `linkedin_start.php` — builds auth URL with scopes `openid profile w_member_social`, redirects user to LinkedIn
2. User approves → LinkedIn redirects to `linkedin_callback.php?code=...`
3. Callback exchanges code for access token via POST to `https://www.linkedin.com/oauth/v2/accessToken`
4. Fetches person URN from `https://api.linkedin.com/v2/userinfo` (field: `sub`)
5. Saves token + URN to `linkedin_tokens` table

### Posting Flow
**Single Image:**
1. POST to `/rest/images?action=initializeUpload` → get `uploadUrl` + `image` URN
2. PUT slide PNG bytes to `uploadUrl`
3. POST to `/rest/posts` with `content.media.id = image URN`

**Carousel (multiple slides):**
1. Combine slides into a single PDF (use PHP Imagick or GD, or accept pre-made PDF upload)
2. POST to `/rest/documents?action=initializeUpload` → get `uploadUrl` + `document` URN
3. PUT PDF bytes to `uploadUrl`
4. POST to `/rest/posts` with `content.media.id = document URN`
   - Sleep 3 seconds between upload and post to allow LinkedIn processing

**Text Post:**
- POST to `/rest/posts` with just `commentary` — no `content` key

**Required headers for all API calls:**
```
Authorization: Bearer {access_token}
LinkedIn-Version: 202506
X-Restli-Protocol-Version: 2.0.0
Content-Type: application/json
```

**Post body structure:**
```json
{
  "author": "urn:li:person:{id}",
  "commentary": "caption text here",
  "visibility": "PUBLIC",
  "distribution": { "feedDistribution": "MAIN_FEED" },
  "lifecycleState": "PUBLISHED"
}
```

---

## Claude API Integration (Mode A)

Uses Anthropic Messages API to generate LinkedIn post content.

**Endpoint:** `POST https://api.anthropic.com/v1/messages`

**Headers:**
```
x-api-key: {user's anthropic api key}
anthropic-version: 2023-06-01
content-type: application/json
```

**Prompt structure (system + user):**

System prompt:
```
You are a LinkedIn content writer for B2B technology companies. Write in a
professional but direct voice. No fluff, no buzzwords. Output only the caption
text — no preamble, no explanation.
```

User message (built from the Generate form):
```
Write a LinkedIn post caption for:
Topic: {topic}
Format: {format} (Single Image / Carousel)
Pillar: {pillar}
Target Persona: {persona}
CTA: {cta}

Rules:
- 150–250 words
- Hook in the first line (no "In today's world" or clichés)
- End with the CTA
- Add 3–5 relevant hashtags on the last line
- Do not use markdown — plain text only
```

**Model:** `claude-sonnet-5` (or pass as configurable setting)

---

## Cron Job Setup

In cPanel → Cron Jobs, add:

```
0 9 * * *   php /home/{username}/public_html/linkedin-scheduler/cron/auto_post.php
```

Runs at 9:00 AM daily.

**`auto_post.php` logic:**
1. Query `posts` table for rows where `status = 'scheduled'` AND `scheduled_at <= NOW()`
2. For each post, load the user's LinkedIn token
3. Call the posting flow (image/document/text as appropriate)
4. On success: update `status = 'posted'`, set `posted_at`, store `li_post_urn`
5. On failure: update `status = 'failed'`, store `error_message`
6. Log result

---

## LinkedIn Text Formatter (JavaScript)

Select text in the caption textarea → click B or I → converts to Unicode equivalents.

Unicode mappings to implement (same as the Python/local version):

| Style  | Uppercase start | Lowercase start | Digits start |
|--------|----------------|-----------------|--------------|
| Bold   | U+1D5D4 (𝗔)    | U+1D5EE (𝗮)    | U+1D7EC (𝟬)  |
| Italic | U+1D608 (𝘈)    | U+1D622 (𝘢)    | —            |

Clear Formatting: reverse map — convert Unicode bold/italic chars back to plain ASCII.

The formatter file already exists in the local Python app at:
`C:\Users\Mananthsri\Claude\LinkedIn_scheduler\static\js\formatter.js`
— copy this directly, it works as-is.

---

## CSV Import Format

Expected columns (in any order, header row required):

| Column         | Notes                                      |
|----------------|--------------------------------------------|
| Campaign_ID    | also accepts typo "Campign_ID"             |
| Date           | formats: MM/DD/YYYY, DD-Mon-YYYY, YYYY-MM-DD |
| Final_Format   | Single Image / Carousel / Text Post / Poll |
| Topic / Title  | prepended to caption on post               |
| Post Caption   | main post text (must be filled to import)  |
| Pillar         | stored for reference                       |
| Type           | stored for reference                       |

Rows where Post Caption is empty are skipped during import.
Rows where Final_Format is not one of the four valid values are skipped.

---

## Image Upload Flow (for slides)

Since image rendering (Python + Pillow) happens locally, the upload flow is:

1. User renders slides locally: `py render.py AS-LO-48`
2. Output PNGs are in `Auto-LinkedIn/output/AS-LO-48/slide_01.png` etc.
3. User opens the post in the web app → clicks "Upload Slides"
4. Multi-file upload (HTML `<input type="file" multiple accept="image/png">`)
5. PHP saves files to `uploads/{user_id}/{campaign_id}/slide_01.png` etc.
6. Slide paths saved to `post_slides` table

For carousels, PHP converts multiple PNGs to a PDF before uploading to LinkedIn.
Use PHP Imagick (if available on host) or accept a pre-made PDF upload as fallback.

---

## Key Pages — UI Description

### Today (`/pages/today.php`)
- Shows post scheduled for today's date
- Left panel: slide image viewer with prev/next arrows
- Right panel: editable caption textarea with formatter toolbar (B, I, Clear)
- Character counter (max 3,000)
- "Post Now" button → AJAX call → success/error message

### Calendar (`/pages/calendar.php`)
- Full month grid (7 columns Mon–Sun)
- Days with scheduled posts highlighted, colour-coded by format
- Click a day → goes to that post's edit page
- Today highlighted with border

### Generate (`/pages/generate.php`) — Mode A only
- Form: Topic, Format (dropdown), Pillar, Target Persona, CTA
- "Generate Caption" button → AJAX → calls Claude API → populates caption textarea
- Formatter toolbar available immediately
- "Save as Draft" → saves to posts table
- "Schedule" → pick date/time → saves with status = scheduled

### Import (`/pages/import.php`) — Mode B
- File upload: CSV drag-and-drop or click to browse
- Preview table showing parsed rows before confirming import
- "Import N posts" button → creates draft posts in database
- Shows count: imported / skipped / already exists

### Post Edit (`/pages/post.php?id=X`)
- Campaign ID, format badge, title
- Slide viewer (if slides uploaded)
- Caption editor with formatter
- Schedule picker: date + time
- Buttons: "Post Now" / "Schedule" / "Save Draft" / "Delete"

### Settings (`/pages/settings.php`)
- LinkedIn App: Client ID, Client Secret fields (saved to user_settings)
- Anthropic API Key (saved to user_settings, displayed masked)
- LinkedIn Connection status: connected as {name} / Reconnect button
- Redirect URI displayed for reference: `https://{domain}/auth/linkedin_callback.php`

---

## Security Notes

- All LinkedIn tokens and API keys stored in MySQL (not flat files)
- Passwords hashed with `password_hash()` / verified with `password_verify()`
- All DB queries use PDO prepared statements
- OAuth `state` parameter validated on callback to prevent CSRF
- File uploads: validate MIME type (image/png only), store outside web root if possible
- `config.php` excluded from web access via `.htaccess`
- Session IDs regenerated on login

---

## config.php Template

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

define('APP_URL',     'https://yourdomain.com/linkedin-scheduler');
define('APP_SECRET',  'random-32-char-string-for-sessions');

define('LI_VERSION',      '202506');
define('LI_REDIRECT_URI', APP_URL . '/auth/linkedin_callback.php');
define('LI_API_BASE',     'https://api.linkedin.com');
```

---

## Context: Existing Local Python App

A working local version already exists at:
`C:\Users\Mananthsri\Claude\LinkedIn_scheduler\`

It includes:
- `app.py` — Flask app with full LinkedIn OAuth + posting logic
- `content_loader.py` — CSV parsing, date detection, slide finding
- `static/js/formatter.js` — LinkedIn text formatter (copy directly to PHP app)
- `static/css/style.css` — UI styles (adapt for PHP app)
- `templates/` — HTML templates (adapt Jinja2 → plain PHP)

The LinkedIn API calls in `app.py` (upload_image, upload_document, create_linkedin_post)
are the reference implementation — replicate these in `includes/linkedin_api.php`.

---

## Build Order (Recommended)

1. `config.php` + `includes/db.php` — database connection
2. `schema.sql` — create all tables
3. `index.php` — login/register
4. `includes/auth.php` — session helpers
5. `includes/linkedin_api.php` — OAuth + posting functions
6. `auth/linkedin_start.php` + `auth/linkedin_callback.php` — OAuth flow
7. `pages/settings.php` — credentials form
8. `includes/csv_parser.php` + `pages/import.php` — CSV import
9. `pages/today.php` + `api/post_now.php` — post today (core feature)
10. `pages/calendar.php` — calendar view
11. `pages/post.php` — individual post editor
12. `cron/auto_post.php` — scheduled posting
13. `includes/claude_api.php` + `pages/generate.php` — Claude API mode (last, optional)
