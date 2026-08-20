-- Link Tracking (Knowledge Hub > new tab) — a short redirect link
-- (APP_URL/go?s={slug}) for any destination URL, so a link pasted into
-- a LinkedIn caption or blog post can have its clicks counted. LinkedIn
-- gives no read-back engagement data via API (the recurring blocker
-- this app has hit for Like/Comment and post analytics alike), so this
-- is the one form of "did people click through" this app can actually
-- measure on its own — see go.php (public, unauthenticated redirect)
-- and includes/link_tracking.php.
CREATE TABLE IF NOT EXISTS tracked_links (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id    INT NULL,
  user_id         INT NOT NULL,
  label           VARCHAR(255) NULL,
  target_url      VARCHAR(1000) NOT NULL,
  slug            VARCHAR(20) NOT NULL,
  click_count     INT NOT NULL DEFAULT 0,
  last_clicked_at DATETIME NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_slug (slug),
  INDEX idx_workspace (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
