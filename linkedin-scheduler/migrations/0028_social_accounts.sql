-- Multi-platform posting: Facebook Pages, Instagram Business, Pinterest,
-- Google Business Profile. Purely additive — linkedin_accounts and every
-- existing posts row are untouched (platform defaults to 'linkedin',
-- social_account_id stays NULL, so today's LinkedIn-only behavior does
-- not change). New-platform posts leave linkedin_account_id NULL and use
-- platform + social_account_id instead. See includes/social_publish.php,
-- includes/facebook_api.php, includes/instagram_api.php.
CREATE TABLE IF NOT EXISTS social_accounts (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  platform       ENUM('facebook','instagram','pinterest','google_business') NOT NULL,
  external_id    VARCHAR(255) NOT NULL,
  display_name   VARCHAR(255) NOT NULL,
  access_token   TEXT NOT NULL,
  refresh_token  TEXT,
  expires_at     DATETIME,
  scopes         VARCHAR(255),
  meta_json      TEXT,
  status         ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_platform_target (user_id, platform, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE posts
  ADD COLUMN platform ENUM('linkedin','facebook','instagram','pinterest','google_business') NOT NULL DEFAULT 'linkedin' AFTER linkedin_account_id,
  ADD COLUMN social_account_id INT NULL AFTER platform,
  ADD FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE SET NULL;
