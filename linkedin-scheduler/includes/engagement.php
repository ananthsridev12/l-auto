<?php
// Engagement (Like, Comment & Repost) — an admin-curated list of
// external LinkedIn posts a workspace's members are encouraged to
// engage with. Three different mechanisms, because LinkedIn's actual
// API access turned out narrower than first assumed:
//
// - Like/Comment: LinkedIn's Social Actions API (the only way to
//   actually like/comment as a member via API) requires Community
//   Management API partner approval — confirmed via a live 403
//   ACCESS_DENIED (partnerApiSocialActions.CREATE) — which this app
//   doesn't have and can't get self-serve. So instead, clicking Like/
//   Comment opens the real post on LinkedIn in a new tab AND
//   immediately logs the action as done. This is an honor-system,
//   self-reported record, not a verified one — if the member doesn't
//   actually follow through on LinkedIn, it's still counted. That's a
//   deliberate, accepted tradeoff (see engagement_actions.verification),
//   not an oversight.
// - Repost: just creating a post with reshareContext set — the same
//   Posts API (w_member_social) already used for scheduled publishing —
//   so it's a real, verified, in-app action with no redirect and no
//   extra approval needed.
//
// Either way, every action is logged to engagement_actions the moment
// it happens — the full engagement record for anything done through
// this app, and the data source a future points feature will read
// from. r_member_social (LinkedIn's OWN "who engaged" read permission)
// isn't self-serve either, which is the whole reason this app logs its
// own actions rather than asking LinkedIn who did what.
//
// Requires includes/organizations.php (user_can_access_workspace()) and
// includes/linkedin_api.php (li_embed_url()/li_create_repost()) to
// already be loaded — same no-self-require convention as
// includes/post_helpers.php.

// Accepts a raw urn:li:{activity|share|ugcPost}:{id}, LinkedIn's "Copy
// link to post" feed permalink
// (https://www.linkedin.com/feed/update/urn:li:activity:.../), or the
// public post URL LinkedIn's own UI links to
// (https://www.linkedin.com/posts/{slug}-activity-{id}-{suffix}/).
// Returns null if none of these patterns match — the caller shows a
// friendly "paste the full post URL" error rather than guessing.
function li_parse_post_urn(string $input): ?string
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }
    if (preg_match('#urn:li:(activity|share|ugcPost):(\d+)#', $input, $m)) {
        return "urn:li:{$m[1]}:{$m[2]}";
    }
    if (preg_match('#-activity-(\d+)(-|$)#', $input, $m)) {
        return "urn:li:activity:{$m[1]}";
    }
    return null;
}

function fetch_target_posts(int $workspaceId, bool $includeArchived = false): array
{
    $sql = 'SELECT * FROM target_posts WHERE workspace_id = ?' . ($includeArchived ? '' : " AND status = 'active'") . ' ORDER BY created_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute([$workspaceId]);
    return $stmt->fetchAll();
}

// Same owns-OR-granted read authorization every other workspace-scoped
// row uses (fetch_post()/fetch_persona() pattern in post_helpers.php).
function fetch_target_post(int $id, int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM target_posts WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (!user_can_access_workspace($userId, (int) $row['workspace_id'])) {
        return null;
    }
    return $row;
}

// Curation gate: the workspace's own owner, OR an owner/admin of the
// organization. There's no separate "workspace admin" role in this
// app's data model (workspace_members is a flat grant, no role column)
// — org_role is the only existing admin/owner concept, reused as-is
// rather than introducing a new role just for this feature.
function user_can_manage_target_posts(int $userId, int $workspaceId): bool
{
    $stmt = db()->prepare('SELECT user_id FROM workspaces WHERE id = ?');
    $stmt->execute([$workspaceId]);
    $ownerId = $stmt->fetchColumn();
    if ($ownerId !== false && (int) $ownerId === $userId) {
        return true;
    }
    if (!user_can_access_workspace($userId, $workspaceId)) {
        return false;
    }
    $stmt = db()->prepare('SELECT org_role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return in_array($stmt->fetchColumn(), ['owner', 'admin'], true);
}

// $rawUrl is whatever the admin pasted (a full post URL or a bare
// urn:li:...). Returns [success, error, id].
function add_target_post(int $workspaceId, int $userId, string $rawUrl, ?string $label): array
{
    $urn = li_parse_post_urn($rawUrl);
    if (!$urn) {
        return [false, "Couldn't find a LinkedIn post ID in that link. Paste the full post URL (e.g. linkedin.com/posts/... or linkedin.com/feed/update/urn:li:activity:...) or the raw urn:li:activity:... value.", null];
    }
    $dupe = db()->prepare("SELECT id FROM target_posts WHERE workspace_id = ? AND target_urn = ? AND status = 'active'");
    $dupe->execute([$workspaceId, $urn]);
    if ($dupe->fetchColumn()) {
        return [false, 'This post is already on your list.', null];
    }
    $stmt = db()->prepare(
        'INSERT INTO target_posts (workspace_id, post_url, target_urn, label, added_by) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$workspaceId, trim($rawUrl), $urn, trim((string) $label) !== '' ? trim($label) : null, $userId]);
    return [true, null, (int) db()->lastInsertId()];
}

function archive_target_post(int $id, int $workspaceId): void
{
    db()->prepare("UPDATE target_posts SET status = 'archived' WHERE id = ? AND workspace_id = ?")->execute([$id, $workspaceId]);
}

function unarchive_target_post(int $id, int $workspaceId): void
{
    db()->prepare("UPDATE target_posts SET status = 'active' WHERE id = ? AND workspace_id = ?")->execute([$id, $workspaceId]);
}

// Anti-abuse daily cap. Originally sized to stay under LinkedIn's
// ~100-calls/day/member API limit, but Like/Comment no longer call
// LinkedIn's API at all (self-reported — see the file-level comment),
// so that reasoning no longer applies to 2 of the 3 action types.
// Repurposed as a plain "don't let one account rack up an implausible
// number of self-reported actions in a day" guardrail instead — a
// curated list is small by design, so genuine daily engagement volume
// is nowhere near this; it exists to blunt spam-clicking, not to model
// real usage.
const ENGAGEMENT_DAILY_CAP = 30;

function engagement_actions_today_count(int $accountId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM engagement_actions WHERE linkedin_account_id = ? AND created_at >= CURDATE()'
    );
    $stmt->execute([$accountId]);
    return (int) $stmt->fetchColumn();
}

function engagement_actions_remaining_today(int $accountId): int
{
    return max(0, ENGAGEMENT_DAILY_CAP - engagement_actions_today_count($accountId));
}

// Used to hard-block a repeat Like/Repost from the same user on the
// same target (checked server-side, not just reflected in the UI's
// disabled-button state) — matters more now than it would for a
// verified action, since a self-reported Like has no other backstop
// against someone farming repeat "engagement" for a future points
// feature by clicking the same button twice. Comments are deliberately
// NOT deduped this way — a person can legitimately comment more than
// once.
function has_action_on_target(string $actionType, int $targetPostId, int $userId): bool
{
    $stmt = db()->prepare(
        'SELECT 1 FROM engagement_actions WHERE target_post_id = ? AND user_id = ? AND action_type = ? AND success = 1 LIMIT 1'
    );
    $stmt->execute([$targetPostId, $userId, $actionType]);
    return (bool) $stmt->fetchColumn();
}

// Shared INSERT for all three action types — any $row key not present
// defaults to NULL/the column default (including 'verification', so a
// caller that forgets to set it gets the column's own 'self_reported'
// default rather than an error).
function engagement_log(array $row): void
{
    $cols = ['workspace_id', 'target_post_id', 'target_urn', 'user_id', 'linkedin_account_id', 'action_type', 'verification', 'comment_text', 'li_response_status', 'li_response_id', 'success', 'error_message'];
    $stmt = db()->prepare('INSERT INTO engagement_actions (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
    $stmt->execute(array_map(fn ($c) => $row[$c] ?? null, $cols));
}

// li_create_repost() bakes the HTTP status into the exception message
// ("Repost failed 422: ...") rather than as a structured property, same
// convention every other li_* function uses (RuntimeException, no
// custom exception class) — this regexes it back out for the log's
// li_response_status column. Best-effort only: null if the message
// doesn't match (e.g. a network error before any HTTP response came
// back).
function engagement_extract_status(Throwable $e): ?int
{
    return preg_match('/\b(\d{3})\b/', $e->getMessage(), $m) ? (int) $m[1] : null;
}

// Looks up the acting LinkedIn account and confirms it's usable in this
// target post's workspace and still active — shared by all three
// engagement_*() functions below. Returns
// [account-or-null, error-response-or-null].
function engagement_resolve_account(array $target, int $userId, int $accountId): array
{
    if (!account_usable_in_workspace($accountId, $userId, (int) $target['workspace_id'])) {
        return [null, ['success' => false, 'error' => 'Invalid LinkedIn account for this workspace.', 'status_code' => 422]];
    }
    $acctStmt = db()->prepare('SELECT access_token, target_urn, status FROM linkedin_accounts WHERE id = ?');
    $acctStmt->execute([$accountId]);
    $account = $acctStmt->fetch();
    if (!$account || $account['status'] !== 'active') {
        return [null, ['success' => false, 'error' => 'The connected LinkedIn account needs to be reconnected.', 'status_code' => 422]];
    }
    if (engagement_actions_remaining_today($accountId) <= 0) {
        return [null, ['success' => false, 'error' => "You've reached today's engagement action limit for this account. Try again tomorrow.", 'status_code' => 429]];
    }
    return [$account, null];
}

// Self-reported — see the file-level comment for why. Makes no LinkedIn
// API call; the caller (assets/js/engagement.js) opens the real post in
// a new tab at the same moment this fires. Hard-blocks a repeat Like
// from the same user on the same target (see has_action_on_target()).
function engagement_like(int $targetPostId, int $userId, int $accountId): array
{
    $target = fetch_target_post($targetPostId, $userId);
    if (!$target) {
        return ['success' => false, 'error' => 'Target post not found.', 'status_code' => 404];
    }
    if ($target['status'] !== 'active') {
        return ['success' => false, 'error' => 'This post has been archived.', 'status_code' => 422];
    }
    if (has_action_on_target('like', $targetPostId, $userId)) {
        return ['success' => false, 'error' => "You've already marked this as liked.", 'status_code' => 422];
    }
    [$account, $error] = engagement_resolve_account($target, $userId, $accountId);
    if ($error) {
        return $error;
    }

    engagement_log([
        'workspace_id' => $target['workspace_id'], 'target_post_id' => $targetPostId, 'target_urn' => $target['target_urn'],
        'user_id' => $userId, 'linkedin_account_id' => $accountId, 'action_type' => 'like',
        'verification' => 'self_reported', 'success' => 1,
    ]);
    return ['success' => true];
}

// Self-reported — see the file-level comment for why. Not deduped
// (has_action_on_target() is deliberately not checked here — a person
// can legitimately comment more than once).
function engagement_comment(int $targetPostId, int $userId, int $accountId, string $commentText): array
{
    $commentText = trim($commentText);
    if ($commentText === '') {
        return ['success' => false, 'error' => 'Enter a comment.', 'status_code' => 422];
    }
    if (mb_strlen($commentText) > 1250) { // LinkedIn's documented comment length ceiling
        return ['success' => false, 'error' => 'Comment is too long (max 1250 characters).', 'status_code' => 422];
    }
    $target = fetch_target_post($targetPostId, $userId);
    if (!$target || $target['status'] !== 'active') {
        return ['success' => false, 'error' => 'Target post not found or archived.', 'status_code' => 404];
    }
    [$account, $error] = engagement_resolve_account($target, $userId, $accountId);
    if ($error) {
        return $error;
    }

    engagement_log([
        'workspace_id' => $target['workspace_id'], 'target_post_id' => $targetPostId, 'target_urn' => $target['target_urn'],
        'user_id' => $userId, 'linkedin_account_id' => $accountId, 'action_type' => 'comment', 'comment_text' => $commentText,
        'verification' => 'self_reported', 'success' => 1,
    ]);
    return ['success' => true];
}

// Real, verified, in-app action — see the file-level comment. No
// redirect: publishes the repost directly via the already-approved
// Posts API and only logs success once LinkedIn actually confirms it.
// $commentary is optional ("repost with your thoughts" vs a plain
// repost — see li_create_repost()). Hard-blocks a repeat repost from
// the same user on the same target, same as Like — reposting the exact
// same content twice from one account is pointless on LinkedIn's side
// too, so this also just avoids an accidental double-submit.
function engagement_repost(int $targetPostId, int $userId, int $accountId, string $commentary = ''): array
{
    $commentary = trim($commentary);
    if (mb_strlen($commentary) > 3000) { // LinkedIn's documented post commentary length ceiling
        return ['success' => false, 'error' => 'Comment is too long (max 3000 characters).', 'status_code' => 422];
    }
    $target = fetch_target_post($targetPostId, $userId);
    if (!$target || $target['status'] !== 'active') {
        return ['success' => false, 'error' => 'Target post not found or archived.', 'status_code' => 404];
    }
    if (has_action_on_target('repost', $targetPostId, $userId)) {
        return ['success' => false, 'error' => "You've already reposted this.", 'status_code' => 422];
    }
    [$account, $error] = engagement_resolve_account($target, $userId, $accountId);
    if ($error) {
        return $error;
    }

    $log = [
        'workspace_id' => $target['workspace_id'], 'target_post_id' => $targetPostId, 'target_urn' => $target['target_urn'],
        'user_id' => $userId, 'linkedin_account_id' => $accountId, 'action_type' => 'repost',
        'verification' => 'api', 'comment_text' => $commentary !== '' ? $commentary : null,
    ];
    try {
        $repostUrn = li_create_repost($account['access_token'], $account['target_urn'], $target['target_urn'], $commentary, get_mention_candidates($userId));
        engagement_log($log + ['success' => 1, 'li_response_status' => 201, 'li_response_id' => $repostUrn]);
        return ['success' => true, 'repost_urn' => $repostUrn];
    } catch (Throwable $e) {
        engagement_log($log + ['success' => 0, 'error_message' => $e->getMessage(), 'li_response_status' => engagement_extract_status($e)]);
        return ['success' => false, 'error' => $e->getMessage(), 'status_code' => 500];
    }
}
