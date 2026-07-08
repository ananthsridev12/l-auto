-- LinkedIn Scheduler — database schema
-- Target: MySQL 5.7+/8.0 (shared hosting / cPanel), InnoDB, utf8mb4

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name          VARCHAR(255),
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Connected LinkedIn accounts: personal profile OR company page.
-- Multiple rows per user_id are allowed.
CREATE TABLE IF NOT EXISTS linkedin_accounts (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  account_type   ENUM('personal','company') NOT NULL,
  target_urn     VARCHAR(255) NOT NULL,
  display_name   VARCHAR(255) NOT NULL,
  linkedin_name  VARCHAR(255),
  access_token   TEXT NOT NULL,
  refresh_token  TEXT,
  expires_at     DATETIME,
  scopes         VARCHAR(255),
  status         ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_target (user_id, target_urn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tracks each CSV+ZIP bulk import run.
CREATE TABLE IF NOT EXISTS import_batches (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  user_id                  INT NOT NULL,
  csv_filename             VARCHAR(255),
  zip_filename             VARCHAR(255),
  row_count                INT DEFAULT 0,
  imported_count           INT DEFAULT 0,
  skipped_count            INT DEFAULT 0,
  unmatched_account_count  INT DEFAULT 0,
  already_posted_count     INT DEFAULT 0,
  created_at               DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS posts (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  user_id             INT NOT NULL,
  linkedin_account_id INT NULL,
  import_batch_id     INT NULL,
  campaign_id         VARCHAR(50),
  title               VARCHAR(500),
  format              ENUM('Single Image','Carousel','Text Post','Poll') NOT NULL,
  caption             TEXT,
  source_page_label   VARCHAR(255),
  scheduled_at        DATETIME NULL,
  posted_at           DATETIME NULL,
  status              ENUM('draft','scheduled','posted','failed') DEFAULT 'draft',
  li_post_urn         VARCHAR(255),
  error_message       TEXT,
  created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (linkedin_account_id) REFERENCES linkedin_accounts(id) ON DELETE SET NULL,
  FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_user_campaign (user_id, campaign_id),
  INDEX idx_scheduled (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS post_slides (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  post_id     INT NOT NULL,
  slide_order INT NOT NULL,
  filename    VARCHAR(255) NOT NULL,
  filepath    VARCHAR(500) NOT NULL,
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_post_order (post_id, slide_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
