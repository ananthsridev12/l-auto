-- Engagement v2 — see includes/engagement.php for the full design
-- rationale. LinkedIn's socialActions (Like/Comment) endpoints turned
-- out to require Community Management API partner approval, not
-- covered by the self-serve w_member_social scope this app already
-- has — so Like/Comment became a "redirect to LinkedIn + self-report on
-- click" flow instead (unverifiable, honor-system). Repost is different:
-- it's just creating a post with reshareContext set, the exact same
-- Posts API this app already uses for scheduled publishing, so it stays
-- a real, verified, in-app action with no redirect needed.
--
-- 'verification' records which kind a given row is, so a future points
-- feature (or an admin auditing engagement_actions) can tell a real
-- API-confirmed action from a trusted-but-unverifiable click apart,
-- rather than treating every row as equally certain.

ALTER TABLE `engagement_actions`
  MODIFY COLUMN `action_type` ENUM('like','comment','repost') NOT NULL,
  ADD COLUMN `verification` ENUM('self_reported','api') NOT NULL DEFAULT 'self_reported' AFTER `action_type`;
