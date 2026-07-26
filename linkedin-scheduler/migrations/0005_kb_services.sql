-- KB expansion Phase 4 — Services. See docs/KNOWLEDGE_BASE.md.
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
