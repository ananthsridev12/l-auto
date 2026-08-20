-- Content Collections — group related LinkedIn posts + blog posts
-- together (e.g. a product launch, a themed content week) so they can
-- be viewed/managed as one set from Knowledge Hub. Deliberately NOT
-- named "campaign" (posts.campaign_id is an unrelated, pre-existing
-- per-post unique slug used as the upload folder name) or "series"
-- (creative_json.series_label is an unrelated per-slide cosmetic
-- eyebrow-text field) — "Collection" avoids colliding with either.
-- Same user_id/workspace_id-nullable scoping convention as
-- content_pillars: a NULL workspace_id row stays visible in every
-- workspace (see includes/collections.php).
CREATE TABLE IF NOT EXISTS content_collections (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  workspace_id INT NULL,
  name         VARCHAR(255) NOT NULL,
  description  TEXT NULL,
  status       ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_ws_collection (user_id, workspace_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE posts
  ADD COLUMN collection_id INT NULL AFTER content_pillar_id,
  ADD CONSTRAINT fk_posts_collection FOREIGN KEY (collection_id) REFERENCES content_collections(id) ON DELETE SET NULL;

ALTER TABLE blog_posts
  ADD COLUMN collection_id INT NULL AFTER content_pillar_id,
  ADD CONSTRAINT fk_blog_posts_collection FOREIGN KEY (collection_id) REFERENCES content_collections(id) ON DELETE SET NULL;

-- Stock photo search (New Post's "Stock/AI Photo" panel) — Unsplash
-- Access Key, same bring-your-own-key pattern as users.reddit_client_id/
-- reddit_client_secret. AI-generated (non-branded) photos reuse the
-- existing per-user Gemini/Claude/OpenAI key already stored for text
-- generation — no separate credential needed for that path.
ALTER TABLE users ADD COLUMN unsplash_access_key VARCHAR(255) NULL;
