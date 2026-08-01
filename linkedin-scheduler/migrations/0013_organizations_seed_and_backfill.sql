-- SQL-only equivalent of `php scripts/migrate_organizations.php`, for
-- deployments where only a DB console (phpMyAdmin/Adminer) is available,
-- not shell/CLI access. Run this AFTER 0012_organizations.sql.
--
-- Idempotent: seeds the 3 starter plans only if their slug doesn't
-- already exist, and only touches users with organization_id IS NULL —
-- safe to run more than once.
--
-- 1. Seed the 3 starter plans (Free/Pro/Agency) — placeholder limits, no
--    payment gateway wired up yet (see includes/organizations.php
--    org_within_limit()). All modules enabled by default; a superadmin
--    trims per-organization from pages/admin.php.
INSERT INTO plans (name, slug, max_users, max_workspaces, max_posts_per_month, default_modules)
SELECT * FROM (SELECT
    'Free' AS name, 'free' AS slug, 1 AS max_users, 2 AS max_workspaces, 30 AS max_posts_per_month,
    'post_scheduling,ai_generation,content_studio,blog_studio,news_studio' AS default_modules
  UNION ALL SELECT
    'Pro', 'pro', 10, 10, 200,
    'post_scheduling,ai_generation,content_studio,blog_studio,news_studio'
  UNION ALL SELECT
    'Agency', 'agency', NULL, NULL, NULL,
    'post_scheduling,ai_generation,content_studio,blog_studio,news_studio'
) AS seed
WHERE seed.slug NOT IN (SELECT slug FROM plans);

-- 2. For every existing user with no organization_id: create a 1-person
--    organization on the Free plan and assign it (org_role stays the
--    column default 'owner') — mirrors scripts/migrate_organizations.php's
--    per-user backfill loop.
--
--    Pure-SQL substitute for a per-row PHP loop: INSERT ... SELECT ...
--    ORDER BY id gives the new `organizations` rows sequential ids in
--    the same relative order as the `users` rows were read (safe for a
--    one-time offline migration with no concurrent writes), so a
--    session-variable running counter in the matching UPDATE ... ORDER
--    BY id can pair each user back up with the organization created for
--    them, without needing a stored procedure/cursor.
SET @free_plan_id := (SELECT id FROM plans WHERE slug = 'free' LIMIT 1);
SET @start_org_id := (
  SELECT AUTO_INCREMENT FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations'
);

INSERT INTO organizations (name, plan_id)
SELECT COALESCE(NULLIF(TRIM(name), ''), email), @free_plan_id
FROM users WHERE organization_id IS NULL ORDER BY id;

SET @rownum := @start_org_id - 1;
UPDATE users
SET organization_id = (@rownum := @rownum + 1)
WHERE organization_id IS NULL
ORDER BY id;
