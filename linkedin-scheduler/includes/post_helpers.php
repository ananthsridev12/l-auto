<?php

function fetch_post_with_slides(int $postId, int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT p.*, la.display_name AS account_name, la.status AS account_status
         FROM posts p
         LEFT JOIN linkedin_accounts la ON la.id = p.linkedin_account_id
         WHERE p.id = ? AND p.user_id = ?'
    );
    $stmt->execute([$postId, $userId]);
    $post = $stmt->fetch();
    if (!$post) {
        return null;
    }

    $slideStmt = db()->prepare('SELECT filename, filepath FROM post_slides WHERE post_id = ? ORDER BY slide_order ASC');
    $slideStmt->execute([$postId]);
    $post['slides'] = array_map(fn ($s) => [
        'filename' => $s['filename'],
        'url'      => slide_public_url($s['filepath']),
    ], $slideStmt->fetchAll());

    return $post;
}

// Appends the file's own mtime as a cache-busting query param. Re-render
// flows (api/post_rerender.php, etc.) overwrite the same filename in
// place rather than writing a new one, so without this the URL never
// changes and browsers keep serving the pre-regenerate image on the
// next full page load — the in-place AJAX preview looked right, but a
// reload showed the stale copy.
function slide_public_url(string $filepath): string
{
    $relative = ltrim(str_replace(UPLOAD_DIR, '', $filepath), '/');
    $url = app_path('uploads/' . $relative);
    $mtime = @filemtime($filepath);
    return $mtime !== false ? $url . '?v=' . $mtime : $url;
}

function fetch_user_accounts(int $userId): array
{
    $stmt = db()->prepare('SELECT id, display_name, account_type FROM linkedin_accounts WHERE user_id = ? AND status = "active" ORDER BY display_name');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function fetch_tag_directory(int $userId): array
{
    $stmt = db()->prepare('SELECT id, display_name, target_urn FROM tag_directory WHERE user_id = ? ORDER BY display_name');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// display_name => target_urn, for resolving "@[Name]" tags inserted via
// the "Tag a Page" toolbar button into real LinkedIn mentions at publish
// time. See includes/linkedin_text.php. Connected accounts win on a
// name collision with a manually-added tag_directory entry.
function get_mention_candidates(int $userId): array
{
    $stmt = db()->prepare('SELECT display_name, target_urn FROM linkedin_accounts WHERE user_id = ? AND status = "active"');
    $stmt->execute([$userId]);
    $connected = array_column($stmt->fetchAll(), 'target_urn', 'display_name');

    $directory = array_column(fetch_tag_directory($userId), 'target_urn', 'display_name');

    return array_merge($directory, $connected);
}

// Combined, display-ready list for the "@ Tag" picker: connected
// accounts plus manually-added tag_directory entries, in one shape.
function fetch_mention_picker_list(int $userId): array
{
    $list = array_map(fn ($a) => ['name' => $a['display_name'], 'type' => $a['account_type']], fetch_user_accounts($userId));
    foreach (fetch_tag_directory($userId) as $entry) {
        $list[] = ['name' => $entry['display_name'], 'type' => 'tagged page'];
    }
    return $list;
}

// Content Knowledge Base — reusable context for AI generation, picked
// from New Post's AI panel or applied automatically (see
// includes/ai_generate.php build_generation_prompt()).

// SELECT * (was an explicit id/name/description list) so KB expansion
// Phase 2's enrichment columns (docs/KNOWLEDGE_BASE.md) come along for
// free — every existing caller only reads name/description, so this
// is forward-compatible, not a behavior change for them.
function fetch_personas(int $userId, ?int $workspaceId = null): array
{
    if ($workspaceId === null) {
        $stmt = db()->prepare('SELECT * FROM personas WHERE user_id = ? ORDER BY name');
        $stmt->execute([$userId]);
    } else {
        $stmt = db()->prepare('SELECT * FROM personas WHERE user_id = ? AND (workspace_id = ? OR workspace_id IS NULL) ORDER BY name');
        $stmt->execute([$userId, $workspaceId]);
    }
    return $stmt->fetchAll();
}

function fetch_persona(int $userId, int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM personas WHERE user_id = ? AND id = ?');
    $stmt->execute([$userId, $id]);
    return $stmt->fetch() ?: null;
}

// KB expansion Phase 1 (docs/KNOWLEDGE_BASE.md) — Senders: who a post's
// voice belongs to. Scoped by workspace_id alone (no user_id column —
// a net-new table with no legacy pre-workspace rows to keep visible).
function fetch_senders(int $workspaceId): array
{
    $stmt = db()->prepare('SELECT * FROM senders WHERE workspace_id = ? ORDER BY is_default DESC, full_name');
    $stmt->execute([$workspaceId]);
    return $stmt->fetchAll();
}

function fetch_sender(int $workspaceId, int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM senders WHERE workspace_id = ? AND id = ?');
    $stmt->execute([$workspaceId, $id]);
    return $stmt->fetch() ?: null;
}

function fetch_default_sender(int $workspaceId): ?array
{
    $stmt = db()->prepare('SELECT * FROM senders WHERE workspace_id = ? AND is_default = 1 LIMIT 1');
    $stmt->execute([$workspaceId]);
    return $stmt->fetch() ?: null;
}

// Only one sender per workspace may be default — clears any existing
// default first, same application-level approach as
// set_default_brand_palette() below.
function set_default_sender(int $workspaceId, int $id): void
{
    $pdo = db();
    $pdo->prepare('UPDATE senders SET is_default = 0 WHERE workspace_id = ?')->execute([$workspaceId]);
    $pdo->prepare('UPDATE senders SET is_default = 1 WHERE workspace_id = ? AND id = ?')->execute([$workspaceId, $id]);
}

function delete_sender(int $workspaceId, int $id): void
{
    db()->prepare('DELETE FROM senders WHERE workspace_id = ? AND id = ?')->execute([$workspaceId, $id]);
}

// KB expansion Phase 3 (docs/KNOWLEDGE_BASE.md) — Verticals.
function fetch_verticals(int $workspaceId): array
{
    $stmt = db()->prepare('SELECT * FROM verticals WHERE workspace_id = ? ORDER BY name');
    $stmt->execute([$workspaceId]);
    return $stmt->fetchAll();
}

function fetch_vertical(int $workspaceId, int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM verticals WHERE workspace_id = ? AND id = ?');
    $stmt->execute([$workspaceId, $id]);
    return $stmt->fetch() ?: null;
}

function delete_vertical(int $workspaceId, int $id): void
{
    db()->prepare('DELETE FROM verticals WHERE workspace_id = ? AND id = ?')->execute([$workspaceId, $id]);
}

// KB round 2, Phase 15 (docs/KNOWLEDGE_BASE.md) — CSV import resolves a
// vertical_name/service_name column to an id by name lookup within the
// importing workspace (a human-editable CSV can't know internal
// auto-increment ids). No match = left unlinked, not a failed row —
// same graceful-degradation ethos as everywhere else in this file.
function find_vertical_id_by_name(int $workspaceId, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT id FROM verticals WHERE workspace_id = ? AND name = ? LIMIT 1');
    $stmt->execute([$workspaceId, $name]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

function find_service_id_by_name(int $workspaceId, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT id FROM services WHERE workspace_id = ? AND name = ? LIMIT 1');
    $stmt->execute([$workspaceId, $name]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

// KB expansion Phase 4 (docs/KNOWLEDGE_BASE.md) — Services.
function fetch_services(int $workspaceId): array
{
    $stmt = db()->prepare('SELECT * FROM services WHERE workspace_id = ? ORDER BY name');
    $stmt->execute([$workspaceId]);
    return $stmt->fetchAll();
}

function fetch_service(int $workspaceId, int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM services WHERE workspace_id = ? AND id = ?');
    $stmt->execute([$workspaceId, $id]);
    return $stmt->fetch() ?: null;
}

function delete_service(int $workspaceId, int $id): void
{
    db()->prepare('DELETE FROM services WHERE workspace_id = ? AND id = ?')->execute([$workspaceId, $id]);
}

// KB expansion Phase 9/10 (docs/KNOWLEDGE_BASE.md) — Calendar Batch has
// no per-post Service picker (it auto-generates unattended), so it
// matches a service by keyword overlap between the topic/pillar name
// and the service's signal fields instead. Weighted similarly to the
// ISE design doc's KBMatcher (signal_types/tech_triggers count for more
// than a plain signal_keywords hit, industries count for less) — but
// adapted to match a free-text topic string rather than a structured
// target-company row, since this app has no separate "companies" table.
// Deterministic, no AI involved. Returns null below the qualifying
// threshold so "no confident match" never forces irrelevant context in.
const SERVICE_MATCH_MIN_SCORE = 2;

function match_service_by_keywords(int $workspaceId, string $topic): ?array
{
    $topicWords = preg_split('/[^a-z0-9]+/', strtolower(trim($topic)), -1, PREG_SPLIT_NO_EMPTY);
    if (!$topicWords) {
        return null;
    }
    $topicWords = array_flip($topicWords);
    $best = null;
    $bestScore = 0;
    foreach (fetch_services($workspaceId) as $service) {
        $score = score_service_signal_overlap($service, $topicWords);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $service;
        }
    }
    return $bestScore >= SERVICE_MATCH_MIN_SCORE ? $best : null;
}

// Weighted overlap between a service's signal fields and a set of
// lowercased topic words (as an array_flip'd lookup for O(1) membership
// checks). Exposed separately from match_service_by_keywords() so a
// future caller (e.g. a KB completeness / signal-strength display) can
// score one specific service without re-fetching the whole list.
function score_service_signal_overlap(array $service, array $topicWordLookup): int
{
    $score = 0;
    foreach ([
        'signal_keywords' => 2,
        'signal_types'    => 3,
        'tech_triggers'   => 3,
        'industries'      => 1,
    ] as $field => $weight) {
        $raw = strtolower(trim((string) ($service[$field] ?? '')));
        if ($raw === '') {
            continue;
        }
        $fieldWords = preg_split('/[^a-z0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($fieldWords as $word) {
            if (isset($topicWordLookup[$word])) {
                $score += $weight;
            }
        }
    }
    return $score;
}

// KB expansion Phase 5 (docs/KNOWLEDGE_BASE.md) — ICPs.
function fetch_icps(int $workspaceId): array
{
    $stmt = db()->prepare('SELECT * FROM icps WHERE workspace_id = ? ORDER BY name');
    $stmt->execute([$workspaceId]);
    return $stmt->fetchAll();
}

function fetch_icp(int $workspaceId, int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM icps WHERE workspace_id = ? AND id = ?');
    $stmt->execute([$workspaceId, $id]);
    return $stmt->fetch() ?: null;
}

function delete_icp(int $workspaceId, int $id): void
{
    db()->prepare('DELETE FROM icps WHERE workspace_id = ? AND id = ?')->execute([$workspaceId, $id]);
}

// KB expansion Phase 7 (docs/KNOWLEDGE_BASE.md) — Proof Points.
function fetch_proof_points(int $workspaceId): array
{
    $stmt = db()->prepare('SELECT * FROM proof_points WHERE workspace_id = ? ORDER BY client_name');
    $stmt->execute([$workspaceId]);
    return $stmt->fetchAll();
}

function fetch_proof_point(int $workspaceId, int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM proof_points WHERE workspace_id = ? AND id = ?');
    $stmt->execute([$workspaceId, $id]);
    return $stmt->fetch() ?: null;
}

function delete_proof_point(int $workspaceId, int $id): void
{
    db()->prepare('DELETE FROM proof_points WHERE workspace_id = ? AND id = ?')->execute([$workspaceId, $id]);
}

// KB expansion Phase 9 (docs/KNOWLEDGE_BASE.md) — auto-attach one
// public proof point for a selected Service, instead of a separate
// picker. Falls back to the service's vertical when no service-specific
// proof point exists.
function fetch_matching_proof_point(int $workspaceId, int $serviceId, ?int $verticalId = null): ?array
{
    $stmt = db()->prepare('SELECT * FROM proof_points WHERE workspace_id = ? AND service_id = ? AND is_public = 1 ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$workspaceId, $serviceId]);
    $proof = $stmt->fetch();
    if ($proof || !$verticalId) {
        return $proof ?: null;
    }
    $stmt = db()->prepare('SELECT * FROM proof_points WHERE workspace_id = ? AND vertical_id = ? AND is_public = 1 ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$workspaceId, $verticalId]);
    return $stmt->fetch() ?: null;
}

// KB round 2, Phase 14 (KB Phase 10 — docs/KNOWLEDGE_BASE.md) — a fill
// summary for the Knowledge Base page's top strip. Informational only;
// generation already degrades gracefully regardless of what's filled.
// 'mode' mirrors the design doc's own Lite/Full detection: Full once
// there's at least one Vertical AND one Service to match against.
function kb_completeness(int $userId, array $workspace): array
{
    $workspaceId = (int) $workspace['id'];
    $companyFilled = trim((string) ($workspace['about'] ?? '')) !== '' || trim((string) ($workspace['credibility_statement'] ?? '')) !== '';
    $toneFilled = trim((string) ($workspace['tone_descriptors'] ?? '')) !== ''
        || trim((string) ($workspace['words_never'] ?? '')) !== ''
        || trim((string) ($workspace['good_example'] ?? '')) !== '';
    $verticalCount = count(fetch_verticals($workspaceId));
    $serviceCount = count(fetch_services($workspaceId));

    return [
        'company_filled' => $companyFilled,
        'tone_filled'    => $toneFilled,
        'senders'        => count(fetch_senders($workspaceId)),
        'verticals'      => $verticalCount,
        'services'       => $serviceCount,
        'icps'           => count(fetch_icps($workspaceId)),
        'personas'       => count(fetch_personas($userId, $workspaceId)),
        'proof_points'   => count(fetch_proof_points($workspaceId)),
        'documents'      => count(fetch_knowledge_documents($workspaceId)),
        'mode'           => ($verticalCount > 0 && $serviceCount > 0) ? 'full' : 'lite',
    ];
}

// $workspaceId scopes results to a workspace; rows with NULL
// workspace_id (created before the workspace migration ran) stay
// visible everywhere so nothing disappears mid-rollout.
function fetch_content_pillars(int $userId, ?int $workspaceId = null): array
{
    if ($workspaceId === null) {
        $stmt = db()->prepare('SELECT id, name, description, category, default_layout, default_palette FROM content_pillars WHERE user_id = ? ORDER BY name');
        $stmt->execute([$userId]);
    } else {
        $stmt = db()->prepare('SELECT id, name, description, category, default_layout, default_palette FROM content_pillars WHERE user_id = ? AND (workspace_id = ? OR workspace_id IS NULL) ORDER BY name');
        $stmt->execute([$userId, $workspaceId]);
    }
    return $stmt->fetchAll();
}

function fetch_content_pillar(int $userId, int $id): ?array
{
    $stmt = db()->prepare('SELECT id, name, description, category, default_layout, default_palette FROM content_pillars WHERE user_id = ? AND id = ?');
    $stmt->execute([$userId, $id]);
    return $stmt->fetch() ?: null;
}

// Auto-assigns a Design Template for bulk generation (Content Studio CSV
// upload, Content Calendar Generator) so the review step doesn't need a
// manual pick for every row — see schema.sql's comment on
// content_pillars.default_layout / users.default_layout_single/_carousel.
// $pillarName matches by name (the CSV's free-text "Pillar" column and a
// content pillar's name share the same string, same convention
// includes/creative_builder.php's series_label already relies on) rather
// than ID, since Content Studio rows aren't linked to a content_pillars
// row the way Calendar-generated posts are. A pillar match wins over the
// per-user format default, which wins over 'classic'.
function resolve_default_layout(int $userId, string $format, ?string $pillarName = null, ?int $workspaceId = null): string
{
    if ($pillarName) {
        $stmt = db()->prepare('SELECT default_layout FROM content_pillars WHERE user_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$userId, $pillarName]);
        $pillarLayout = $stmt->fetchColumn();
        if ($pillarLayout) {
            return $pillarLayout;
        }
    }
    if ($workspaceId !== null) {
        $stmt = db()->prepare('SELECT default_layout_single, default_layout_carousel FROM workspaces WHERE id = ? AND user_id = ?');
        $stmt->execute([$workspaceId, $userId]);
    } else {
        $stmt = db()->prepare('SELECT default_layout_single, default_layout_carousel FROM users WHERE id = ?');
        $stmt->execute([$userId]);
    }
    $row = $stmt->fetch();
    $layout = $format === 'carousel' ? ($row['default_layout_carousel'] ?? null) : ($row['default_layout_single'] ?? null);
    return $layout ?: 'classic';
}

// Same auto-assignment idea as resolve_default_layout() above, but for
// the Color Palette ("template" creative-JSON field) instead of the
// Design Template. Returns null (not a hardcoded fallback) when nothing
// is configured at any tier — callers should leave the creative JSON's
// "template" key unset in that case, so render_resolve_palette_colors()'s
// own existing fallback (the user's default custom palette, else
// series-label keyword matching) decides, exactly as it did before this
// feature existed. The returned string is already in the same format the
// "template" field expects: a digit string ("1"-"4") for a built-in
// preset or "custom:{id}" for a saved brand_palettes row — callers should
// cast digit strings to int before assigning, same as every other call
// site that sets "template".
function resolve_default_palette(int $userId, string $format, ?string $pillarName = null, ?int $workspaceId = null): ?string
{
    if ($pillarName) {
        $stmt = db()->prepare('SELECT default_palette FROM content_pillars WHERE user_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$userId, $pillarName]);
        $pillarPalette = $stmt->fetchColumn();
        if ($pillarPalette) {
            return $pillarPalette;
        }
    }
    if ($workspaceId !== null) {
        $stmt = db()->prepare('SELECT default_palette_single, default_palette_carousel FROM workspaces WHERE id = ? AND user_id = ?');
        $stmt->execute([$workspaceId, $userId]);
    } else {
        $stmt = db()->prepare('SELECT default_palette_single, default_palette_carousel FROM users WHERE id = ?');
        $stmt->execute([$userId]);
    }
    $row = $stmt->fetch();
    $palette = $format === 'carousel' ? ($row['default_palette_carousel'] ?? null) : ($row['default_palette_single'] ?? null);
    return $palette ?: null;
}

function fetch_cta_library(int $userId, ?int $workspaceId = null): array
{
    if ($workspaceId === null) {
        $stmt = db()->prepare('SELECT id, text, funnel_stage FROM cta_library WHERE user_id = ? ORDER BY funnel_stage, text');
        $stmt->execute([$userId]);
    } else {
        $stmt = db()->prepare('SELECT id, text, funnel_stage FROM cta_library WHERE user_id = ? AND (workspace_id = ? OR workspace_id IS NULL) ORDER BY funnel_stage, text');
        $stmt->execute([$userId, $workspaceId]);
    }
    return $stmt->fetchAll();
}

function fetch_cta(int $userId, int $id): ?array
{
    $stmt = db()->prepare('SELECT id, text, funnel_stage FROM cta_library WHERE user_id = ? AND id = ?');
    $stmt->execute([$userId, $id]);
    return $stmt->fetch() ?: null;
}

// Per-user brand color palettes, selectable as a rendering "template"
// alongside the 4 built-in presets — see includes/image_renderer.php
// render_resolve_palette_colors().

function fetch_brand_palettes(int $userId): array
{
    $stmt = db()->prepare('SELECT id, name, bg_color, text_color, accent_color, cta_color, signature_color, background_image_path, is_default FROM brand_palettes WHERE user_id = ? ORDER BY name');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function fetch_brand_palette(int $userId, int $id): ?array
{
    $stmt = db()->prepare('SELECT id, name, bg_color, text_color, accent_color, cta_color, signature_color, background_image_path, is_default FROM brand_palettes WHERE user_id = ? AND id = ?');
    $stmt->execute([$userId, $id]);
    return $stmt->fetch() ?: null;
}

function fetch_default_brand_palette(int $userId): ?array
{
    $stmt = db()->prepare('SELECT id, name, bg_color, text_color, accent_color, cta_color, signature_color, background_image_path, is_default FROM brand_palettes WHERE user_id = ? AND is_default = 1 LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

// Only one palette per user may be default — clears any existing default
// before setting the new one (application-level, not a DB constraint,
// same approach used elsewhere in this app for "only one active X").
function set_default_brand_palette(int $userId, int $id): void
{
    $pdo = db();
    $pdo->prepare('UPDATE brand_palettes SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('UPDATE brand_palettes SET is_default = 1 WHERE user_id = ? AND id = ?')->execute([$userId, $id]);
}

// Per-user uploaded fonts (Regular + Bold TTF/OTF pair), selectable as
// the renderer's typeface — see includes/image_renderer.php
// render_font_path()/render_resolve_font_paths().

function fetch_brand_fonts(int $userId): array
{
    $stmt = db()->prepare('SELECT id, name, regular_path, bold_path, is_default FROM brand_fonts WHERE user_id = ? ORDER BY name');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function fetch_brand_font(int $userId, int $id): ?array
{
    $stmt = db()->prepare('SELECT id, name, regular_path, bold_path, is_default FROM brand_fonts WHERE user_id = ? AND id = ?');
    $stmt->execute([$userId, $id]);
    return $stmt->fetch() ?: null;
}

// Which font (if any) is assigned to each rendering role — see
// includes/image_renderer.php render_font_override_role(). Headline text
// uses 'heading'; body, points, CTA banner, counter, and footer all use
// 'body'. NULL falls back to the bundled Inter/DejaVu chain.
function fetch_heading_font(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT bf.id, bf.name, bf.regular_path, bf.bold_path
         FROM users u JOIN brand_fonts bf ON bf.id = u.heading_font_id
         WHERE u.id = ? AND bf.user_id = ?'
    );
    $stmt->execute([$userId, $userId]);
    return $stmt->fetch() ?: null;
}

function fetch_body_font(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT bf.id, bf.name, bf.regular_path, bf.bold_path
         FROM users u JOIN brand_fonts bf ON bf.id = u.body_font_id
         WHERE u.id = ? AND bf.user_id = ?'
    );
    $stmt->execute([$userId, $userId]);
    return $stmt->fetch() ?: null;
}

function set_heading_font(int $userId, ?int $fontId): void
{
    db()->prepare('UPDATE users SET heading_font_id = ? WHERE id = ?')->execute([$fontId ?: null, $userId]);
}

function set_body_font(int $userId, ?int $fontId): void
{
    db()->prepare('UPDATE users SET body_font_id = ? WHERE id = ?')->execute([$fontId ?: null, $userId]);
}

// Independent "Signature" font role for the footer name — separate from
// Heading/Body, takes priority over get_footer_font_role()'s Heading/Body
// toggle when set (see render_creative_to_slides()).
function fetch_footer_font(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT bf.id, bf.name, bf.regular_path, bf.bold_path
         FROM users u JOIN brand_fonts bf ON bf.id = u.footer_font_id
         WHERE u.id = ? AND bf.user_id = ?'
    );
    $stmt->execute([$userId, $userId]);
    return $stmt->fetch() ?: null;
}

function set_footer_font(int $userId, ?int $fontId): void
{
    db()->prepare('UPDATE users SET footer_font_id = ? WHERE id = ?')->execute([$fontId ?: null, $userId]);
}
