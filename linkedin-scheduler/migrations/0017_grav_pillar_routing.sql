-- Per-Content-Pillar Grav routing — see includes/grav_api.php,
-- pages/blog_studio.php, pages/knowledge.php. Lets each pillar send its
-- Grav posts to a different route prefix (and optionally a different
-- Grav page template) instead of every post in a workspace landing
-- under one shared prefix — e.g. a "Product Updates" pillar could route
-- to /blog/product/ while "Industry News" goes to /blog/news/. NULL on
-- either column falls back to the workspace's own grav_route_prefix/
-- grav_template, same "NULL = no override" convention as
-- content_pillars.default_layout/default_palette.
--
-- blog_posts never had a content_pillar_id at all (unlike the LinkedIn
-- posts table) — adding it here is what lets a blog post be tagged with
-- a pillar in the first place, which the routing above depends on.

ALTER TABLE `content_pillars`
  ADD COLUMN `grav_route_prefix` VARCHAR(255) NULL,
  ADD COLUMN `grav_template` VARCHAR(100) NULL;

ALTER TABLE `blog_posts`
  ADD COLUMN `content_pillar_id` INT NULL AFTER `workspace_id`,
  ADD CONSTRAINT `fk_blog_posts_content_pillar` FOREIGN KEY (`content_pillar_id`) REFERENCES `content_pillars`(`id`) ON DELETE SET NULL;
