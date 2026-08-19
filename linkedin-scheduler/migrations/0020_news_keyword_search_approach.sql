-- News Studio keyword "search approach" (Settings > News Auto-Content):
-- SEO = today's behavior unchanged, the literal keyword is searched
-- against Google News as-is. AI Mode = mirrors how Google's own "AI
-- Mode" surfaces broader/related topics instead of exact keyword
-- matches — the configured AI provider (includes/ai_generate.php)
-- expands the keyword into several natural-language related queries
-- once (stored here as JSON), and news_build_queries()
-- (includes/news_fetch.php) fans those out as extra Google News
-- searches alongside the literal keyword. Only meaningful for
-- source_type='auto' (Reddit/direct-feed topics keep 'seo' — there's
-- nothing to expand for a subreddit name or a feed URL).
ALTER TABLE `news_topics`
  ADD COLUMN `search_approach` ENUM('seo','ai_mode') NOT NULL DEFAULT 'seo' AFTER `source_type`,
  ADD COLUMN `ai_expanded_queries` TEXT NULL AFTER `search_approach`;
