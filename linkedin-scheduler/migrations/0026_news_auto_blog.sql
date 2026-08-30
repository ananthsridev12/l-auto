-- Auto-generate a Blog Studio draft from fresh news headlines, same
-- daily-cron pattern as the existing LinkedIn auto-drafting
-- (news_auto_enabled/news_drafts_per_day) but a separate toggle/cap —
-- a workspace can run one, both, or neither. Always lands as a Draft
-- for review; the cron never schedules or publishes it — that stays a
-- manual step in Blog Studio (from where it flows into the existing
-- Grav auto-publish cron once scheduled). See
-- includes/news_fetch.php news_generate_blog_draft(),
-- cron/news_daily.php, pages/settings.php's News Auto-Content section.
ALTER TABLE workspaces ADD COLUMN news_auto_blog_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE workspaces ADD COLUMN news_blog_drafts_per_day INT NOT NULL DEFAULT 1;
