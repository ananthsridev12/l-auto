<?php
// One-time cleanup for a bug in scripts/migrate_workspaces.php's original
// company-workspace heuristic (fixed alongside this script — see that
// file's updated comment): it used to auto-create a 'company' workspace
// whenever a user had any content_pillars tagged category='company', but
// the default starter Knowledge Base (includes/kb_seed.php) seeds 5 such
// pillars for EVERY signup regardless of whether they ever connect a
// company page. That created an unwanted company workspace for
// personal-only users, and Settings' unfiltered account picker (also
// fixed) then let their personal LinkedIn account get attached to it —
// pulling their personal calendar_batches/posts into "the company
// workspace" view, since that migration blanket-reassigned pillar-less
// posts/batches there once it existed.
//
// This script finds and fixes exactly that state, per user:
//   - a 'company' workspace whose linkedin_account_id points at an
//     account_type='personal' row (the unambiguous signature of the
//     bug — a legitimately-created company workspace never ends up here
//     now that both code bugs are fixed), OR
//   - a 'company' workspace with no linkedin_account_id at all AND no
//     real company-type account AND no brand_brief on the user (created
//     purely by the old pillar heuristic, never actually used).
// For each match: every content_pillars/personas/cta_library/news_topics/
// news_items/news_trusted_sources/calendar_batches/posts row currently
// scoped to that workspace is moved to the user's Personal workspace,
// then the company workspace row itself is deleted (knowledge_documents/
// content_memory/blog_posts rows on it, if any, cascade-delete via their
// FK — there should be none for a workspace nobody knowingly used).
//
// Run ONCE, after deploying the code fixes in scripts/migrate_workspaces.php
// and pages/settings.php:
//   php scripts/fix_erroneous_company_workspace.php

require_once __DIR__ . '/../includes/db.php';

$pdo = db();

$stmt = $pdo->query(
    "SELECT w.id, w.user_id, w.name, w.linkedin_account_id, la.account_type AS attached_account_type
     FROM workspaces w
     LEFT JOIN linkedin_accounts la ON la.id = w.linkedin_account_id
     WHERE w.type = 'company'"
);
$companyWorkspaces = $stmt->fetchAll();

$fixed = 0;

foreach ($companyWorkspaces as $ws) {
    $wsId = (int) $ws['id'];
    $userId = (int) $ws['user_id'];

    $isErroneous = false;
    $reason = '';

    if ($ws['attached_account_type'] === 'personal') {
        $isErroneous = true;
        $reason = 'personal-type account attached to a company workspace';
    } elseif ($ws['linkedin_account_id'] === null) {
        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM linkedin_accounts WHERE user_id = ? AND account_type = 'company'");
        $stmt2->execute([$userId]);
        $hasCompanyAccount = (int) $stmt2->fetchColumn();
        $stmt3 = $pdo->prepare('SELECT brand_brief FROM users WHERE id = ?');
        $stmt3->execute([$userId]);
        $brandBrief = trim((string) $stmt3->fetchColumn());
        if ($hasCompanyAccount === 0 && $brandBrief === '') {
            $isErroneous = true;
            $reason = 'no company account, no brand brief — created only by the old pillar heuristic';
        }
    }

    if (!$isErroneous) {
        echo "user {$userId}: company workspace #{$wsId} \"{$ws['name']}\" looks legitimate, leaving it alone\n";
        continue;
    }

    // Personal workspace to move content back to — created if somehow missing.
    $stmt = $pdo->prepare("SELECT id FROM workspaces WHERE user_id = ? AND type = 'personal' LIMIT 1");
    $stmt->execute([$userId]);
    $personalId = (int) $stmt->fetchColumn();
    if (!$personalId) {
        $pdo->prepare("INSERT INTO workspaces (user_id, type, name) VALUES (?, 'personal', 'Personal')")->execute([$userId]);
        $personalId = (int) $pdo->lastInsertId();
    }

    echo "user {$userId}: fixing company workspace #{$wsId} \"{$ws['name']}\" ({$reason}) -> moving content to Personal #{$personalId}\n";

    // Several of these tables carry a UNIQUE KEY scoped to (user_id,
    // workspace_id, ...) — e.g. news_items on url_hash, content_pillars/
    // personas/news_topics on name/query. If Personal already has a row
    // with the same key (the same news URL got fetched under both
    // workspaces before this was noticed, say), a plain UPDATE throws an
    // uncaught duplicate-key exception and aborts the whole script
    // mid-loop. UPDATE IGNORE skips just that row instead of erroring;
    // whatever's left still pointing at the company workspace afterward
    // lost the naming/URL collision to an already-equivalent Personal
    // row, so it's a safe-to-discard duplicate, not lost data.
    foreach (['content_pillars', 'personas', 'cta_library', 'news_topics', 'news_items', 'news_trusted_sources', 'calendar_batches', 'posts'] as $tbl) {
        $upd = $pdo->prepare("UPDATE IGNORE {$tbl} SET workspace_id = ? WHERE workspace_id = ?");
        $upd->execute([$personalId, $wsId]);
        if ($upd->rowCount() > 0) {
            echo "  moved {$upd->rowCount()} row(s) in {$tbl}\n";
        }
        $del = $pdo->prepare("DELETE FROM {$tbl} WHERE workspace_id = ?");
        $del->execute([$wsId]);
        if ($del->rowCount() > 0) {
            echo "  discarded {$del->rowCount()} duplicate row(s) in {$tbl} (already present in Personal)\n";
        }
    }

    $pdo->prepare('DELETE FROM workspaces WHERE id = ? AND user_id = ?')->execute([$wsId, $userId]);
    echo "  deleted company workspace #{$wsId}\n";
    $fixed++;
}

echo "done. fixed {$fixed} erroneous company workspace(s).\n";
