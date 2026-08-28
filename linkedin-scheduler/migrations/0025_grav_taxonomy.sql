-- Grav taxonomy fields — per the site's own taxonomy reference doc:
-- taxonomy.category (News: fixed Company/Product/Industry; Blog: free
-- label), taxonomy.service (Blog/Portfolio/Case Study — the target
-- service page's exact URL slug), and industry (Portfolio/Case Study
-- only — a PLAIN header field, not nested under taxonomy). See
-- includes/grav_api.php grav_publish_post(), pages/blog_studio.php.
-- All nullable/optional — a post with none set publishes exactly as
-- before this migration.
ALTER TABLE blog_posts ADD COLUMN grav_category VARCHAR(100) NULL;
ALTER TABLE blog_posts ADD COLUMN grav_service VARCHAR(150) NULL;
ALTER TABLE blog_posts ADD COLUMN grav_industry VARCHAR(150) NULL;
