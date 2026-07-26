<?php
// Workspaces: full personal/company segregation. Every user has exactly
// one 'personal' workspace (created at signup / by the migration script)
// plus one workspace per company page. Knowledge (pillars, personas,
// CTAs, news topics, documents) and content (posts, calendar batches)
// are scoped to a workspace; the sidebar switcher (includes/layout_top.php
// + api/switch_workspace.php) sets the active one in the session.

function fetch_workspaces(int $userId): array
{
    $stmt = db()->prepare("SELECT * FROM workspaces WHERE user_id = ? ORDER BY type = 'personal' DESC, name");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function fetch_workspace(int $userId, int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM workspaces WHERE user_id = ? AND id = ?');
    $stmt->execute([$userId, $id]);
    return $stmt->fetch() ?: null;
}

function create_workspace(int $userId, string $type, string $name, ?int $linkedinAccountId = null): int
{
    $type = $type === 'personal' ? 'personal' : 'company';
    db()->prepare('INSERT INTO workspaces (user_id, type, name, linkedin_account_id) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $type, trim($name) ?: ($type === 'personal' ? 'Personal' : 'Company'), $linkedinAccountId ?: null]);
    return (int) db()->lastInsertId();
}

// The one personal workspace — created on first touch so existing
// sessions/users never hit a "no workspace" state even before the
// migration script has run.
function personal_workspace_id(int $userId): int
{
    $stmt = db()->prepare("SELECT id FROM workspaces WHERE user_id = ? AND type = 'personal' LIMIT 1");
    $stmt->execute([$userId]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    return create_workspace($userId, 'personal', 'Personal');
}

// Active workspace for this session, validated against ownership every
// call (a stale session id after a workspace delete falls back to the
// personal workspace rather than erroring).
function current_workspace_id(): int
{
    $userId = current_user_id();
    $id = (int) ($_SESSION['workspace_id'] ?? 0);
    if ($id && fetch_workspace($userId, $id)) {
        return $id;
    }
    $id = personal_workspace_id($userId);
    $_SESSION['workspace_id'] = $id;
    return $id;
}

function current_workspace(): array
{
    return fetch_workspace(current_user_id(), current_workspace_id());
}

function set_current_workspace(int $userId, int $id): bool
{
    if (!fetch_workspace($userId, $id)) {
        return false;
    }
    $_SESSION['workspace_id'] = $id;
    return true;
}

// Profile fields → prompt context lines, consumed by
// includes/ai_generate.php build_context_block(). The 'about' field is
// the workspace's brief (successor to users.brand_brief/self_brief).
function workspace_context_text(?array $ws): string
{
    if (!$ws) {
        return '';
    }
    $lines = [];
    $isPersonal = ($ws['type'] ?? '') === 'personal';
    $label = $isPersonal ? 'About the author' : 'About the company';
    // KB expansion Phase 0 (see docs/KNOWLEDGE_BASE.md) — Company
    // Identity + Tone fields interspersed among the original 7 below.
    // Every one is optional; a workspace with only the original fields
    // filled in produces byte-identical output to before this change.
    foreach ([
        $label                 => $ws['about'] ?? null,
        'Tagline'              => $ws['tagline'] ?? null,
        'Founded'              => $ws['founded_year'] ?? null,
        'Size'                 => $ws['company_size'] ?? null,
        'Headquarters'         => $ws['hq_location'] ?? null,
        'Industry'             => $ws['industry'] ?? null,
        'Target audience'      => $ws['target_audience'] ?? null,
        'Mission'              => $ws['mission'] ?? null,
        'Vision'               => $ws['vision'] ?? null,
        'Story'                => $ws['story'] ?? null,
        'Credibility statement' => $ws['credibility_statement'] ?? null,
        'Notable clients'      => $ws['notable_clients'] ?? null,
        'Awards'               => $ws['awards'] ?? null,
        'Tone of voice'        => $ws['tone_of_voice'] ?? null,
        'Tone descriptors'     => $ws['tone_descriptors'] ?? null,
        'Avoid this tone'      => $ws['anti_tone'] ?? null,
        'Words to favor'       => $ws['words_always'] ?? null,
        'Words to never use (follow strictly)' => $ws['words_never'] ?? null,
        'Post opening style'   => $ws['post_opening_style'] ?? null,
        'Hook style'           => $ws['hook_style'] ?? null,
        'Hashtag strategy'     => $ws['hashtag_strategy'] ?? null,
        'Posting frequency'    => $ws['post_frequency'] ?? null,
        'LinkedIn CTA style'   => $ws['cta_linkedin'] ?? null,
        'Paragraph style'      => $ws['paragraph_style'] ?? null,
        'Example to mirror'    => $ws['good_example'] ?? null,
        'Example to avoid writing like' => $ws['bad_example'] ?? null,
        'Content goals'        => $ws['goals'] ?? null,
        'Content rules (follow strictly)' => $ws['content_rules'] ?? null,
        'Custom instructions (follow strictly)' => $ws['custom_instructions'] ?? null,
        'Website'              => $ws['website'] ?? null,
    ] as $key => $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $lines[] = "{$key}: {$value}";
        }
    }
    return implode("\n", $lines);
}

// Reference-document knowledge for the prompt (Phase B fills
// knowledge_documents; safe no-op while the table is empty). Uses each
// doc's compact AI summary when present, else raw extracted text,
// budget-capped so a big PDF can't blow up the prompt.
function workspace_documents_text(int $workspaceId, int $budgetChars = 6000): string
{
    $stmt = db()->prepare(
        'SELECT filename, extracted_text, summary FROM knowledge_documents
         WHERE workspace_id = ? AND (summary IS NOT NULL OR extracted_text IS NOT NULL)
         ORDER BY uploaded_at DESC'
    );
    $stmt->execute([$workspaceId]);
    $parts = [];
    $used = 0;
    foreach ($stmt->fetchAll() as $doc) {
        $text = trim((string) ($doc['summary'] ?: $doc['extracted_text']));
        if ($text === '') {
            continue;
        }
        $remaining = $budgetChars - $used;
        if ($remaining <= 200) {
            break;
        }
        $text = mb_substr($text, 0, $remaining);
        $parts[] = "From \"{$doc['filename']}\":\n{$text}";
        $used += mb_strlen($text);
    }
    return $parts ? "Reference material (from the author's uploaded documents):\n" . implode("\n\n", $parts) : '';
}

// The workspace a post belongs to (for flows that start from a post id,
// e.g. calendar generation). Null-safe for pre-migration posts.
function fetch_post_workspace(int $userId, ?int $workspaceId): ?array
{
    return $workspaceId ? fetch_workspace($userId, $workspaceId) : null;
}
