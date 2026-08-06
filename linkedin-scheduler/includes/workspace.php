<?php
// Workspaces: full personal/company segregation. Every user has exactly
// one 'personal' workspace (created at signup / by the migration script)
// plus one workspace per company page. Knowledge (pillars, personas,
// CTAs, news topics, documents) and content (posts, calendar batches)
// are scoped to a workspace; the sidebar switcher (includes/layout_top.php
// + api/switch_workspace.php) sets the active one in the session.

// Owned workspaces UNION workspaces explicitly granted via
// workspace_members (see includes/organizations.php grant_workspace_access())
// — a teammate who's been granted a specific page sees it here exactly
// like their own, with no other change needed anywhere else that reads
// through these two functions (current_workspace_id(), the sidebar
// switcher, set_current_workspace() all call one of these).
// owner_name/owner_email identify a granted (not owned) workspace's
// actual owner for the sidebar switcher — see layout_top.php, which
// only shows them when w.user_id differs from the viewing user.
function fetch_workspaces(int $userId): array
{
    $stmt = db()->prepare(
        "SELECT w.*, u.name AS owner_name, u.email AS owner_email FROM workspaces w
         JOIN users u ON u.id = w.user_id
         LEFT JOIN workspace_members wm ON wm.workspace_id = w.id AND wm.user_id = ?
         WHERE w.user_id = ? OR wm.user_id IS NOT NULL
         ORDER BY w.type = 'personal' DESC, w.name"
    );
    $stmt->execute([$userId, $userId]);
    return $stmt->fetchAll();
}

function fetch_workspace(int $userId, int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT w.* FROM workspaces w
         LEFT JOIN workspace_members wm ON wm.workspace_id = w.id AND wm.user_id = ?
         WHERE w.id = ? AND (w.user_id = ? OR wm.user_id IS NOT NULL)'
    );
    $stmt->execute([$userId, $id, $userId]);
    return $stmt->fetch() ?: null;
}

// Bare owner lookup, no access check — every call site already reached
// $workspaceId through an authorized path (current_workspace_id(),
// fetch_post(), etc.), so this is just "whose page is it" for resolving
// brand identity (fonts/palettes/footer images — see
// workspace_brand_user_id() below), not an authorization decision.
function workspace_owner_id(int $workspaceId): ?int
{
    $stmt = db()->prepare('SELECT user_id FROM workspaces WHERE id = ?');
    $stmt->execute([$workspaceId]);
    $ownerId = $stmt->fetchColumn();
    return $ownerId ? (int) $ownerId : null;
}

// Brand identity (fonts, palettes, footer image/logo, footer name size)
// belongs to the page, not whoever happens to be posting — a granted
// teammate acting on a shared workspace should render with the page
// owner's brand settings, not their own personal ones. Every render/
// resolve call site that used to pass the acting user's id straight
// through now resolves it via this first. Falls back to the acting user
// when there's no workspace context (a bare personal post) or the
// workspace lookup comes back empty (shouldn't happen for an already-
// authorized $workspaceId, but fails safe rather than fatal).
function workspace_brand_user_id(int $actingUserId, ?int $workspaceId): int
{
    if ($workspaceId) {
        $ownerId = workspace_owner_id($workspaceId);
        if ($ownerId) {
            return $ownerId;
        }
    }
    return $actingUserId;
}

function create_workspace(int $userId, string $type, string $name, ?int $linkedinAccountId = null): int
{
    $type = $type === 'personal' ? 'personal' : 'company';
    db()->prepare('INSERT INTO workspaces (user_id, type, name, linkedin_account_id) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $type, trim($name) ?: ($type === 'personal' ? 'Personal' : 'Company'), $linkedinAccountId ?: null]);
    return (int) db()->lastInsertId();
}

// Used by the post-signup onboarding steps (signup_company.php/
// signup_goals.php) — no ownership check here, the caller resolves
// $workspaceId via personal_workspace_id(current_user_id()) in the same
// request, already authorized.
function set_workspace_name(int $workspaceId, string $name): void
{
    $name = trim($name);
    if ($name === '') {
        return;
    }
    db()->prepare('UPDATE workspaces SET name = ? WHERE id = ?')->execute([$name, $workspaceId]);
}

function set_workspace_goals(int $workspaceId, string $goals): void
{
    $goals = trim($goals);
    db()->prepare('UPDATE workspaces SET goals = ? WHERE id = ?')->execute([$goals !== '' ? $goals : null, $workspaceId]);
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

// KB expansion Phase 1 (docs/KNOWLEDGE_BASE.md) — the workspace's
// default sender's voice, consumed by build_context_block() in
// includes/ai_generate.php. This is the block the KB design doc calls
// out as making a post "sound like the actual person, not a generic
// AI" — individual_tone + example_posts are the highest-value fields.
function sender_context_text(?array $sender): string
{
    if (!$sender) {
        return '';
    }
    $who = trim((string) ($sender['full_name'] ?? ''));
    if ($who === '') {
        return '';
    }
    if (!empty($sender['title'])) {
        $who .= ', ' . $sender['title'];
    }
    $lines = ["Writing as: {$who}"];
    foreach ([
        'Background'              => $sender['background'] ?? null,
        'Credibility'             => $sender['credibility'] ?? null,
        'This person\'s voice (follow closely)' => $sender['individual_tone'] ?? null,
        'Real examples of their posts to mirror' => $sender['example_posts'] ?? null,
        'Topics they usually write about' => $sender['post_topics'] ?? null,
    ] as $key => $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $lines[] = "{$key}: {$value}";
        }
    }
    return implode("\n", $lines);
}

// KB expansion Phase 9 (docs/KNOWLEDGE_BASE.md) — the service being
// pitched, when the caller (New Post's AI panel, Calendar Batch) has
// one selected/matched. Consumed by build_context_block() in
// includes/ai_generate.php, positioned right after Company/Tone per
// the design doc's prompt order.
function service_context_text(?array $service): string
{
    if (!$service) {
        return '';
    }
    $name = trim((string) ($service['name'] ?? ''));
    if ($name === '') {
        return '';
    }
    $lines = ["Service being pitched: {$name}"];
    foreach ([
        'One-liner'          => $service['one_liner'] ?? null,
        'Problem it solves'  => $service['problem_statement'] ?? null,
        'Outcomes'           => $service['outcomes'] ?? null,
        'Differentiators'    => $service['differentiators'] ?? null,
        'Description'        => $service['description'] ?? null,
    ] as $key => $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $lines[] = "{$key}: {$value}";
        }
    }
    return implode("\n", $lines);
}

// KB expansion Phase 9 — a matching Proof Point (Block 8), auto-
// attached (see fetch_matching_proof_point() in post_helpers.php) when
// a Service is present, rather than needing its own selector.
function proof_point_context_text(?array $proof): string
{
    if (!$proof) {
        return '';
    }
    $client = trim((string) ($proof['client_name'] ?? ''));
    if ($client === '') {
        return '';
    }
    $lines = ["Proof point — client: {$client}"];
    foreach ([
        'Industry'    => $proof['client_industry'] ?? null,
        'Challenge'   => $proof['challenge'] ?? null,
        'Solution'    => $proof['solution'] ?? null,
        'Outcomes'    => $proof['outcomes'] ?? null,
        'Metrics'     => $proof['metrics'] ?? null,
        'Quote'       => $proof['quote'] ?? null,
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
