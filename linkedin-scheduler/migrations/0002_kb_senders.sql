-- KB expansion Phase 1 — Senders. See docs/KNOWLEDGE_BASE.md.
CREATE TABLE IF NOT EXISTS `senders` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id`      INT NOT NULL,
  `full_name`         VARCHAR(255) NOT NULL,
  `title`             VARCHAR(255) NULL,
  `linkedin_headline` VARCHAR(300) NULL,
  `linkedin_about`    TEXT NULL,
  `background`        TEXT NULL,
  `credibility`       TEXT NULL,
  `years_experience`  INT NULL,
  `individual_tone`   TEXT NULL,
  `example_posts`     TEXT NULL,
  `post_topics`       TEXT NULL,
  `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
