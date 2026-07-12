<?php

const ALL_POST_FORMATS = ['Single Image', 'Carousel', 'Text Post', 'Poll'];

// Poll is excluded from the default set — LinkedIn's Posts API (what
// this app uses to publish) has no endpoint for creating a real,
// votable poll, so "posting" a Poll-format row would only ever publish
// plain text under a misleading label. Users can still turn it on
// explicitly in Settings if they just want the text content out.
const DEFAULT_ENABLED_FORMATS = ['Single Image', 'Carousel', 'Text Post'];

const AI_PROVIDERS = ['gemini', 'claude', 'openai'];

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Shared thumbnail-grid markup for the Design Template picker — used by
// both New Post (static, one picker on the page) and Calendar Batch (one
// per review card, hence $fieldSuffix to keep radio group names unique
// per card). Content Studio builds its cards in JS instead, so it reads
// window.DESIGN_TEMPLATES (see pages/content_studio.php) rather than
// calling this. Requires includes/image_renderer.php to already be
// loaded (render_design_templates()) — every caller already requires it
// for actual rendering anyway.
function render_template_picker_html(string $selected = 'classic', string $fieldSuffix = ''): string
{
    $groupName = 'design_template' . $fieldSuffix;
    $html = '<div class="template-grid">';
    foreach (render_design_templates() as $id => $t) {
        $checked = $id === $selected;
        $html .= '<label class="template-option' . ($checked ? ' selected' : '') . '">'
            . '<input type="radio" name="' . h($groupName) . '" value="' . h($id) . '"' . ($checked ? ' checked' : '') . '>'
            . '<img src="' . h(app_path('assets/img/template-thumbs/' . $id . '.png')) . '" alt="' . h($t['name']) . '" loading="lazy">'
            . '<span>' . h($t['name']) . '</span>'
            . '</label>';
    }
    return $html . '</div>';
}

// Shared <option> markup for a Color Palette <select> — the 4 built-in
// presets plus every saved brand_palettes row, matching the format the
// "template" creative-JSON field takes (int 1-4, or "custom:{id}"). See
// pages/calendar_batch.php for the hand-written version of this same
// list that predates this helper. $selected accepts any of int 1-4,
// digit string, "custom:{id}", or null. $includeAuto adds a leading
// empty-value "Auto" option, for defaults where "no override" is valid
// (unlike per-post pickers, which always resolve to a concrete value).
function render_palette_select_options($selected, array $brandPalettes, bool $includeAuto = false): string
{
    $selected = $selected === null ? '' : (string) $selected;
    $html = '';
    if ($includeAuto) {
        $html .= '<option value=""' . ($selected === '' ? ' selected' : '') . '>Auto</option>';
    }
    $builtins = ['1' => 'Cream', '2' => 'Dark Green', '3' => 'Olive', '4' => 'Medium Green'];
    foreach ($builtins as $value => $label) {
        // Numeric-string array keys ('1'..'4') are auto-cast to int by
        // PHP, so $value here is an int — cast back before comparing
        // against $selected, which is always a string.
        $html .= '<option value="' . $value . '"' . ($selected === (string) $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    foreach ($brandPalettes as $bp) {
        $value = 'custom:' . $bp['id'];
        $html .= '<option value="' . h($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . h($bp['name']) . '</option>';
    }
    return $html;
}

// Display name for a "template" value ("1"-"4" or "custom:{id}"), for
// badges/labels — pairs with render_palette_select_options() above.
function palette_display_name(string $value, array $brandPalettes): string
{
    $builtins = ['1' => 'Cream', '2' => 'Dark Green', '3' => 'Olive', '4' => 'Medium Green'];
    if (isset($builtins[$value])) {
        return $builtins[$value];
    }
    if (preg_match('/^custom:(\d+)$/', $value, $m)) {
        foreach ($brandPalettes as $bp) {
            if ((int) $bp['id'] === (int) $m[1]) {
                return $bp['name'];
            }
        }
    }
    return $value;
}

// Validates a raw palette-select POST value against what "template"
// accepts: "" (no override), a built-in digit "1"-"4", or "custom:{id}"
// where {id} is a brand_palettes row owned by $userId. Returns the
// value unchanged if valid, or null (caller treats null as "clear the
// override") otherwise — mirrors the render_design_templates() lookup
// pattern used to validate the layout selects.
function validate_palette_select_value(int $userId, string $value): ?string
{
    if ($value === '') {
        return null;
    }
    if (preg_match('/^[1-4]$/', $value)) {
        return $value;
    }
    if (preg_match('/^custom:(\d+)$/', $value, $m) && fetch_brand_palette($userId, (int) $m[1])) {
        return $value;
    }
    return null;
}

// Accepts a bare numeric org ID ("12345"), a full URN
// ("urn:li:organization:12345"), or a LinkedIn company URL that uses
// the numeric ID form ("linkedin.com/company/12345/") — LinkedIn vanity
// names (e.g. "/company/microsoft/") can't be resolved to an ID without
// API access this app doesn't have, so those aren't accepted here.
// Returns null if no valid numeric ID could be extracted.
function normalize_organization_input(string $input): ?string
{
    $input = trim($input);
    if (preg_match('/^\d+$/', $input)) {
        return 'urn:li:organization:' . $input;
    }
    if (preg_match('/^urn:li:organization:(\d+)$/', $input, $m)) {
        return 'urn:li:organization:' . $m[1];
    }
    if (preg_match('#linkedin\.com/company/(\d+)#', $input, $m)) {
        return 'urn:li:organization:' . $m[1];
    }
    return null;
}

function get_enabled_formats(int $userId): array
{
    $stmt = db()->prepare('SELECT enabled_formats FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $raw = $stmt->fetchColumn();
    if (!$raw) {
        return DEFAULT_ENABLED_FORMATS;
    }
    $selected = array_map('trim', explode(',', $raw));
    return array_values(array_intersect(ALL_POST_FORMATS, $selected));
}

function set_enabled_formats(int $userId, array $formats): void
{
    $formats = array_values(array_intersect(ALL_POST_FORMATS, $formats));
    $stmt = db()->prepare('UPDATE users SET enabled_formats = ? WHERE id = ?');
    $stmt->execute([implode(',', $formats), $userId]);
}

function get_gemini_api_key(int $userId): ?string
{
    $stmt = db()->prepare('SELECT gemini_api_key FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $key = $stmt->fetchColumn();
    return $key ?: null;
}

function set_gemini_api_key(int $userId, ?string $key): void
{
    $key = trim((string) $key);
    $stmt = db()->prepare('UPDATE users SET gemini_api_key = ? WHERE id = ?');
    $stmt->execute([$key === '' ? null : $key, $userId]);
}

function get_claude_api_key(int $userId): ?string
{
    $stmt = db()->prepare('SELECT claude_api_key FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $key = $stmt->fetchColumn();
    return $key ?: null;
}

function set_claude_api_key(int $userId, ?string $key): void
{
    $key = trim((string) $key);
    $stmt = db()->prepare('UPDATE users SET claude_api_key = ? WHERE id = ?');
    $stmt->execute([$key === '' ? null : $key, $userId]);
}

function get_openai_api_key(int $userId): ?string
{
    $stmt = db()->prepare('SELECT openai_api_key FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $key = $stmt->fetchColumn();
    return $key ?: null;
}

function set_openai_api_key(int $userId, ?string $key): void
{
    $key = trim((string) $key);
    $stmt = db()->prepare('UPDATE users SET openai_api_key = ? WHERE id = ?');
    $stmt->execute([$key === '' ? null : $key, $userId]);
}

function get_ai_provider(int $userId): ?string
{
    $stmt = db()->prepare('SELECT ai_provider FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $provider = $stmt->fetchColumn();
    return in_array($provider, AI_PROVIDERS, true) ? $provider : null;
}

function set_ai_provider(int $userId, ?string $provider): void
{
    $provider = in_array($provider, AI_PROVIDERS, true) ? $provider : null;
    $stmt = db()->prepare('UPDATE users SET ai_provider = ? WHERE id = ?');
    $stmt->execute([$provider, $userId]);
}

// Reddit "script" OAuth app credentials (News Studio trend source, see
// includes/news_fetch.php reddit_fetch_subreddit_posts()) — a free,
// self-service app at reddit.com/prefs/apps, no approval wait. Stored
// per-user like the AI provider keys above.
function get_reddit_credentials(int $userId): ?array
{
    $stmt = db()->prepare('SELECT reddit_client_id, reddit_client_secret FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || !$row['reddit_client_id'] || !$row['reddit_client_secret']) {
        return null;
    }
    return ['client_id' => $row['reddit_client_id'], 'client_secret' => $row['reddit_client_secret']];
}

function set_reddit_credentials(int $userId, ?string $clientId, ?string $clientSecret): void
{
    $clientId = trim((string) $clientId);
    $clientSecret = trim((string) $clientSecret);
    $stmt = db()->prepare('UPDATE users SET reddit_client_id = ?, reddit_client_secret = ? WHERE id = ?');
    $stmt->execute([$clientId === '' ? null : $clientId, $clientSecret === '' ? null : $clientSecret, $userId]);
}

// Which font role ('heading' or 'body') the rendered footer *name* text
// uses — see includes/image_renderer.php render_footer_simple()/
// render_footer_with_photo(). Defaults to 'body' (today's behavior,
// unchanged for anyone who hasn't touched this Settings toggle).
function get_footer_font_role(int $userId): string
{
    $stmt = db()->prepare('SELECT footer_font_role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn();
    return $role === 'heading' ? 'heading' : 'body';
}

function set_footer_font_role(int $userId, string $role): void
{
    $role = $role === 'heading' ? 'heading' : 'body';
    $stmt = db()->prepare('UPDATE users SET footer_font_role = ? WHERE id = ?');
    $stmt->execute([$role, $userId]);
}

// Manual pixel-size override for the footer signature — a literal
// rendered pixel size (see schema.sql comment on this column), used as
// the base for Hook/Content/Single slides; the CTA/photo-footer slide
// keeps rendering proportionally larger, same ratio as the built-in
// default (see render_footer_with_photo()). NULL keeps the built-in
// default size.
function get_footer_name_size(int $userId): ?int
{
    $stmt = db()->prepare('SELECT footer_name_size FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $size = $stmt->fetchColumn();
    return $size ? (int) $size : null;
}

function set_footer_name_size(int $userId, ?int $size): void
{
    if ($size !== null) {
        $size = max(16, min(80, $size));
    }
    $stmt = db()->prepare('UPDATE users SET footer_name_size = ? WHERE id = ?');
    $stmt->execute([$size, $userId]);
}

// Per-user, per-format Design Template defaults, used by
// includes/post_helpers.php resolve_default_layout() to auto-assign a
// layout during bulk generation (Content Studio CSV upload, Content
// Calendar Generator) instead of requiring one pick per row. Callers
// are expected to validate $layout against render_design_templates()
// before calling the setters (pages/settings.php does, since only it
// has includes/image_renderer.php loaded at this point).
function get_default_layout_single(int $userId): ?string
{
    $stmt = db()->prepare('SELECT default_layout_single FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() ?: null;
}

function set_default_layout_single(int $userId, ?string $layout): void
{
    db()->prepare('UPDATE users SET default_layout_single = ? WHERE id = ?')->execute([$layout ?: null, $userId]);
}

function get_default_layout_carousel(int $userId): ?string
{
    $stmt = db()->prepare('SELECT default_layout_carousel FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() ?: null;
}

function set_default_layout_carousel(int $userId, ?string $layout): void
{
    db()->prepare('UPDATE users SET default_layout_carousel = ? WHERE id = ?')->execute([$layout ?: null, $userId]);
}

// Per-user, per-format Color Palette defaults — same idea as
// default_layout_single/carousel above, used by
// includes/post_helpers.php resolve_default_palette(). $palette is the
// same string a "template" creative-JSON value takes: a digit string
// ("1"-"4") or "custom:{id}" — pages/settings.php validates against the
// user's actual palette list before calling the setter.
function get_default_palette_single(int $userId): ?string
{
    $stmt = db()->prepare('SELECT default_palette_single FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() ?: null;
}

function set_default_palette_single(int $userId, ?string $palette): void
{
    db()->prepare('UPDATE users SET default_palette_single = ? WHERE id = ?')->execute([$palette ?: null, $userId]);
}

function get_default_palette_carousel(int $userId): ?string
{
    $stmt = db()->prepare('SELECT default_palette_carousel FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() ?: null;
}

function set_default_palette_carousel(int $userId, ?string $palette): void
{
    db()->prepare('UPDATE users SET default_palette_carousel = ? WHERE id = ?')->execute([$palette ?: null, $userId]);
}

function get_brand_brief(int $userId): ?string
{
    $stmt = db()->prepare('SELECT brand_brief FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $brief = $stmt->fetchColumn();
    return $brief !== false && trim((string) $brief) !== '' ? $brief : null;
}

function set_brand_brief(int $userId, ?string $brief): void
{
    $brief = trim((string) $brief);
    $stmt = db()->prepare('UPDATE users SET brand_brief = ? WHERE id = ?');
    $stmt->execute([$brief === '' ? null : $brief, $userId]);
}

function get_self_brief(int $userId): ?string
{
    $stmt = db()->prepare('SELECT self_brief FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $brief = $stmt->fetchColumn();
    return $brief !== false && trim((string) $brief) !== '' ? $brief : null;
}

function set_self_brief(int $userId, ?string $brief): void
{
    $brief = trim((string) $brief);
    $stmt = db()->prepare('UPDATE users SET self_brief = ? WHERE id = ?');
    $stmt->execute([$brief === '' ? null : $brief, $userId]);
}

// Picks brand_brief for company-category pillars (or when no pillar is
// selected — the common case) and self_brief for personal-category ones,
// so AI generation always uses the voice that actually matches the post.
function resolve_brief_for_pillar(int $userId, ?array $pillar): ?string
{
    if ($pillar && ($pillar['category'] ?? 'company') === 'personal') {
        return get_self_brief($userId);
    }
    return get_brand_brief($userId);
}

// Resolves which provider/key/model AI generation should use for
// $userId. Provider: the user's own preference, or AI_PROVIDER_DEFAULT
// if unset. API key: the user's own key for that provider; only falls
// back to the admin-shared *_API_KEY_DEFAULT (config.php) when the
// resolved provider is ALSO the site's default provider — a user who
// deliberately picked a non-default provider without their own key gets
// null (not silently billed to the admin's key for a provider they
// didn't confirm). Model is always the provider's config constant.
// Resolves the footer logo/photo for a rendered slide (see
// includes/image_renderer.php render_footer_with_photo()). $category is
// 'company' or 'personal', matching content_pillars.category — company
// posts use the uploaded logo, personal posts use the uploaded photo.
// Fallback chain: the workspace's own upload (per-workspace branding,
// added in Phase C) -> the old user-level upload (pre-Phase-C accounts
// that uploaded before workspaces existed) -> the app-wide
// assets/img/profile.* bundled default. Each tier only kicks in if the
// one before it is absent, so nothing changes for an account that never
// touches the new per-workspace upload.
function resolve_footer_image(int $userId, string $category, ?int $workspaceId = null): ?string
{
    $slot = $category === 'company' ? 'logo' : 'photo';
    if ($workspaceId !== null) {
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            $path = UPLOAD_DIR . "/{$userId}/workspace_{$workspaceId}/branding/{$slot}.{$ext}";
            if (is_file($path)) {
                return $path;
            }
        }
    }
    foreach (['png', 'jpg', 'jpeg'] as $ext) {
        $path = UPLOAD_DIR . "/{$userId}/branding/{$slot}.{$ext}";
        if (is_file($path)) {
            return $path;
        }
    }
    foreach (['png', 'jpg', 'jpeg'] as $ext) {
        $path = __DIR__ . "/../assets/img/profile.{$ext}";
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

// A separate slot from resolve_footer_image()'s logo/photo — those are
// always circle-cropped for the CTA footer avatar, which would mangle a
// rectangular wordmark. This one is drawn as-is (aspect preserved) in the
// top-left corner of every slide — see render_draw_logo(). No bundled
// default: absent means nothing is drawn, not a placeholder mark. Same
// per-workspace-first fallback chain as resolve_footer_image() above.
function resolve_brand_logo(int $userId, ?int $workspaceId = null): ?string
{
    if ($workspaceId !== null) {
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            $path = UPLOAD_DIR . "/{$userId}/workspace_{$workspaceId}/branding/brand_logo.{$ext}";
            if (is_file($path)) {
                return $path;
            }
        }
    }
    foreach (['png', 'jpg', 'jpeg'] as $ext) {
        $path = UPLOAD_DIR . "/{$userId}/branding/brand_logo.{$ext}";
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function resolve_ai_config(int $userId): array
{
    $provider = get_ai_provider($userId) ?: AI_PROVIDER_DEFAULT;

    $userKeys = [
        'gemini' => get_gemini_api_key($userId),
        'claude' => get_claude_api_key($userId),
        'openai' => get_openai_api_key($userId),
    ];
    $models = [
        'gemini' => GEMINI_MODEL,
        'claude' => CLAUDE_MODEL,
        'openai' => OPENAI_MODEL,
    ];

    $apiKey = $userKeys[$provider] ?? null;
    if ($apiKey === null && $provider === AI_PROVIDER_DEFAULT) {
        // Gemini intentionally has no admin-shared default — its free
        // tier is meant to be per-user, not pooled across every signup.
        if ($provider === 'claude' && CLAUDE_API_KEY_DEFAULT !== '') {
            $apiKey = CLAUDE_API_KEY_DEFAULT;
        } elseif ($provider === 'openai' && OPENAI_API_KEY_DEFAULT !== '') {
            $apiKey = OPENAI_API_KEY_DEFAULT;
        }
    }

    return ['provider' => $provider, 'api_key' => $apiKey, 'model' => $models[$provider] ?? null];
}

function redirect(string $path): void
{
    header('Location: ' . app_path($path));
    exit;
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function flash(string $key, ?string $message = null)
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $value;
}

// Builds a Mon–Sun month grid; $postsByDate is keyed by 'Y-m-d' with an
// ARRAY of posts per date (a day can have more than one scheduled post).
function build_calendar_grid(int $year, int $month, array $postsByDate): array
{
    $firstOfMonth = new DateTime("{$year}-{$month}-01");
    $daysInMonth  = (int) $firstOfMonth->format('t');
    $startWeekday = (int) $firstOfMonth->format('N'); // 1 (Mon) .. 7 (Sun)

    $grid = [];
    $week = array_fill(0, $startWeekday - 1, null);

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $week[] = ['day' => $day, 'date' => $date, 'posts' => $postsByDate[$date] ?? []];
        if (count($week) === 7) {
            $grid[] = $week;
            $week = [];
        }
    }
    if ($week) {
        while (count($week) < 7) {
            $week[] = null;
        }
        $grid[] = $week;
    }
    return $grid;
}
