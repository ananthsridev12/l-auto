-- Per-Content-Pillar defaults for auto-generated blog posts — so the
-- news_auto_blog_enabled cron (see cron/news_daily.php,
-- includes/news_fetch.php news_generate_blog_draft()) can write each
-- pillar's content the way it's actually supposed to be written,
-- instead of one fixed generic default for every pillar. Same
-- NULL-means-no-override convention as content_pillars.default_layout/
-- default_palette above — falls back to this app's existing global
-- defaults (BLOG_CONTENT_TYPE_DEFAULT, BLOG_LENGTH_DEFAULT,
-- BLOG_MODE_ORIGINAL, no citation, full brand context) when unset.
-- Also used by the manual "Write Blog Post" button in News Studio
-- going forward as this pillar's baseline, same as default_layout/
-- default_palette already are for images.
--
-- grav_category is a separate concept from content_pillars.category
-- (company/personal voice) — this is the Grav taxonomy value (see
-- migrations/0025_grav_taxonomy.sql), letting a pillar like "Company
-- Announcements" or "Industry Commentary" carry its own fixed News
-- category so an auto-generated post is tagged correctly without a
-- human picking it after the fact. blog_posts.grav_category (set at
-- creation from this) can still be edited per-post afterward.
ALTER TABLE content_pillars
  ADD COLUMN blog_content_type VARCHAR(50) DEFAULT NULL,
  ADD COLUMN blog_length VARCHAR(20) DEFAULT NULL,
  ADD COLUMN blog_mode VARCHAR(20) DEFAULT NULL,
  ADD COLUMN blog_cite_source TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN blog_fresh_context TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN grav_category VARCHAR(100) DEFAULT NULL;
