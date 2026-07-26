-- KB expansion Phase 5 — ICPs (Ideal Customer Profiles). See docs/KNOWLEDGE_BASE.md.
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
