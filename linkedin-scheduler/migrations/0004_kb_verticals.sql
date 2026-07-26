-- KB expansion Phase 3 — Verticals. See docs/KNOWLEDGE_BASE.md.
CREATE TABLE IF NOT EXISTS `verticals` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id`    INT NOT NULL,
  `name`            VARCHAR(255) NOT NULL,
  `focus`           TEXT NULL,
  `industries`      TEXT NULL,
  `priority`        ENUM('core','growth','emerging') NOT NULL DEFAULT 'core',
  `differentiators` TEXT NULL,
  `head_name`       VARCHAR(255) NULL,
  `positioning`     TEXT NULL,
  `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
