-- Engagement (Like & Comment) — see includes/engagement.php, pages/engagement.php.
-- target_posts: an admin-curated list of external LinkedIn posts a
-- workspace's members are encouraged to engage with (e.g. "Company
-- Page's latest post"). engagement_actions: a log of every Like/Comment
-- fired through this app (success or failure), the data source for a
-- future points feature and the source-of-truth for the per-account
-- daily rate-limit guardrail.

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
-- PHP constant) for any org that already resolves a plan row — which
-- every org does, post-Organizations-migration. default_modules was
-- only ever written once, when 0012/0013 introduced it; adding
-- 'engagement' to includes/modules.php's arrays alone would silently
-- hide it from every existing plan/org until this backfill runs.
-- FIND_IN_SET(...) = 0 also matches an empty string, so this is safe to
-- re-run.
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
