-- Remote MCP server (Claude/ChatGPT connect via OAuth, then call tools
-- over JSON-RPC at /mcp) — purely additive, no change to any existing
-- table. See includes/oauth_server.php, includes/mcp_server.php,
-- includes/mcp_tools.php.
CREATE TABLE IF NOT EXISTS mcp_oauth_clients (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  client_id      CHAR(32) NOT NULL,
  client_name    VARCHAR(100) NULL,
  redirect_uris  TEXT NOT NULL,
  auth_method    VARCHAR(30) NOT NULL DEFAULT 'none',
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_client_id (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- code_hash is single-use: looked up and deleted in the same step when
-- exchanged for a token (includes/oauth_server.php oauth_issue_token()).
CREATE TABLE IF NOT EXISTS mcp_oauth_codes (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  code_hash       CHAR(64) NOT NULL,
  client_id       CHAR(32) NOT NULL,
  user_id         INT NOT NULL,
  redirect_uri    VARCHAR(500) NOT NULL,
  code_challenge  VARCHAR(128) NOT NULL,
  expires_at      DATETIME NOT NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_code_hash (code_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bearer tokens handed to Claude/ChatGPT after the OAuth flow. Stored
-- hashed (sha256) — the raw token is only ever shown once, at issuance,
-- and compared (never redisplayed) on every /mcp call.
CREATE TABLE IF NOT EXISTS mcp_tokens (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  token_hash    CHAR(64) NOT NULL,
  client_name   VARCHAR(100) NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_used_at  DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
