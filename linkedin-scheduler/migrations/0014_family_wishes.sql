-- Family App integration — logs each generated birthday/anniversary
-- wish image, keyed by the calling app's own reference id so a retried
-- request returns the already-rendered image instead of re-rendering.
-- Not tied to any users/workspaces row — the calling "family app" is a
-- wholly separate system with its own users; PostPilot only acts as an
-- image-generation backend for it (see api/family_wish.php).
CREATE TABLE IF NOT EXISTS `family_wish_requests` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `external_ref`    VARCHAR(191) NOT NULL UNIQUE,
  `occasion`        ENUM('birthday','anniversary') NOT NULL,
  `recipient_name`  VARCHAR(255) NOT NULL,
  `relation`        VARCHAR(100) NULL,
  `message`         TEXT NULL,
  `image_path`      VARCHAR(500) NOT NULL,
  `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
