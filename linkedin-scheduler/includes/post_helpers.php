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

function slide_public_url(string $filepath): string
{
    $relative = ltrim(str_replace(UPLOAD_DIR, '', $filepath), '/');
    return app_path('uploads/' . $relative);
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

function fetch_personas(int $userId): array
{
    $stmt = db()->prepare('SELECT id, name, description FROM personas WHERE user_id = ? ORDER BY name');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function fetch_persona(int $userId, int $id): ?array
{
    $stmt = db()->prepare('SELECT id, name, description FROM personas WHERE user_id = ? AND id = ?');
    $stmt->execute([$userId, $id]);
    return $stmt->fetch() ?: null;
}

function fetch_content_pillars(int $userId): array
{
    $stmt = db()->prepare('SELECT id, name, description, category, default_layout, default_palette FROM content_pillars WHERE user_id = ? ORDER BY name');
    $stmt->execute([$userId]);
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
function resolve_default_layout(int $userId, string $format, ?string $pillarName = null): string
{
    if ($pillarName) {
        $stmt = db()->prepare('SELECT default_layout FROM content_pillars WHERE user_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$userId, $pillarName]);
        $pillarLayout = $stmt->fetchColumn();
        if ($pillarLayout) {
            return $pillarLayout;
        }
    }
    $stmt = db()->prepare('SELECT default_layout_single, default_layout_carousel FROM users WHERE id = ?');
    $stmt->execute([$userId]);
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
function resolve_default_palette(int $userId, string $format, ?string $pillarName = null): ?string
{
    if ($pillarName) {
        $stmt = db()->prepare('SELECT default_palette FROM content_pillars WHERE user_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$userId, $pillarName]);
        $pillarPalette = $stmt->fetchColumn();
        if ($pillarPalette) {
            return $pillarPalette;
        }
    }
    $stmt = db()->prepare('SELECT default_palette_single, default_palette_carousel FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $palette = $format === 'carousel' ? ($row['default_palette_carousel'] ?? null) : ($row['default_palette_single'] ?? null);
    return $palette ?: null;
}

function fetch_cta_library(int $userId): array
{
    $stmt = db()->prepare('SELECT id, text, funnel_stage FROM cta_library WHERE user_id = ? ORDER BY funnel_stage, text');
    $stmt->execute([$userId]);
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
