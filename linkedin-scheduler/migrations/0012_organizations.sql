-- Organizations, Superadmin, Plans & Module Gating — Phase 1 schema.
-- See docs/ (or the session that shipped this) for the full design.
--
-- plans: no payment gateway yet — a plan is just a named bundle of
-- usage limits (NULL = unlimited) + a default set of enabled modules.
CREATE TABLE IF NOT EXISTS `plans` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `name`                VARCHAR(100) NOT NULL,
  `slug`                VARCHAR(100) NOT NULL UNIQUE,
  `max_users`           INT NULL,
  `max_workspaces`      INT NULL,
  `max_posts_per_month` INT NULL,
  -- Comma-separated subset of includes/modules.php's MODULE_KEYS.
  `default_modules`     VARCHAR(255) NOT NULL DEFAULT '',
  `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`          DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- organizations: the tenant/team unit. enabled_modules NULL = inherit
-- the assigned plan's default_modules; non-NULL = superadmin override,
-- same NULL-means-default convention as users.enabled_formats.
CREATE TABLE IF NOT EXISTS `organizations` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `name`             VARCHAR(255) NOT NULL,
  `plan_id`          INT NOT NULL,
  `enabled_modules`  VARCHAR(255) NULL,
  `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- users: every user belongs to at most one organization. organization_id
-- is nullable at the schema level (existing rows need a backfill pass —
-- see scripts/migrate_organizations.php — same nullable-then-backfilled
-- pattern already used for posts.workspace_id etc.), but the app treats
-- "every user has an org" as an invariant, with a lazy-create fallback
-- mirroring current_workspace_id()'s resilience for stale/missing state.
ALTER TABLE users
  ADD COLUMN organization_id INT NULL,
  ADD COLUMN org_role ENUM('owner','admin','member') NOT NULL DEFAULT 'owner',
  ADD COLUMN is_superadmin TINYINT(1) NOT NULL DEFAULT 0,
  ADD FOREIGN KEY (organization_id) REFERENCES organizations(id);

-- workspace_members: per-page grants — a non-owner org member's access
-- to a *specific* LinkedIn page/workspace they don't own. Ownership
-- itself (workspaces.user_id) already implies full access and needs no
-- row here.
CREATE TABLE IF NOT EXISTS `workspace_members` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id`  INT NOT NULL,
  `user_id`       INT NOT NULL,
  `granted_by`    INT NULL,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_workspace_member` (`workspace_id`, `user_id`),
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`granted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- organization_invites: token-based invite links (no email infra in
-- this app yet — the owner/admin copies and sends the link themselves).
-- workspace_ids is a comma-separated list of workspace ids to grant
-- (as workspace_members rows) on acceptance — validated in
-- includes/organizations.php that every listed workspace's owning user
-- belongs to the inviting organization.
CREATE TABLE IF NOT EXISTS `organization_invites` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `organization_id`  INT NOT NULL,
  `email`            VARCHAR(255) NOT NULL,
  `token`            VARCHAR(64) NOT NULL UNIQUE,
  `role`             ENUM('admin','member') NOT NULL DEFAULT 'member',
  `workspace_ids`    VARCHAR(255) NULL,
  `invited_by`       INT NOT NULL,
  `status`           ENUM('pending','accepted','revoked','expired') NOT NULL DEFAULT 'pending',
  `expires_at`       DATETIME NULL,
  `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
  `accepted_at`      DATETIME NULL,
  FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`invited_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
