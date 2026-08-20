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

-- Optional per-palette overrides for the derived text colors that were
-- previously always computed (body: text blended 35% toward bg;
-- accent_text/badge_text/cta_text: best-contrast auto-pick) — see
-- includes/image_renderer.php render_derive_palette_colors(). NULL
-- keeps the existing auto-derived behavior; a set hex is used
-- literally, same optional/auto-generate pattern as accent_color/
-- cta_color/signature_color above. Only meaningful on custom palettes;
-- the 4 built-in presets (render_palettes()) are fully fixed.
ALTER TABLE brand_palettes
  ADD COLUMN body_color VARCHAR(7) DEFAULT NULL,
  ADD COLUMN accent_text_color VARCHAR(7) DEFAULT NULL,
  ADD COLUMN badge_text_color VARCHAR(7) DEFAULT NULL,
  ADD COLUMN cta_text_color VARCHAR(7) DEFAULT NULL;

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

-- Keyword "search approach" — SEO (literal keyword, unchanged default
-- behavior) vs AI Mode (AI-expanded related queries, see
-- includes/news_fetch.php news_generate_ai_expansion()).
ALTER TABLE news_topics ADD COLUMN search_approach ENUM('seo','ai_mode') NOT NULL DEFAULT 'seo';
ALTER TABLE news_topics ADD COLUMN ai_expanded_queries TEXT NULL;

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

-- ── Knowledge Base expansion, Phase 0 — richer Company Identity + Tone ──
-- See docs/KNOWLEDGE_BASE.md. Everything here is optional/nullable and
-- purely additive to the existing `about`/`tone_of_voice`/etc columns
-- above — a workspace that never fills these in behaves exactly as
-- before (workspace_context_text() only mentions a field when it's
-- non-blank). Applies identically to Personal and Company workspaces.
ALTER TABLE workspaces ADD COLUMN tagline VARCHAR(500) NULL;
ALTER TABLE workspaces ADD COLUMN founded_year VARCHAR(10) NULL;
ALTER TABLE workspaces ADD COLUMN company_size VARCHAR(100) NULL;
ALTER TABLE workspaces ADD COLUMN hq_location VARCHAR(255) NULL;
ALTER TABLE workspaces ADD COLUMN mission TEXT NULL;
ALTER TABLE workspaces ADD COLUMN vision TEXT NULL;
ALTER TABLE workspaces ADD COLUMN story TEXT NULL;
ALTER TABLE workspaces ADD COLUMN credibility_statement TEXT NULL;
ALTER TABLE workspaces ADD COLUMN notable_clients TEXT NULL;
ALTER TABLE workspaces ADD COLUMN awards TEXT NULL;

ALTER TABLE workspaces ADD COLUMN tone_descriptors VARCHAR(500) NULL;
ALTER TABLE workspaces ADD COLUMN anti_tone VARCHAR(500) NULL;
ALTER TABLE workspaces ADD COLUMN words_always TEXT NULL;
ALTER TABLE workspaces ADD COLUMN words_never TEXT NULL;
ALTER TABLE workspaces ADD COLUMN post_opening_style TEXT NULL;
ALTER TABLE workspaces ADD COLUMN hook_style TEXT NULL;
ALTER TABLE workspaces ADD COLUMN hashtag_strategy TEXT NULL;
ALTER TABLE workspaces ADD COLUMN post_frequency VARCHAR(100) NULL;
ALTER TABLE workspaces ADD COLUMN cta_linkedin TEXT NULL;
ALTER TABLE workspaces ADD COLUMN paragraph_style ENUM('one-liners','full-paragraphs','bullet-heavy') NULL;
ALTER TABLE workspaces ADD COLUMN good_example TEXT NULL;
ALTER TABLE workspaces ADD COLUMN bad_example TEXT NULL;

-- Free-form extra instructions appended to every AI prompt for this
-- workspace — the "custom_instructions" field from the KB design doc's
-- ai_settings block, kept here rather than a new table since it's a
-- single per-workspace value, same shape as content_rules above.
ALTER TABLE workspaces ADD COLUMN custom_instructions TEXT NULL;

-- ── Knowledge Base expansion, Phase 1 — Senders ──
-- See docs/KNOWLEDGE_BASE.md. A "sender" is the person whose voice a
-- post is written in — for a Personal workspace this is usually the
-- user themselves (one row, is_default=1); a Company workspace can
-- have several (different employees ghostwriting under the same
-- brand). Scoped by workspace_id alone, matching knowledge_documents'
-- convention (no separate user_id column needed).
CREATE TABLE IF NOT EXISTS `senders` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id`      INT NOT NULL,
  `full_name`         VARCHAR(255) NOT NULL,
  `title`             VARCHAR(255) NULL,
  `linkedin_headline` VARCHAR(300) NULL,
  `linkedin_about`    TEXT NULL,
  `background`        TEXT NULL,          -- career summary for AI context
  `credibility`       TEXT NULL,          -- why this person is worth listening to
  `years_experience`  INT NULL,
  `individual_tone`   TEXT NULL,          -- how this person specifically writes
  `example_posts`     TEXT NULL,          -- paste 2-3 real LinkedIn posts as style examples
  `post_topics`       TEXT NULL,          -- comma-sep topics this person covers
  `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Knowledge Base expansion, Phase 2 — richer Personas ──
-- See docs/KNOWLEDGE_BASE.md. All nullable/optional and additive to
-- the existing name+description-only personas — a persona that only
-- ever had those two fields filled in behaves exactly as before.
-- vertical_id/service_id links come later (Phase 6), once those
-- tables exist.
ALTER TABLE personas ADD COLUMN title VARCHAR(255) NULL;
ALTER TABLE personas ADD COLUMN department VARCHAR(255) NULL;
ALTER TABLE personas ADD COLUMN seniority ENUM('C-Suite','VP','Director','Manager','Individual Contributor') NULL;
ALTER TABLE personas ADD COLUMN reporting_to VARCHAR(255) NULL;
ALTER TABLE personas ADD COLUMN goals TEXT NULL;
ALTER TABLE personas ADD COLUMN pain_points TEXT NULL;
ALTER TABLE personas ADD COLUMN objections TEXT NULL;
ALTER TABLE personas ADD COLUMN kpis VARCHAR(500) NULL;
ALTER TABLE personas ADD COLUMN decision_role ENUM('Economic Buyer','Champion','Technical Buyer','End User','Influencer','Blocker') NULL;
ALTER TABLE personas ADD COLUMN communication_style TEXT NULL;
ALTER TABLE personas ADD COLUMN preferred_content VARCHAR(500) NULL;
ALTER TABLE personas ADD COLUMN watering_holes VARCHAR(500) NULL;
ALTER TABLE personas ADD COLUMN content_hook TEXT NULL;

-- ── Knowledge Base expansion, Phase 3 — Verticals ──
-- See docs/KNOWLEDGE_BASE.md. A vertical is a business unit / practice
-- area / focus area; Services (Phase 4), ICPs (Phase 5) and Proof
-- Points (Phase 7) can optionally hang off one. Scoped by workspace_id
-- alone, same convention as senders/knowledge_documents.
CREATE TABLE IF NOT EXISTS `verticals` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id`    INT NOT NULL,
  `name`            VARCHAR(255) NOT NULL,
  `focus`           TEXT NULL,
  `industries`      TEXT NULL,
  `priority`        ENUM('core','growth','emerging') NOT NULL DEFAULT 'core',
  `differentiators` TEXT NULL,
  `head_name`       VARCHAR(255) NULL,
  `positioning`     TEXT NULL,
  `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Knowledge Base expansion, Phase 4 — Services ──
-- See docs/KNOWLEDGE_BASE.md. The most important table for
-- signal-matching (Phase 9/10 use signal_keywords/signal_types/
-- tech_triggers to detect which service to pitch). vertical_id is
-- optional — a service doesn't have to belong to a vertical.
CREATE TABLE IF NOT EXISTS `services` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id`      INT NOT NULL,
  `vertical_id`       INT NULL,
  `name`              VARCHAR(255) NOT NULL,
  `one_liner`         VARCHAR(500) NULL,
  `industries`        TEXT NULL,
  `icp_size`          VARCHAR(255) NULL,
  `buyer_titles`      TEXT NULL,
  `engagement_model`  VARCHAR(100) NULL,
  `signal_keywords`   TEXT NULL,
  `signal_types`      TEXT NULL,
  `tech_triggers`     TEXT NULL,
  `competing_tools`   TEXT NULL,
  `description`       TEXT NULL,
  `problem_statement` TEXT NULL,
  `outcomes`          TEXT NULL,
  `differentiators`   TEXT NULL,
  `proof_points`      TEXT NULL,
  `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vertical_id`) REFERENCES `verticals`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Knowledge Base expansion, Phase 5 — ICPs (Ideal Customer Profiles) ──
-- See docs/KNOWLEDGE_BASE.md. Company-level "who's the perfect
-- customer" — distinct from a Persona (Phase 2), which is an
-- individual role within that company. Both vertical_id and
-- service_id are optional.
CREATE TABLE IF NOT EXISTS `icps` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id`       INT NOT NULL,
  `vertical_id`        INT NULL,
  `service_id`         INT NULL,
  `name`               VARCHAR(255) NOT NULL,
  `size_range`         VARCHAR(255) NULL,
  `revenue_range`      VARCHAR(255) NULL,
  `industries`         TEXT NULL,
  `geographies`        TEXT NULL,
  `tech_stack_signals` TEXT NULL,
  `trigger_events`     TEXT NULL,
  `perfect_fit`        TEXT NULL,
  `poor_fit`           TEXT NULL,
  `disqualifiers`      TEXT NULL,
  `buying_process`     TEXT NULL,
  `created_at`         DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vertical_id`) REFERENCES `verticals`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Knowledge Base expansion, Phase 6 — link Personas to Verticals/Services ──
-- See docs/KNOWLEDGE_BASE.md. Both nullable/optional; added now that
-- both target tables exist (Phase 3/4).
ALTER TABLE personas ADD COLUMN vertical_id INT NULL;
ALTER TABLE personas ADD COLUMN service_id INT NULL;
ALTER TABLE personas ADD CONSTRAINT fk_personas_vertical FOREIGN KEY (vertical_id) REFERENCES verticals(id) ON DELETE SET NULL;
ALTER TABLE personas ADD CONSTRAINT fk_personas_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL;

-- ── Knowledge Base expansion, Phase 7 — Proof Points / Case Studies ──
-- See docs/KNOWLEDGE_BASE.md. Real client outcomes, injected as social
-- proof into content when a service/vertical match exists (Phase 9).
CREATE TABLE IF NOT EXISTS `proof_points` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id`      INT NOT NULL,
  `vertical_id`       INT NULL,
  `service_id`        INT NULL,
  `client_name`       VARCHAR(255) NOT NULL,
  `client_industry`   VARCHAR(255) NULL,
  `client_size`       VARCHAR(255) NULL,
  `challenge`         TEXT NULL,
  `solution`          TEXT NULL,
  `outcomes`          TEXT NULL,
  `metrics`           VARCHAR(500) NULL,
  `quote`             TEXT NULL,
  `quote_attribution` VARCHAR(255) NULL,
  `is_public`         TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`         DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vertical_id`) REFERENCES `verticals`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Light/dark theme preference ──
-- NULL = follow the browser's prefers-color-scheme automatically; an
-- explicit 'light'/'dark' always wins over that. See includes/helpers.php
-- get_user_theme()/set_user_theme() and includes/layout_top.php.
ALTER TABLE users ADD COLUMN theme VARCHAR(10) DEFAULT NULL;

-- ── KB round 2, Phase 13 (KB Phase 8) — Documents polish ──
-- See docs/KNOWLEDGE_BASE.md. Organizational metadata only — Documents
-- already flow into AI context via extracted_text/summary regardless of
-- these fields; doc_type/use_case/vertical/service/is_public just make
-- a growing document list easier to scan and filter. Appended here
-- (not next to the CREATE TABLE above) because verticals/services must
-- exist first for the FKs below.
ALTER TABLE knowledge_documents ADD COLUMN doc_type ENUM('case_study','whitepaper','brochure','deck','one_pager','roi_calculator','video','other') NOT NULL DEFAULT 'other';
ALTER TABLE knowledge_documents ADD COLUMN use_case TEXT NULL;
ALTER TABLE knowledge_documents ADD COLUMN vertical_id INT NULL;
ALTER TABLE knowledge_documents ADD COLUMN service_id INT NULL;
ALTER TABLE knowledge_documents ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE knowledge_documents ADD CONSTRAINT fk_kb_documents_vertical FOREIGN KEY (vertical_id) REFERENCES verticals(id) ON DELETE SET NULL;
ALTER TABLE knowledge_documents ADD CONSTRAINT fk_kb_documents_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL;

-- ── Organizations, Superadmin, Plans & Module Gating ──
-- plans: no payment gateway yet — a plan is just a named bundle of
-- usage limits (NULL = unlimited) + a default set of enabled modules.
CREATE TABLE IF NOT EXISTS `plans` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `name`                VARCHAR(100) NOT NULL,
  `slug`                VARCHAR(100) NOT NULL UNIQUE,
  `max_users`           INT NULL,
  `max_workspaces`      INT NULL,
  `max_posts_per_month` INT NULL,
  -- Comma-separated subset of includes/modules.php's MODULE_KEYS.
  `default_modules`     VARCHAR(255) NOT NULL DEFAULT '',
  `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`          DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- organizations: the tenant/team unit. enabled_modules NULL = inherit
-- the assigned plan's default_modules; non-NULL = superadmin override,
-- same NULL-means-default convention as users.enabled_formats.
CREATE TABLE IF NOT EXISTS `organizations` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `name`             VARCHAR(255) NOT NULL,
  `plan_id`          INT NOT NULL,
  `enabled_modules`  VARCHAR(255) NULL,
  `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- users: every user belongs to at most one organization. organization_id
-- is nullable at the schema level (existing rows need a backfill pass —
-- see scripts/migrate_organizations.php — same nullable-then-backfilled
-- pattern already used for posts.workspace_id etc.), but the app treats
-- "every user has an org" as an invariant, with a lazy-create fallback
-- mirroring current_workspace_id()'s resilience for stale/missing state.
ALTER TABLE users
  ADD COLUMN organization_id INT NULL,
  ADD COLUMN org_role ENUM('owner','admin','member') NOT NULL DEFAULT 'owner',
  ADD COLUMN is_superadmin TINYINT(1) NOT NULL DEFAULT 0,
  ADD FOREIGN KEY (organization_id) REFERENCES organizations(id);

-- workspace_members: per-page grants — a non-owner org member's access
-- to a *specific* LinkedIn page/workspace they don't own. Ownership
-- itself (workspaces.user_id) already implies full access and needs no
-- row here.
CREATE TABLE IF NOT EXISTS `workspace_members` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id`  INT NOT NULL,
  `user_id`       INT NOT NULL,
  `granted_by`    INT NULL,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_workspace_member` (`workspace_id`, `user_id`),
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`granted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- organization_invites: token-based invite links (no email infra in
-- this app yet — the owner/admin copies and sends the link themselves).
-- workspace_ids is a comma-separated list of workspace ids to grant
-- (as workspace_members rows) on acceptance — validated in
-- includes/organizations.php that every listed workspace's owning user
-- belongs to the inviting organization.
CREATE TABLE IF NOT EXISTS `organization_invites` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `organization_id`  INT NOT NULL,
  `email`            VARCHAR(255) NOT NULL,
  `token`            VARCHAR(64) NOT NULL UNIQUE,
  `role`             ENUM('admin','member') NOT NULL DEFAULT 'member',
  `workspace_ids`    VARCHAR(255) NULL,
  `invited_by`       INT NOT NULL,
  `status`           ENUM('pending','accepted','revoked','expired') NOT NULL DEFAULT 'pending',
  `expires_at`       DATETIME NULL,
  `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
  `accepted_at`      DATETIME NULL,
  FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`invited_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Family App integration — logs each generated birthday/anniversary
-- wish image, keyed by the calling app's own reference id so a retried
-- request returns the already-rendered image instead of re-rendering.
-- Not tied to any users/workspaces row — the calling "family app" is a
-- wholly separate system with its own users; PostPilot only acts as an
-- image-generation backend for it (see api/family_wish.php).
CREATE TABLE IF NOT EXISTS `family_wish_requests` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `external_ref`    VARCHAR(191) NOT NULL UNIQUE,
  `occasion`        ENUM('birthday','anniversary') NOT NULL,
  `recipient_name`  VARCHAR(255) NOT NULL,
  `relation`        VARCHAR(100) NULL,
  `message`         TEXT NULL,
  `image_path`      VARCHAR(500) NOT NULL,
  `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Engagement (Like & Comment) — see includes/engagement.php,
-- pages/engagement.php. target_posts is an admin-curated list of
-- external LinkedIn posts a workspace's members are encouraged to
-- engage with (e.g. "Company Page's latest post"); engagement_actions
-- logs every Like/Comment fired through this app (success or failure —
-- a failed attempt still consumed part of the day's LinkedIn quota),
-- which is both the source of truth for the per-account daily
-- rate-limit guardrail and the data a future points feature will read
-- from without needing any schema change.
CREATE TABLE IF NOT EXISTS `target_posts` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `post_url`     VARCHAR(1000) NOT NULL,
  `target_urn`   VARCHAR(255) NOT NULL,
  `label`        VARCHAR(255) NULL,
  `added_by`     INT NOT NULL,
  `status`       ENUM('active','archived') NOT NULL DEFAULT 'active',
  `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`added_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_workspace_status` (`workspace_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- includes/modules.php's org_enabled_modules() falls back to
-- plans.default_modules (a DB column, not the DEFAULT_ENABLED_MODULES
-- PHP constant) for any org that already resolves a plan row. This
-- backfills the 'engagement' module into any plans row seeded before it
-- existed; FIND_IN_SET(...) = 0 also matches an empty string, so it's
-- safe to re-run and a no-op on a genuinely fresh install (plans is
-- empty until scripts/migrate_organizations.php or
-- migrations/0013_organizations_seed_and_backfill.sql seeds it).
UPDATE `plans`
SET `default_modules` = CASE WHEN `default_modules` = '' THEN 'engagement' ELSE CONCAT(`default_modules`, ',engagement') END
WHERE FIND_IN_SET('engagement', `default_modules`) = 0;

CREATE TABLE IF NOT EXISTS `engagement_actions` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id`        INT NOT NULL,
  `target_post_id`      INT NULL,
  `target_urn`          VARCHAR(255) NOT NULL,
  `user_id`             INT NOT NULL,
  `linkedin_account_id` INT NOT NULL,
  `action_type`         ENUM('like','comment') NOT NULL,
  `comment_text`        TEXT NULL,
  `li_response_status`  INT NULL,
  `li_response_id`      VARCHAR(255) NULL,
  `success`             TINYINT(1) NOT NULL DEFAULT 0,
  `error_message`       TEXT NULL,
  `created_at`          DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`target_post_id`) REFERENCES `target_posts`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`linkedin_account_id`) REFERENCES `linkedin_accounts`(`id`) ON DELETE CASCADE,
  INDEX `idx_account_created` (`linkedin_account_id`, `created_at`),
  INDEX `idx_user_created` (`user_id`, `created_at`),
  INDEX `idx_target` (`target_post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Engagement v2 — LinkedIn's socialActions (Like/Comment) endpoints
-- require Community Management API partner approval, not covered by
-- the self-serve w_member_social scope this app has, so Like/Comment
-- became a "redirect to LinkedIn + self-report on click" flow instead
-- (unverifiable). Repost is different: it's just creating a post with
-- reshareContext set — the same already-working Posts API — so it
-- stays a real, verified, in-app action. 'verification' records which
-- kind a given row is.
ALTER TABLE `engagement_actions`
  MODIFY COLUMN `action_type` ENUM('like','comment','repost') NOT NULL,
  ADD COLUMN `verification` ENUM('self_reported','api') NOT NULL DEFAULT 'self_reported' AFTER `action_type`;

-- Per-Content-Pillar Grav routing — see includes/grav_api.php,
-- pages/blog_studio.php, pages/knowledge.php. Lets each pillar send its
-- Grav posts to a different route prefix (and optionally a different
-- Grav page template) instead of every post in a workspace landing
-- under one shared prefix. NULL on either column falls back to the
-- workspace's own grav_route_prefix/grav_template, same "NULL = no
-- override" convention as content_pillars.default_layout/default_palette.
-- blog_posts never had a content_pillar_id at all (unlike the LinkedIn
-- posts table) — adding it here is what lets a blog post be tagged with
-- a pillar in the first place.
ALTER TABLE `content_pillars`
  ADD COLUMN `grav_route_prefix` VARCHAR(255) NULL,
  ADD COLUMN `grav_template` VARCHAR(100) NULL;

ALTER TABLE `blog_posts`
  ADD COLUMN `content_pillar_id` INT NULL AFTER `workspace_id`,
  ADD CONSTRAINT `fk_blog_posts_content_pillar` FOREIGN KEY (`content_pillar_id`) REFERENCES `content_pillars`(`id`) ON DELETE SET NULL;

-- Per-workspace news source region + RSS snippet capture — see
-- includes/news_fetch.php, includes/blog_generate.php,
-- pages/news_studio.php, pages/settings.php.
ALTER TABLE `workspaces`
  ADD COLUMN `news_region` VARCHAR(10) NULL;

ALTER TABLE `news_items`
  ADD COLUMN `description` TEXT NULL;

-- Content types, Grav unpublish/delete, sitemap-based internal linking —
-- see includes/blog_generate.php, includes/grav_api.php,
-- includes/sitemap.php, pages/blog_studio.php, pages/settings.php.
ALTER TABLE `blog_posts`
  ADD COLUMN `content_type` VARCHAR(30) NULL;

ALTER TABLE `blog_posts`
  MODIFY COLUMN `status` ENUM('draft','scheduled','published','unpublished','failed') NOT NULL DEFAULT 'draft';

ALTER TABLE `workspaces`
  ADD COLUMN `sitemap_url` VARCHAR(1000) NULL;

CREATE TABLE IF NOT EXISTS `sitemap_links` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `url` VARCHAR(1000) NOT NULL,
  `title` VARCHAR(500) NULL,
  `category` VARCHAR(100) NULL,
  `fetched_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uniq_workspace_url` (`workspace_id`, `url`(255)),
  INDEX `idx_workspace_category` (`workspace_id`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Content Collections — group related LinkedIn posts + blog posts
-- together (e.g. a product launch, a themed content week) so they can
-- be viewed/managed as one set from Knowledge Hub. Deliberately NOT
-- named "campaign" (posts.campaign_id is an unrelated, pre-existing
-- per-post unique slug used as the upload folder name) or "series"
-- (creative_json.series_label is an unrelated per-slide cosmetic
-- eyebrow-text field) — "Collection" avoids colliding with either.
-- Same user_id/workspace_id-nullable scoping convention as
-- content_pillars: a NULL workspace_id row stays visible in every
-- workspace (see includes/collections.php).
CREATE TABLE IF NOT EXISTS content_collections (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  workspace_id INT NULL,
  name         VARCHAR(255) NOT NULL,
  description  TEXT NULL,
  status       ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_ws_collection (user_id, workspace_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE posts
  ADD COLUMN collection_id INT NULL AFTER content_pillar_id,
  ADD CONSTRAINT fk_posts_collection FOREIGN KEY (collection_id) REFERENCES content_collections(id) ON DELETE SET NULL;

ALTER TABLE blog_posts
  ADD COLUMN collection_id INT NULL AFTER content_pillar_id,
  ADD CONSTRAINT fk_blog_posts_collection FOREIGN KEY (collection_id) REFERENCES content_collections(id) ON DELETE SET NULL;

-- Stock photo search (New Post's "Stock/AI Photo" panel) — Unsplash
-- Access Key, same bring-your-own-key pattern as users.reddit_client_id/
-- reddit_client_secret. AI-generated (non-branded) photos reuse the
-- existing per-user Gemini/Claude/OpenAI key already stored for text
-- generation — no separate credential needed for that path.
ALTER TABLE users ADD COLUMN unsplash_access_key VARCHAR(255) NULL;

-- Link Tracking (Knowledge Hub > new tab) — a short redirect link
-- (APP_URL/go?s={slug}) for any destination URL, so a link pasted into
-- a LinkedIn caption or blog post can have its clicks counted. LinkedIn
-- gives no read-back engagement data via API (the recurring blocker
-- this app has hit for Like/Comment and post analytics alike), so this
-- is the one form of "did people click through" this app can actually
-- measure on its own — see go.php (public, unauthenticated redirect)
-- and includes/link_tracking.php.
CREATE TABLE IF NOT EXISTS tracked_links (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id    INT NULL,
  user_id         INT NOT NULL,
  label           VARCHAR(255) NULL,
  target_url      VARCHAR(1000) NOT NULL,
  slug            VARCHAR(20) NOT NULL,
  click_count     INT NOT NULL DEFAULT 0,
  last_clicked_at DATETIME NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_slug (slug),
  INDEX idx_workspace (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
