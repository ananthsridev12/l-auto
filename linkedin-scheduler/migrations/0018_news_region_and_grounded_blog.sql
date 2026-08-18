-- news_region: per-workspace override of the global NEWS_FEED_LANG/
-- NEWS_FEED_COUNTRY constants (includes/news_fetch.php) — NULL keeps
-- the existing global-constant behavior for every workspace that
-- hasn't picked one. Values match NEWS_REGION_PRESETS keys (e.g. 'IN',
-- 'US', 'GB').
ALTER TABLE `workspaces`
  ADD COLUMN `news_region` VARCHAR(10) NULL;

-- description: the RSS item's short snippet (Google News/direct feed
-- <description>, HTML-stripped and truncated — never full article
-- text). Powers "Write Blog Post"'s Grounded Rewrite mode, which
-- extracts factual values from this snippet rather than reacting to
-- the headline alone. NULL when a feed provided none (Reddit items,
-- or a publisher feed with no description tag) — that item simply
-- can't use Grounded Rewrite mode.
ALTER TABLE `news_items`
  ADD COLUMN `description` TEXT NULL;
