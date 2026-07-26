-- KB expansion Phase 7 — Proof Points / Case Studies. See docs/KNOWLEDGE_BASE.md.
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
