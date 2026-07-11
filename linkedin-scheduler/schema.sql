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
