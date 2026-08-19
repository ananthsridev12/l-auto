-- content_type: which structural template (see includes/blog_generate.php
-- BLOG_CONTENT_TYPES) generated this post. NULL/empty is treated as the
-- default 'analysis' type by every reader, so existing rows need no
-- backfill.
ALTER TABLE `blog_posts`
  ADD COLUMN `content_type` VARCHAR(30) NULL;

-- 'unpublished': a Grav page that was soft-removed (header.published =
-- false via grav_set_published(), includes/grav_api.php) — the page
-- still exists on the Grav site, just hidden from the live site, and
-- external_post_id/external_url are kept so re-publishing targets the
-- same page rather than creating a duplicate.
ALTER TABLE `blog_posts`
  MODIFY COLUMN `status` ENUM('draft','scheduled','published','unpublished','failed') NOT NULL DEFAULT 'draft';

-- Sitemap-based internal linking (see includes/sitemap.php,
-- pages/settings.php) — a workspace-level sitemap.xml URL, manually
-- fetched (no cron), parsed into individual page links the AI can cite
-- for internal linking on top of this app's own published blog posts.
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
