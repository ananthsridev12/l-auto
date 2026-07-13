<?php
// One-time migration to Workspaces — run ONCE after applying the schema
// changes (workspaces/knowledge_documents tables + workspace_id columns):
//   php scripts/migrate_workspaces.php          (CLI)
// or open scripts/migrate_workspaces.php in the browser while logged in.
//
// For every user:
//   1. Create a Personal workspace (self_brief -> about) if missing.
//   2. Create ONE Company workspace (brand_brief -> about) named after
//      their first connected company LinkedIn account (else "My Company"),
//      linked to that account — only when they have a real company
//      account or a hand-written brand_brief (default starter-KB pillars
//      tagged 'company' do NOT count — every signup gets those, so they're
//      not a reliable signal of an actual company).
//   3. Backfill workspace_id everywhere:
//      - pillars by their category (personal -> Personal ws, else Company
//        ws when one exists, else Personal)
//      - personas/CTAs/news topics+items+sources -> Company ws (they were
//        built for company content; planner only used personas there),
//        else Personal
//      - posts via their pillar's workspace; pillar-less posts -> Company
//        ws else Personal; calendar_batches follow their posts
//      - users.news_auto_enabled/news_drafts_per_day copied to the
//        Company (else Personal) workspace
// Idempotent: rows that already have workspace_id are never touched, and
// existing workspaces are reused rather than duplicated.

require_once __DIR__ . '/../includes/db.php';

$pdo = db();
$users = $pdo->query('SELECT id, brand_brief, self_brief, news_auto_enabled, news_drafts_per_day FROM users')->fetchAll();

foreach ($users as $u) {
    $userId = (int) $u['id'];
    echo "user {$userId}:\n";

    // 1. Personal workspace
    $stmt = $pdo->prepare("SELECT id FROM workspaces WHERE user_id = ? AND type = 'personal' LIMIT 1");
    $stmt->execute([$userId]);
    $personalId = (int) $stmt->fetchColumn();
    if (!$personalId) {
        $pdo->prepare("INSERT INTO workspaces (user_id, type, name, about) VALUES (?, 'personal', 'Personal', ?)")
            ->execute([$userId, trim((string) $u['self_brief']) ?: null]);
        $personalId = (int) $pdo->lastInsertId();
        echo "  created Personal workspace #{$personalId}\n";
    }

    // 2. Company workspace (only when there's company-flavored data)
    $stmt = $pdo->prepare("SELECT id FROM workspaces WHERE user_id = ? AND type = 'company' LIMIT 1");
    $stmt->execute([$userId]);
    $companyId = (int) $stmt->fetchColumn();
    if (!$companyId) {
        $stmt = $pdo->prepare("SELECT id, display_name FROM linkedin_accounts WHERE user_id = ? AND account_type = 'company' ORDER BY id LIMIT 1");
        $stmt->execute([$userId]);
        $companyAccount = $stmt->fetch();
        // NOTE: previously also auto-created a company workspace when the
        // user had any content_pillars with category='company', but the
        // default starter Knowledge Base (includes/kb_seed.php) seeds 5
        // company-category pillars for EVERY signup whether or not they
        // ever connect a company page — that made this heuristic a false
        // positive for personal-only users, attaching an unwanted company
        // workspace (and, via Settings' unfiltered account picker, their
        // personal account) with no way to tell from the UI. A real
        // company account or a hand-written brand_brief are the only
        // reliable signals; a user who genuinely wants a company workspace
        // without either can still create one manually from Settings.
        if ($companyAccount || trim((string) $u['brand_brief']) !== '') {
            $pdo->prepare("INSERT INTO workspaces (user_id, type, name, linkedin_account_id, about) VALUES (?, 'company', ?, ?, ?)")
                ->execute([
                    $userId,
                    $companyAccount['display_name'] ?? 'My Company',
                    $companyAccount['id'] ?? null,
                    trim((string) $u['brand_brief']) ?: null,
                ]);
            $companyId = (int) $pdo->lastInsertId();
            echo "  created Company workspace #{$companyId}" . ($companyAccount ? " ({$companyAccount['display_name']})" : '') . "\n";
        }
    }

    $companyOrPersonal = $companyId ?: $personalId;

    // 3. Backfill (NULL workspace_id rows only — idempotent)
    $pdo->prepare("UPDATE content_pillars SET workspace_id = ? WHERE user_id = ? AND workspace_id IS NULL AND category = 'personal'")
        ->execute([$personalId, $userId]);
    $pdo->prepare("UPDATE content_pillars SET workspace_id = ? WHERE user_id = ? AND workspace_id IS NULL")
        ->execute([$companyOrPersonal, $userId]);

    foreach (['personas', 'cta_library', 'news_topics', 'news_items', 'news_trusted_sources'] as $tbl) {
        $pdo->prepare("UPDATE {$tbl} SET workspace_id = ? WHERE user_id = ? AND workspace_id IS NULL")
            ->execute([$companyOrPersonal, $userId]);
    }

    // Posts follow their pillar's workspace; pillar-less posts get the
    // company (else personal) workspace.
    $pdo->prepare(
        "UPDATE posts p JOIN content_pillars cp ON cp.id = p.content_pillar_id
         SET p.workspace_id = cp.workspace_id
         WHERE p.user_id = ? AND p.workspace_id IS NULL AND cp.workspace_id IS NOT NULL"
    )->execute([$userId]);
    $pdo->prepare("UPDATE posts SET workspace_id = ? WHERE user_id = ? AND workspace_id IS NULL")
        ->execute([$companyOrPersonal, $userId]);
    $pdo->prepare("UPDATE calendar_batches SET workspace_id = ? WHERE user_id = ? AND workspace_id IS NULL")
        ->execute([$companyOrPersonal, $userId]);

    // News auto-draft settings move to the workspace that got the news data
    $pdo->prepare("UPDATE workspaces SET news_auto_enabled = ?, news_drafts_per_day = ? WHERE id = ? AND news_auto_enabled = 0")
        ->execute([(int) $u['news_auto_enabled'], max(1, (int) $u['news_drafts_per_day']), $companyOrPersonal]);

    // Per-format design defaults copy to BOTH workspaces (they were
    // user-global before, so both inherit the same starting point).
    $stmt = $pdo->prepare('SELECT default_layout_single, default_layout_carousel, default_palette_single, default_palette_carousel FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $d = $stmt->fetch();
    if ($d && ($d['default_layout_single'] || $d['default_layout_carousel'] || $d['default_palette_single'] || $d['default_palette_carousel'])) {
        $upd = $pdo->prepare(
            'UPDATE workspaces SET
               default_layout_single = COALESCE(default_layout_single, ?),
               default_layout_carousel = COALESCE(default_layout_carousel, ?),
               default_palette_single = COALESCE(default_palette_single, ?),
               default_palette_carousel = COALESCE(default_palette_carousel, ?)
             WHERE user_id = ?'
        );
        $upd->execute([$d['default_layout_single'], $d['default_layout_carousel'], $d['default_palette_single'], $d['default_palette_carousel'], $userId]);
    }

    echo "  backfilled (personal #{$personalId}" . ($companyId ? ", company #{$companyId}" : ', no company workspace') . ")\n";
}

echo "done\n";
