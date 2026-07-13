-- LinkedIn Scheduler — database schema
-- Target: MySQL 5.7+/8.0 (shared hosting / cPanel), InnoDB, utf8mb4

CREATE TABLE IF NOT EXISTS users (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  email            VARCHAR(255) UNIQUE NOT NULL,
  password_hash    VARCHAR(255) NOT NULL,
  name             VARCHAR(255),
  -- Comma-separated subset of Single Image/Carousel/Text Post/Poll.
  -- NULL/empty = default (everything except Poll, which LinkedIn's Posts
  -- API cannot actually publish — see includes/helpers.php).
  enabled_formats  VARCHAR(255) DEFAULT NULL,
  -- Per-provider API keys for Content Studio / New Post AI generation
  -- (includes/ai_generate.php). Each is optional — a user can bring their
  -- own key for whichever provider(s) they want, or leave all blank and
  -- fall back to the admin-configured default provider/key in config.php
  -- (see resolve_ai_config() in includes/helpers.php). ai_provider is the
  -- user's preferred provider ('gemini'|'claude'|'openai'); NULL means
  -- "use the site default". The model used per provider is always an
  -- admin/config-level constant (GEMINI_MODEL/CLAUDE_MODEL/OPENAI_MODEL),
  -- never user-editable.
  gemini_api_key   VARCHAR(255) DEFAULT NULL,
  claude_api_key   VARCHAR(255) DEFAULT NULL,
  openai_api_key   VARCHAR(255) DEFAULT NULL,
  ai_provider      VARCHAR(20) DEFAULT NULL,
  -- Free-text brand/voice/business context, prepended to every AI
  -- generation call alongside any selected persona/content pillar below.
  -- brand_brief covers company-related posts; self_brief is the personal-
  -- voice counterpart used for personal-category content pillars (see
  -- content_pillars.category below) — achievements, opinions, life
  -- events, not company messaging.
  brand_brief      TEXT DEFAULT NULL,
  self_brief       TEXT DEFAULT NULL,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Connected LinkedIn accounts: personal profile OR company page.
-- Multiple rows per user_id are allowed.
CREATE TABLE IF NOT EXISTS linkedin_accounts (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  account_type   ENUM('personal','company') NOT NULL,
  target_urn     VARCHAR(255) NOT NULL,
  display_name   VARCHAR(255) NOT NULL,
  linkedin_name  VARCHAR(255),
  access_token   TEXT NOT NULL,
  refresh_token  TEXT,
  expires_at     DATETIME,
  scopes         VARCHAR(255),
  status         ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_target (user_id, target_urn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User-maintained directory of OTHER organizations' pages to tag (not
-- owned/connected by this user — LinkedIn's org lookup API only works
-- for pages you administer, so there's no way to search these
-- programmatically; the user supplies the numeric org ID themselves).
-- Merged into the "@ Tag" picker's candidate list alongside
-- linkedin_accounts. See includes/post_helpers.php get_mention_candidates().
CREATE TABLE IF NOT EXISTS tag_directory (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  target_urn   VARCHAR(255) NOT NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_name (user_id, display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Content Knowledge Base: reusable context a user builds up once and
-- picks from when generating (New Post's AI panel) or that's applied
-- automatically (brand_brief) instead of retyping brand context every
-- time. See includes/ai_generate.php build_generation_prompt().
CREATE TABLE IF NOT EXISTS personas (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  name         VARCHAR(255) NOT NULL,
  description  TEXT,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_persona (user_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- category drives the Content Calendar Generator's Company/Personal mix
-- (see includes/calendar_planner.php) and which brief (brand_brief vs
-- self_brief) gets used as context when generating this pillar's posts.
CREATE TABLE IF NOT EXISTS content_pillars (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  name         VARCHAR(255) NOT NULL,
  description  TEXT,
  category     ENUM('company','personal') NOT NULL DEFAULT 'company',
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_pillar (user_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- funnel_stage is a plain attribute rather than a separate funnel-builder
-- entity — enough to let a CTA be filtered/labeled by where it fits
-- without a whole new funnel-modeling UI.
CREATE TABLE IF NOT EXISTS cta_library (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  text          VARCHAR(500) NOT NULL,
  funnel_stage  ENUM('Awareness','Consideration','Decision','Retention') DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-user brand colors for rendered images, selectable as a "template"
-- alongside the 4 built-in SolidPro presets (see includes/image_renderer.php
-- render_resolve_palette_colors() / render_derive_palette_colors()).
-- accent_color/cta_color are optional — NULL triggers an auto-derived
-- tint so a user only ever has to pick 2 colors if they want to keep it
-- simple. Only one palette per user should have is_default = 1 (enforced
-- in application code, not a DB constraint — see
-- includes/post_helpers.php set_default_brand_palette()).
CREATE TABLE IF NOT EXISTS brand_palettes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  name          VARCHAR(255) NOT NULL,
  bg_color      VARCHAR(7) NOT NULL,
  text_color    VARCHAR(7) NOT NULL,
  accent_color  VARCHAR(7) DEFAULT NULL,
  cta_color     VARCHAR(7) DEFAULT NULL,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_palette_name (user_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-user uploaded fonts (Regular + Bold TTF/OTF pair) — a library to
-- pick from, not a single active font. is_default is unused (superseded
-- by users.heading_font_id/body_font_id below, which assign a font per
-- role instead of one font for everything); kept only so an earlier
-- deploy of this table doesn't need a destructive migration.
CREATE TABLE IF NOT EXISTS brand_fonts (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  name          VARCHAR(255) NOT NULL,
  regular_path  VARCHAR(500) NOT NULL,
  bold_path     VARCHAR(500) NOT NULL,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_font_name (user_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which brand_fonts row (if any) each user has assigned to each role —
-- see includes/image_renderer.php render_font_override_role(). NULL
-- means fall back to the bundled Inter/DejaVu chain for that role.
-- Added via ALTER (not inline on the users table above) because
-- brand_fonts has to exist first for the foreign keys to be valid.
ALTER TABLE users
  ADD COLUMN heading_font_id INT DEFAULT NULL,
  ADD COLUMN body_font_id INT DEFAULT NULL,
  ADD FOREIGN KEY (heading_font_id) REFERENCES brand_fonts(id) ON DELETE SET NULL,
  ADD FOREIGN KEY (body_font_id) REFERENCES brand_fonts(id) ON DELETE SET NULL;

-- Which of the two font roles above the rendered footer *name* text uses
-- — see includes/helpers.php get_footer_font_role() and
-- includes/image_renderer.php render_footer_simple()/render_footer_with_photo().
-- Defaults to 'body', matching the behavior before this toggle existed.
ALTER TABLE users
  ADD COLUMN footer_font_role ENUM('heading','body') NOT NULL DEFAULT 'body';

-- Independent "Signature" font for the footer name, separate from
-- Heading/Body — takes priority over footer_font_role above when set.
-- NULL falls back to the Heading/Body toggle (unchanged default).
ALTER TABLE users
  ADD COLUMN footer_font_id INT DEFAULT NULL,
  ADD FOREIGN KEY (footer_font_id) REFERENCES brand_fonts(id) ON DELETE SET NULL;

-- Manual size override for the footer signature — a brand-wide
-- typography choice, not tied to any one palette, so it stays a single
-- global per-user setting (unlike signature_color below, which is
-- per-palette). NULL keeps the built-in auto size. Stored as a literal
-- rendered pixel size (not the internal 1080-design-basis unit render.php
-- scales from) so what a user types is exactly what they get. See
-- includes/image_renderer.php render_footer_simple()/render_footer_with_photo().
ALTER TABLE users
  ADD COLUMN footer_name_size INT DEFAULT NULL;

-- Earlier revision of this feature added a global footer_name_color on
-- users — dropped in favor of a per-palette signature_color below, since
-- a single flat color doesn't harmonize across different palettes the
-- way each palette's own derived color does. No data migration: this
-- column was added and dropped within the same development pass, before
-- any real user data depended on it.
ALTER TABLE users DROP COLUMN footer_name_color;

-- Optional per-palette override for the footer signature's color — same
-- optional/auto-generate pattern as accent_color/cta_color above. NULL
-- keeps the palette's existing auto-derived color (role 'name'/
-- 'accent_text'/'headline' depending on layout — see
-- render_footer_simple()/render_footer_with_photo()). Only meaningful on
-- custom palettes; the 4 built-in presets (render_palettes()) are fixed
-- and don't get a settable signature color.
ALTER TABLE brand_palettes
  ADD COLUMN signature_color VARCHAR(7) DEFAULT NULL;

-- Optional per-palette background image, selectable per-post via the
-- existing "Background" choice (flat/gradient/image — see
-- includes/image_renderer.php render_draw_background()). File-based like
-- brand_logo/footer images, not a BLOB — stored at
-- UPLOAD_DIR/{userId}/palette_backgrounds/{paletteId}.{ext} (see
-- pages/settings.php palette_bg_image_upload). Only meaningful on custom
-- palettes; the 4 built-in presets have no image. Cover-fit cropped to
-- the render canvas at draw time regardless of the uploaded image's
-- exact aspect ratio, so a near-square upload still renders cleanly.
ALTER TABLE brand_palettes
  ADD COLUMN background_image_path VARCHAR(500) DEFAULT NULL;

-- Auto-assigns a Design Template so bulk generation (Content Studio CSV
-- upload, Content Calendar Generator) doesn't require picking one by
-- hand for every row/post — see includes/post_helpers.php
-- resolve_default_layout(), called from api/content_studio_preview.php
-- and api/calendar_generate_one.php right after a row's creative JSON is
-- built. Resolution order: a pillar match (this column) beats the
-- per-user format default (users.default_layout_single/_carousel)
-- beats 'classic'. NULL means "no pillar-specific override" — falls
-- through to the format default.
ALTER TABLE content_pillars
  ADD COLUMN default_layout VARCHAR(50) DEFAULT NULL;

-- Per-user format-level Design Template defaults — the fallback tier
-- below content_pillars.default_layout above. NULL keeps today's
-- 'classic' default.
ALTER TABLE users
  ADD COLUMN default_layout_single VARCHAR(50) DEFAULT NULL,
  ADD COLUMN default_layout_carousel VARCHAR(50) DEFAULT NULL;

-- Same auto-assignment idea as default_layout above, but for the Color
-- Palette (the "template" creative-JSON field — an int 1-4 for a
-- built-in preset, or "custom:{id}" for a saved brand_palettes row —
-- see includes/image_renderer.php render_resolve_palette_colors()). NULL
-- at every tier means "no override" — leaves render_resolve_palette_colors()'s
-- own existing fallback (user's default custom palette, else series-
-- label keyword matching) to decide, same as before this feature existed.
-- See includes/post_helpers.php resolve_default_palette().
ALTER TABLE content_pillars
  ADD COLUMN default_palette VARCHAR(50) DEFAULT NULL;

ALTER TABLE users
  ADD COLUMN default_palette_single VARCHAR(50) DEFAULT NULL,
  ADD COLUMN default_palette_carousel VARCHAR(50) DEFAULT NULL;

-- News-driven auto content (see includes/news_fetch.php, cron/news_daily.php,
-- pages/news_studio.php). Topics = extra Google News search queries beyond
-- the Content Pillar names that are always searched; items = fetched
-- headlines, deduped per user by url_hash, each usable once as a draft.
CREATE TABLE IF NOT EXISTS news_topics (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  query      VARCHAR(255) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_query (user_id, query)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS news_items (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NOT NULL,
  topic_query       VARCHAR(255) NOT NULL,
  content_pillar_id INT NULL,
  title             VARCHAR(500) NOT NULL,
  url               VARCHAR(1000) NOT NULL,
  url_hash          CHAR(40) NOT NULL,
  source            VARCHAR(255) NULL,
  published_at      DATETIME NULL,
  fetched_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  status            ENUM('new','used','dismissed') NOT NULL DEFAULT 'new',
  post_id           INT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (content_pillar_id) REFERENCES content_pillars(id) ON DELETE SET NULL,
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_user_url (user_id, url_hash),
  INDEX idx_user_status (user_id, status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 0 = news auto-drafting off (News Studio still works manually);
-- news_drafts_per_day caps what cron/news_daily.php generates.
ALTER TABLE users
  ADD COLUMN news_auto_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN news_drafts_per_day TINYINT NOT NULL DEFAULT 2;

-- Trusted publishers for Google News results — when a user has any rows
-- here, only headlines whose <source> matches an entry (domain or name,
-- see includes/news_fetch.php news_source_is_trusted()) are stored.
-- Empty = no filtering. Direct feed URLs in news_topics bypass this.
CREATE TABLE IF NOT EXISTS news_trusted_sources (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  source     VARCHAR(255) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_source (user_id, source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One Content Calendar Generator run (see includes/calendar_planner.php,
-- pages/content_calendar.php). Groups the posts it planned and tracks
-- which stage of the content-approve -> image-approve -> schedule flow
-- the batch is in. mix_config is the submitted % preferences, kept for
-- reference/audit, not re-read by the app after generation.
CREATE TABLE IF NOT EXISTS calendar_batches (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  period_days     INT NOT NULL,
  posts_per_week  INT NOT NULL,
  start_date      DATE NOT NULL,
  status          ENUM('content_review','image_review','ready','scheduled') NOT NULL DEFAULT 'content_review',
  mix_config      JSON,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tracks each CSV+ZIP bulk import run.
CREATE TABLE IF NOT EXISTS import_batches (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  user_id                  INT NOT NULL,
  csv_filename             VARCHAR(255),
  zip_filename             VARCHAR(255),
  row_count                INT DEFAULT 0,
  imported_count           INT DEFAULT 0,
  skipped_count            INT DEFAULT 0,
  unmatched_account_count  INT DEFAULT 0,
  already_posted_count     INT DEFAULT 0,
  created_at               DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS posts (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  user_id             INT NOT NULL,
  linkedin_account_id INT NULL,
  import_batch_id     INT NULL,
  campaign_id         VARCHAR(50),
  title               VARCHAR(500),
  format              ENUM('Single Image','Carousel','Text Post','Poll') NOT NULL,
  caption             TEXT,
  source_page_label   VARCHAR(255),
  scheduled_at        DATETIME NULL,
  posted_at           DATETIME NULL,
  status              ENUM('draft','scheduled','posted','failed') DEFAULT 'draft',
  li_post_urn         VARCHAR(255),
  error_message       TEXT,
  -- Content Calendar Generator fields — NULL for posts created any other
  -- way (New Post, Import, Content Studio). creative_json holds the full
  -- generated creative (title/caption/hashtags/slides) between content
  -- generation and image rendering — see includes/calendar_planner.php
  -- and pages/calendar_batch.php. status stays 'draft' through both
  -- content_approved_at and image_approved_at so cron never touches these
  -- early; only the final "Confirm & Schedule" step flips it to
  -- 'scheduled', even though scheduled_at is already set at plan time.
  calendar_batch_id   INT NULL,
  content_pillar_id   INT NULL,
  persona_id          INT NULL,
  creative_json       LONGTEXT NULL,
  content_approved_at DATETIME NULL,
  image_approved_at   DATETIME NULL,
  created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (linkedin_account_id) REFERENCES linkedin_accounts(id) ON DELETE SET NULL,
  FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL,
  FOREIGN KEY (calendar_batch_id) REFERENCES calendar_batches(id) ON DELETE SET NULL,
  FOREIGN KEY (content_pillar_id) REFERENCES content_pillars(id) ON DELETE SET NULL,
  FOREIGN KEY (persona_id) REFERENCES personas(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_user_campaign (user_id, campaign_id),
  INDEX idx_scheduled (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS post_slides (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  post_id     INT NOT NULL,
  slide_order INT NOT NULL,
  filename    VARCHAR(255) NOT NULL,
  filepath    VARCHAR(500) NOT NULL,
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_post_order (post_id, slide_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Workspaces: full personal/company segregation ────────────────────
-- One 'personal' workspace per user (created at signup and by
-- scripts/migrate_workspaces.php for existing users) plus one workspace
-- per company page. Each workspace owns its knowledge hub (profile
-- fields below + workspace-scoped pillars/personas/CTAs/news topics +
-- knowledge_documents) and its content (posts/calendar batches). The
-- 'about' field replaces users.brand_brief/self_brief, which remain but
-- are no longer read after migration. Voice (company vs personal) is
-- now the workspace type, not content_pillars.category.
CREATE TABLE IF NOT EXISTS workspaces (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  type ENUM('personal','company') NOT NULL,
  name VARCHAR(255) NOT NULL,
  linkedin_account_id INT NULL,
  about TEXT NULL,
  industry VARCHAR(255) NULL,
  target_audience TEXT NULL,
  tone_of_voice TEXT NULL,
  goals TEXT NULL,
  content_rules TEXT NULL,
  website VARCHAR(500) NULL,
  news_auto_enabled TINYINT(1) NOT NULL DEFAULT 0,
  news_drafts_per_day TINYINT NOT NULL DEFAULT 2,
  default_layout_single VARCHAR(50) DEFAULT NULL,
  default_layout_carousel VARCHAR(50) DEFAULT NULL,
  default_palette_single VARCHAR(50) DEFAULT NULL,
  default_palette_carousel VARCHAR(50) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (linkedin_account_id) REFERENCES linkedin_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Uploaded reference documents (PDF/DOCX/TXT/MD) whose extracted text
-- feeds AI generation context for the workspace — see Phase B,
-- includes/kb_documents.php.
CREATE TABLE IF NOT EXISTS knowledge_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  filepath VARCHAR(500) NOT NULL,
  kind ENUM('pdf','docx','txt','md') NOT NULL,
  extracted_text LONGTEXT NULL,
  summary TEXT NULL,
  uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NULL workspace_id = pre-migration row (treated as visible everywhere
-- until scripts/migrate_workspaces.php backfills it).
ALTER TABLE content_pillars ADD COLUMN workspace_id INT NULL;
ALTER TABLE personas ADD COLUMN workspace_id INT NULL;
ALTER TABLE cta_library ADD COLUMN workspace_id INT NULL;
ALTER TABLE news_topics ADD COLUMN workspace_id INT NULL;
ALTER TABLE news_items ADD COLUMN workspace_id INT NULL;
ALTER TABLE news_trusted_sources ADD COLUMN workspace_id INT NULL;
ALTER TABLE calendar_batches ADD COLUMN workspace_id INT NULL;
ALTER TABLE posts ADD COLUMN workspace_id INT NULL;

-- Names/queries are unique per WORKSPACE now, not per user — two
-- workspaces can (and after seeding, do) each have a pillar named
-- "Case Study / Results". NULL workspace_id rows don't collide (MySQL
-- unique indexes permit repeated NULLs), which is fine for the short
-- pre-migration window.
ALTER TABLE content_pillars DROP INDEX uniq_user_pillar, ADD UNIQUE KEY uniq_user_ws_pillar (user_id, workspace_id, name);
ALTER TABLE personas DROP INDEX uniq_user_persona, ADD UNIQUE KEY uniq_user_ws_persona (user_id, workspace_id, name);
ALTER TABLE news_topics DROP INDEX uniq_user_query, ADD UNIQUE KEY uniq_user_ws_query (user_id, workspace_id, query);
ALTER TABLE news_trusted_sources DROP INDEX uniq_user_source, ADD UNIQUE KEY uniq_user_ws_source (user_id, workspace_id, source);
ALTER TABLE news_items DROP INDEX uniq_user_url, ADD UNIQUE KEY uniq_user_ws_url (user_id, workspace_id, url_hash);

-- ── Memory & Context: anti-repetition, natural topic continuation ────
-- One row per generated LinkedIn/blog post: a short summary (the
-- caption/title itself — already compact, no separate summarization
-- call needed) plus its embedding vector. See includes/content_memory.php
-- content_memory_find_related() (brute-force cosine similarity in PHP —
-- realistic per-workspace volume never justifies a vector DB) and
-- includes/embeddings.php ai_generate_embedding(). blog_post_id has no FK
-- yet — added when blog_posts exists (Phase F).
CREATE TABLE IF NOT EXISTS content_memory (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL,
  post_id INT NULL,
  blog_post_id INT NULL,
  content_type ENUM('linkedin','blog') NOT NULL DEFAULT 'linkedin',
  summary TEXT NOT NULL,
  embedding LONGTEXT NOT NULL,
  embedding_model VARCHAR(100) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  INDEX idx_workspace_type (workspace_id, content_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Reddit as a News Studio trend source (Phase E) ────────────────────
-- Free Reddit "script" OAuth app credentials (client_credentials grant,
-- app-only — no Reddit user account tied), stored per-user like the
-- existing AI provider keys above. news_topics.source_type lets a topic
-- be a subreddit name instead of a Google News search query/feed URL;
-- news_refresh() (includes/news_fetch.php) branches on it. 'auto'
-- preserves every existing row's behavior unchanged.
ALTER TABLE users ADD COLUMN reddit_client_id VARCHAR(255) DEFAULT NULL;
ALTER TABLE users ADD COLUMN reddit_client_secret VARCHAR(255) DEFAULT NULL;
ALTER TABLE news_topics ADD COLUMN source_type ENUM('auto','reddit') NOT NULL DEFAULT 'auto';

-- ── Blog content + WordPress publishing (Phase F) ─────────────────────
-- One WordPress site per workspace (Application Password, not the
-- account password — a scoped/revocable credential, same risk profile
-- as linkedin_accounts.access_token). publish_target stays a plain
-- string, not an ENUM locked to 'wordpress' — the user wasn't sure
-- WordPress is the final platform, so adding another target later means
-- a new publish_to_{target}() function (includes/wordpress_api.php is
-- the first), not a schema change.
ALTER TABLE workspaces ADD COLUMN wordpress_url VARCHAR(500) NULL;
ALTER TABLE workspaces ADD COLUMN wordpress_username VARCHAR(255) NULL;
ALTER TABLE workspaces ADD COLUMN wordpress_app_password VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS blog_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  workspace_id INT NOT NULL,
  news_item_id INT NULL,
  title VARCHAR(500) NOT NULL,
  slug VARCHAR(500) NOT NULL,
  meta_description VARCHAR(500) NULL,
  keywords VARCHAR(500) NULL,
  content_html LONGTEXT NOT NULL,
  status ENUM('draft','scheduled','published','failed') NOT NULL DEFAULT 'draft',
  scheduled_at DATETIME NULL,
  published_at DATETIME NULL,
  publish_target VARCHAR(50) NOT NULL DEFAULT 'wordpress',
  external_post_id VARCHAR(100) NULL,
  external_url VARCHAR(500) NULL,
  error_message TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (news_item_id) REFERENCES news_items(id) ON DELETE SET NULL,
  INDEX idx_workspace_status (workspace_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- content_memory (Phase D) was created before blog_posts existed, so
-- its blog_post_id FK is added here now that the target table is real.
ALTER TABLE content_memory ADD CONSTRAINT fk_content_memory_blog_post
  FOREIGN KEY (blog_post_id) REFERENCES blog_posts(id) ON DELETE CASCADE;

-- ── Jekyll as a second Blog Studio publish target ──
-- Jekyll has no live API; "publishing" means committing a markdown
-- file with front matter to the site's GitHub repo via the Contents
-- API (includes/jekyll_api.php). One repo per workspace, same shape
-- as the one-WordPress-site-per-workspace columns above.
ALTER TABLE workspaces ADD COLUMN jekyll_repo VARCHAR(255) NULL;
ALTER TABLE workspaces ADD COLUMN jekyll_branch VARCHAR(100) NULL;
ALTER TABLE workspaces ADD COLUMN jekyll_token VARCHAR(255) NULL;
ALTER TABLE workspaces ADD COLUMN jekyll_posts_path VARCHAR(255) NULL;
ALTER TABLE workspaces ADD COLUMN jekyll_site_url VARCHAR(500) NULL;

-- ── Grav as a third Blog Studio publish target ──
-- Grav is a live PHP CMS (no build/deploy step) with an official REST
-- API plugin (getgrav/grav-plugin-api) — publishing here makes the
-- post live immediately via includes/grav_api.php.
ALTER TABLE workspaces ADD COLUMN grav_site_url VARCHAR(500) NULL;
ALTER TABLE workspaces ADD COLUMN grav_api_key VARCHAR(255) NULL;
ALTER TABLE workspaces ADD COLUMN grav_route_prefix VARCHAR(255) NULL;
ALTER TABLE workspaces ADD COLUMN grav_template VARCHAR(100) NULL;
