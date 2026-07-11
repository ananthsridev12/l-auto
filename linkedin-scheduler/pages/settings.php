<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/zip_import.php';
require_once __DIR__ . '/../includes/image_renderer.php';

require_login();
$userId = current_user_id();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'formats') {
        set_enabled_formats($userId, $_POST['formats'] ?? []);
        flash('success', 'Post formats updated.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'tag_add') {
        $name = trim($_POST['tag_name'] ?? '');
        $urn = normalize_organization_input($_POST['tag_org_id'] ?? '');
        if ($name === '' || $urn === null) {
            flash('error', 'Enter a name and a valid numeric LinkedIn organization ID.');
            redirect('pages/settings.php');
        }
        $stmt = db()->prepare(
            'INSERT INTO tag_directory (user_id, display_name, target_urn) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE target_urn = VALUES(target_urn)'
        );
        $stmt->execute([$userId, $name, $urn]);
        flash('success', "\"{$name}\" added to your tag directory.");
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'tag_delete') {
        $stmt = db()->prepare('DELETE FROM tag_directory WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['tag_id'] ?? 0), $userId]);
        flash('success', 'Removed from tag directory.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'ai_provider') {
        set_ai_provider($userId, $_POST['ai_provider'] ?? '');
        set_gemini_api_key($userId, $_POST['gemini_api_key'] ?? '');
        set_claude_api_key($userId, $_POST['claude_api_key'] ?? '');
        set_openai_api_key($userId, $_POST['openai_api_key'] ?? '');
        flash('success', 'AI provider settings saved.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'brand_brief') {
        set_brand_brief($userId, $_POST['brand_brief'] ?? '');
        set_self_brief($userId, $_POST['self_brief'] ?? '');
        flash('success', 'Brand brief saved.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'seed_kb') {
        seed_default_knowledge_base($userId);
        flash('success', 'Starter personas, content pillars, and CTAs added — anything you already had was left untouched.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'persona_add') {
        $name = trim($_POST['persona_name'] ?? '');
        $desc = trim($_POST['persona_description'] ?? '');
        if ($name === '') {
            flash('error', 'Enter a persona name.');
            redirect('pages/settings.php');
        }
        $stmt = db()->prepare(
            'INSERT INTO personas (user_id, name, description) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description)'
        );
        $stmt->execute([$userId, $name, $desc]);
        flash('success', "Persona \"{$name}\" saved.");
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'persona_delete') {
        $stmt = db()->prepare('DELETE FROM personas WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['persona_id'] ?? 0), $userId]);
        flash('success', 'Persona removed.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'pillar_add') {
        $name = trim($_POST['pillar_name'] ?? '');
        $desc = trim($_POST['pillar_description'] ?? '');
        $category = ($_POST['pillar_category'] ?? '') === 'personal' ? 'personal' : 'company';
        $layout = $_POST['pillar_layout'] ?? '';
        $layout = array_key_exists($layout, render_design_templates()) ? $layout : null;
        $palette = validate_palette_select_value($userId, trim($_POST['pillar_palette'] ?? ''));
        if ($name === '') {
            flash('error', 'Enter a content pillar name.');
            redirect('pages/settings.php');
        }
        $stmt = db()->prepare(
            'INSERT INTO content_pillars (user_id, name, description, category, default_layout, default_palette) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description), category = VALUES(category), default_layout = VALUES(default_layout), default_palette = VALUES(default_palette)'
        );
        $stmt->execute([$userId, $name, $desc, $category, $layout, $palette]);
        flash('success', "Content pillar \"{$name}\" saved.");
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'pillar_delete') {
        $stmt = db()->prepare('DELETE FROM content_pillars WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['pillar_id'] ?? 0), $userId]);
        flash('success', 'Content pillar removed.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'default_layout_formats') {
        $templates = render_design_templates();
        $single = $_POST['design_template_single'] ?? '';
        $carousel = $_POST['design_template_carousel'] ?? '';
        set_default_layout_single($userId, array_key_exists($single, $templates) ? $single : null);
        set_default_layout_carousel($userId, array_key_exists($carousel, $templates) ? $carousel : null);
        set_default_palette_single($userId, validate_palette_select_value($userId, trim($_POST['default_palette_single'] ?? '')));
        set_default_palette_carousel($userId, validate_palette_select_value($userId, trim($_POST['default_palette_carousel'] ?? '')));
        flash('success', 'Default Design Templates updated.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'cta_add') {
        $text = trim($_POST['cta_text'] ?? '');
        $stage = $_POST['cta_funnel_stage'] ?? '';
        $stage = in_array($stage, ['Awareness', 'Consideration', 'Decision', 'Retention'], true) ? $stage : null;
        if ($text === '') {
            flash('error', 'Enter the CTA text.');
            redirect('pages/settings.php');
        }
        $stmt = db()->prepare('INSERT INTO cta_library (user_id, text, funnel_stage) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $text, $stage]);
        flash('success', 'CTA added to your library.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'cta_delete') {
        $stmt = db()->prepare('DELETE FROM cta_library WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['cta_id'] ?? 0), $userId]);
        flash('success', 'CTA removed.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'palette_add') {
        $name = trim($_POST['palette_name'] ?? '');
        $bg = trim($_POST['palette_bg'] ?? '');
        $text = trim($_POST['palette_text'] ?? '');
        $accent = trim($_POST['palette_accent'] ?? '') ?: null;
        $cta = trim($_POST['palette_cta'] ?? '') ?: null;
        $signature = trim($_POST['palette_signature'] ?? '') ?: null;
        if ($name === '' || !preg_match('/^#[0-9A-Fa-f]{6}$/', $bg) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $text)) {
            flash('error', 'Enter a palette name and valid Background/Text colors.');
            redirect('pages/settings.php');
        }
        $stmt = db()->prepare(
            'INSERT INTO brand_palettes (user_id, name, bg_color, text_color, accent_color, cta_color, signature_color) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE bg_color = VALUES(bg_color), text_color = VALUES(text_color), accent_color = VALUES(accent_color), cta_color = VALUES(cta_color), signature_color = VALUES(signature_color)'
        );
        $stmt->execute([$userId, $name, $bg, $text, $accent, $cta, $signature]);
        flash('success', "Palette \"{$name}\" saved.");
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'palette_delete') {
        $paletteId = (int) ($_POST['palette_id'] ?? 0);
        $palette = fetch_brand_palette($userId, $paletteId);
        if ($palette && $palette['background_image_path']) {
            @unlink($palette['background_image_path']);
        }
        $stmt = db()->prepare('DELETE FROM brand_palettes WHERE id = ? AND user_id = ?');
        $stmt->execute([$paletteId, $userId]);
        flash('success', 'Palette removed.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'palette_set_default') {
        set_default_brand_palette($userId, (int) ($_POST['palette_id'] ?? 0));
        flash('success', 'Default palette updated.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'palette_bg_image_upload') {
        $paletteId = (int) ($_POST['palette_id'] ?? 0);
        $palette = fetch_brand_palette($userId, $paletteId);
        if (!$palette) {
            flash('error', 'Palette not found.');
            redirect('pages/settings.php');
        }
        if (empty($_FILES['palette_bg_image']['tmp_name']) || $_FILES['palette_bg_image']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Choose an image file to upload.');
            redirect('pages/settings.php');
        }
        $contents = file_get_contents($_FILES['palette_bg_image']['tmp_name']);
        $mime = zip_sniff_image_mime($contents);
        if (!in_array($mime, ALLOWED_SLIDE_MIME, true)) {
            flash('error', 'Background image must be a PNG or JPEG file.');
            redirect('pages/settings.php');
        }
        $ext = $mime === 'image/png' ? 'png' : 'jpg';
        $dir = UPLOAD_DIR . "/{$userId}/palette_backgrounds";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if ($palette['background_image_path']) {
            @unlink($palette['background_image_path']);
        }
        $path = "{$dir}/{$paletteId}.{$ext}";
        file_put_contents($path, $contents);
        db()->prepare('UPDATE brand_palettes SET background_image_path = ? WHERE id = ? AND user_id = ?')->execute([$path, $paletteId, $userId]);
        flash('success', "Background image for \"{$palette['name']}\" updated.");
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'palette_bg_image_remove') {
        $paletteId = (int) ($_POST['palette_id'] ?? 0);
        $palette = fetch_brand_palette($userId, $paletteId);
        if ($palette && $palette['background_image_path']) {
            @unlink($palette['background_image_path']);
            db()->prepare('UPDATE brand_palettes SET background_image_path = NULL WHERE id = ? AND user_id = ?')->execute([$paletteId, $userId]);
        }
        flash('success', 'Background image removed.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'footer_image_upload') {
        $slot = ($_POST['image_slot'] ?? '') === 'logo' ? 'logo' : 'photo';
        if (empty($_FILES['footer_image']['tmp_name']) || $_FILES['footer_image']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Choose an image file to upload.');
            redirect('pages/settings.php');
        }
        $contents = file_get_contents($_FILES['footer_image']['tmp_name']);
        $mime = zip_sniff_image_mime($contents);
        if (!in_array($mime, ALLOWED_SLIDE_MIME, true)) {
            flash('error', 'Image must be a PNG or JPEG file.');
            redirect('pages/settings.php');
        }
        $ext = $mime === 'image/png' ? 'png' : 'jpg';
        $dir = UPLOAD_DIR . "/{$userId}/branding";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        foreach (['png', 'jpg', 'jpeg'] as $existingExt) {
            @unlink("{$dir}/{$slot}.{$existingExt}");
        }
        file_put_contents("{$dir}/{$slot}.{$ext}", $contents);
        flash('success', ucfirst($slot) . ' updated.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'footer_image_remove') {
        $slot = ($_POST['image_slot'] ?? '') === 'logo' ? 'logo' : 'photo';
        $dir = UPLOAD_DIR . "/{$userId}/branding";
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            @unlink("{$dir}/{$slot}.{$ext}");
        }
        flash('success', ucfirst($slot) . ' removed.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'brand_logo_upload') {
        if (empty($_FILES['brand_logo']['tmp_name']) || $_FILES['brand_logo']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Choose an image file to upload.');
            redirect('pages/settings.php');
        }
        $contents = file_get_contents($_FILES['brand_logo']['tmp_name']);
        $mime = zip_sniff_image_mime($contents);
        if (!in_array($mime, ALLOWED_SLIDE_MIME, true)) {
            flash('error', 'Logo must be a PNG or JPEG file.');
            redirect('pages/settings.php');
        }
        $ext = $mime === 'image/png' ? 'png' : 'jpg';
        $dir = UPLOAD_DIR . "/{$userId}/branding";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        foreach (['png', 'jpg', 'jpeg'] as $existingExt) {
            @unlink("{$dir}/brand_logo.{$existingExt}");
        }
        file_put_contents("{$dir}/brand_logo.{$ext}", $contents);
        flash('success', 'Brand logo updated.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'brand_logo_remove') {
        $dir = UPLOAD_DIR . "/{$userId}/branding";
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            @unlink("{$dir}/brand_logo.{$ext}");
        }
        flash('success', 'Brand logo removed.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'font_add') {
        $fontName = trim($_POST['font_name'] ?? '');
        if ($fontName === '') {
            flash('error', 'Enter a name for this font.');
            redirect('pages/settings.php');
        }
        if (
            empty($_FILES['font_regular']['tmp_name']) || $_FILES['font_regular']['error'] !== UPLOAD_ERR_OK
            || empty($_FILES['font_bold']['tmp_name']) || $_FILES['font_bold']['error'] !== UPLOAD_ERR_OK
        ) {
            flash('error', 'Upload both a Regular and a Bold font file (.ttf or .otf).');
            redirect('pages/settings.php');
        }
        $regularContents = file_get_contents($_FILES['font_regular']['tmp_name']);
        $boldContents = file_get_contents($_FILES['font_bold']['tmp_name']);
        $regularExt = sniff_font_ext($regularContents);
        $boldExt = sniff_font_ext($boldContents);
        if (!$regularExt || !$boldExt) {
            flash('error', 'Both files must be valid TTF or OTF fonts.');
            redirect('pages/settings.php');
        }
        $dir = UPLOAD_DIR . "/{$userId}/fonts";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $slug = bin2hex(random_bytes(8));
        $regularPath = "{$dir}/{$slug}_regular.{$regularExt}";
        $boldPath = "{$dir}/{$slug}_bold.{$boldExt}";
        file_put_contents($regularPath, $regularContents);
        file_put_contents($boldPath, $boldContents);
        try {
            $stmt = db()->prepare('INSERT INTO brand_fonts (user_id, name, regular_path, bold_path) VALUES (?, ?, ?, ?)');
            $stmt->execute([$userId, $fontName, $regularPath, $boldPath]);
        } catch (PDOException $e) {
            @unlink($regularPath);
            @unlink($boldPath);
            if ((string) $e->getCode() === '23000') {
                flash('error', "A font named \"{$fontName}\" already exists — remove it first or pick a different name.");
                redirect('pages/settings.php');
            }
            throw $e;
        }
        flash('success', "Font \"{$fontName}\" uploaded.");
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'font_add_from_site') {
        $familyName = trim($_POST['family'] ?? '');
        $siteFont = null;
        foreach (scan_site_fonts() as $sf) {
            if (strcasecmp($sf['name'], $familyName) === 0) {
                $siteFont = $sf;
                break;
            }
        }
        if (!$siteFont) {
            flash('error', 'That font is no longer in the site library.');
            redirect('pages/settings.php');
        }
        try {
            $stmt = db()->prepare('INSERT INTO brand_fonts (user_id, name, regular_path, bold_path) VALUES (?, ?, ?, ?)');
            $stmt->execute([$userId, $siteFont['name'], $siteFont['regular'], $siteFont['bold']]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                flash('error', "\"{$siteFont['name']}\" is already in your fonts.");
                redirect('pages/settings.php');
            }
            throw $e;
        }
        flash('success', "\"{$siteFont['name']}\" added to your fonts.");
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'font_delete') {
        $fontId = (int) ($_POST['font_id'] ?? 0);
        $font = fetch_brand_font($userId, $fontId);
        if ($font) {
            // Only unlink files inside this user's own upload directory —
            // a font added via "font_add_from_site" points at the shared
            // assets/fonts/ files instead, which must never be deleted
            // out from under every other user who might use them too.
            $ownDir = UPLOAD_DIR . "/{$userId}/fonts/";
            if (str_starts_with($font['regular_path'], $ownDir)) {
                @unlink($font['regular_path']);
            }
            if (str_starts_with($font['bold_path'], $ownDir)) {
                @unlink($font['bold_path']);
            }
            db()->prepare('DELETE FROM brand_fonts WHERE id = ? AND user_id = ?')->execute([$fontId, $userId]);
        }
        flash('success', 'Font removed.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'font_set_role') {
        $fontId = (int) ($_POST['font_id'] ?? 0);
        $role = in_array($_POST['role'] ?? '', ['body', 'footer'], true) ? $_POST['role'] : 'heading';
        if (!fetch_brand_font($userId, $fontId)) {
            flash('error', 'Font not found.');
            redirect('pages/settings.php');
        }
        if ($role === 'heading') {
            set_heading_font($userId, $fontId);
        } elseif ($role === 'body') {
            set_body_font($userId, $fontId);
        } else {
            set_footer_font($userId, $fontId);
        }
        $roleLabel = $role === 'footer' ? 'Signature' : ucfirst($role);
        flash('success', "{$roleLabel} font updated.");
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'font_clear_role') {
        $role = in_array($_POST['role'] ?? '', ['body', 'footer'], true) ? $_POST['role'] : 'heading';
        if ($role === 'heading') {
            set_heading_font($userId, null);
        } elseif ($role === 'body') {
            set_body_font($userId, null);
        } else {
            set_footer_font($userId, null);
        }
        $roleLabel = $role === 'footer' ? 'Signature' : ucfirst($role);
        $resetTo = $role === 'footer' ? 'the Heading/Body toggle' : 'default (Inter)';
        flash('success', "{$roleLabel} font reset to {$resetTo}.");
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'footer_font_role') {
        set_footer_font_role($userId, $_POST['footer_font_role'] ?? 'body');
        flash('success', 'Footer name font updated.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'footer_name_style') {
        $sizeRaw = trim($_POST['footer_name_size'] ?? '');
        set_footer_name_size($userId, $sizeRaw !== '' ? (int) $sizeRaw : null);
        flash('success', 'Signature size updated.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'footer_name_style_reset') {
        set_footer_name_size($userId, null);
        flash('success', 'Signature size reset to automatic.');
        redirect('pages/settings.php');
    }

    $name = trim($_POST['name'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';

    $stmt = db()->prepare('UPDATE users SET name = ? WHERE id = ?');
    $stmt->execute([$name, $userId]);

    if ($newPassword !== '') {
        if (strlen($newPassword) < 8) {
            flash('error', 'New password must be at least 8 characters.');
            redirect('pages/settings.php');
        }
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    }

    flash('success', 'Settings saved.');
    redirect('pages/settings.php');
}

$enabledFormats = get_enabled_formats($userId);
$tagDirectory = fetch_tag_directory($userId);
$geminiKey = get_gemini_api_key($userId);
$claudeKey = get_claude_api_key($userId);
$openaiKey = get_openai_api_key($userId);
$aiProvider = get_ai_provider($userId) ?: AI_PROVIDER_DEFAULT;
$brandBrief = get_brand_brief($userId);
$selfBrief = get_self_brief($userId);
$personas = fetch_personas($userId);
$contentPillars = fetch_content_pillars($userId);
$defaultLayoutSingle = get_default_layout_single($userId);
$defaultLayoutCarousel = get_default_layout_carousel($userId);
$defaultPaletteSingle = get_default_palette_single($userId);
$defaultPaletteCarousel = get_default_palette_carousel($userId);
$ctaLibrary = fetch_cta_library($userId);
$funnelStages = ['Awareness', 'Consideration', 'Decision', 'Retention'];
$brandPalettes = fetch_brand_palettes($userId);
$brandFonts = fetch_brand_fonts($userId);
$headingFontId = (int) (fetch_heading_font($userId)['id'] ?? 0);
$bodyFontId = (int) (fetch_body_font($userId)['id'] ?? 0);
$footerFontId = (int) (fetch_footer_font($userId)['id'] ?? 0);
$ownedFontNames = array_map(fn ($bf) => strtolower($bf['name']), $brandFonts);
$siteFonts = array_filter(scan_site_fonts(), fn ($sf) => !in_array(strtolower($sf['name']), $ownedFontNames, true));
$footerFontRole = get_footer_font_role($userId);
$footerNameSize = get_footer_name_size($userId);

$footerImages = [];
foreach (['logo', 'photo'] as $slot) {
    $footerImages[$slot] = null;
    foreach (['png', 'jpg', 'jpeg'] as $ext) {
        $path = UPLOAD_DIR . "/{$userId}/branding/{$slot}.{$ext}";
        if (is_file($path)) {
            $footerImages[$slot] = slide_public_url($path);
            break;
        }
    }
}

$brandLogoUrl = null;
$brandLogoPath = resolve_brand_logo($userId);
if ($brandLogoPath) {
    $brandLogoUrl = slide_public_url($brandLogoPath);
}

$pageTitle  = 'Settings';
$activePage = 'settings';
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>Settings</h1></div>

<section class="card">
  <h2>Profile</h2>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <label>Name
      <input type="text" name="name" value="<?= h($user['name']) ?>">
    </label>
    <label>Email
      <input type="email" value="<?= h($user['email']) ?>" disabled>
    </label>
    <label>New Password <span class="muted">(leave blank to keep current)</span>
      <input type="password" name="new_password" minlength="8">
    </label>
    <button type="submit" class="btn-primary">Save</button>
  </form>
</section>

<section class="card">
  <h2>LinkedIn Accounts</h2>
  <p class="muted">Manage which personal profile and Company Pages are connected from the <a href="<?= h(app_path('pages/accounts.php')) ?>">Accounts</a> page.</p>
</section>

<section class="card">
  <h2>Post Formats</h2>
  <p class="muted">Only checked formats will auto-schedule from CSV import or be schedulable from the post editor. Anything else lands in Drafts instead. Poll is unchecked by default because LinkedIn's API has no way to actually publish a real poll — a "Poll" post would otherwise just go out as plain text under a misleading label.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="formats">
    <?php foreach (ALL_POST_FORMATS as $fmt): ?>
      <label class="checkbox-row">
        <input type="checkbox" name="formats[]" value="<?= h($fmt) ?>" <?= in_array($fmt, $enabledFormats, true) ? 'checked' : '' ?>>
        <?= h($fmt) ?>
        <?= $fmt === 'Poll' ? '<span class="muted">(cannot actually publish as a poll — see note above)</span>' : '' ?>
      </label>
    <?php endforeach; ?>
    <button type="submit" class="btn-primary">Save Formats</button>
  </form>
</section>

<section class="card">
  <h2>AI Provider</h2>
  <p class="muted">Used by <a href="<?= h(app_path('pages/content_studio.php')) ?>">Content Studio</a> and New Post's "Generate with AI" to write captions and slide copy when you haven't written them yourself. Pick which provider to use and paste your key for it — only the selected provider's key matters. Gemini has a free tier (get a key at <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a>); Claude and OpenAI are paid per-call unless this site has a shared key configured for them, in which case leaving your own key blank will use that instead.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="ai_provider">
    <label class="checkbox-row"><input type="radio" name="ai_provider" value="gemini" <?= $aiProvider === 'gemini' ? 'checked' : '' ?>> Gemini</label>
    <label>Gemini API Key
      <input type="password" name="gemini_api_key" value="<?= h($geminiKey ?? '') ?>" placeholder="AIza..." autocomplete="off">
    </label>
    <label class="checkbox-row"><input type="radio" name="ai_provider" value="claude" <?= $aiProvider === 'claude' ? 'checked' : '' ?>> Claude</label>
    <label>Claude API Key
      <input type="password" name="claude_api_key" value="<?= h($claudeKey ?? '') ?>" placeholder="sk-ant-..." autocomplete="off">
    </label>
    <label class="checkbox-row"><input type="radio" name="ai_provider" value="openai" <?= $aiProvider === 'openai' ? 'checked' : '' ?>> OpenAI</label>
    <label>OpenAI API Key
      <input type="password" name="openai_api_key" value="<?= h($openaiKey ?? '') ?>" placeholder="sk-..." autocomplete="off">
    </label>
    <button type="submit" class="btn-primary">Save AI Provider</button>
  </form>
</section>

<section class="card">
  <h2>Brand Brief &amp; Self Brief</h2>
  <p class="muted">Brand Brief covers company-related posts (services, case studies, industry expertise). Self Brief is the personal-voice counterpart used for personal content pillars (achievements, opinions, life events) — see the Company/Personal tag on each pillar below. Both are automatically added as context to every AI generation call, so you don't have to retype them each time.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="brand_brief">
    <label>Brand Brief
      <textarea name="brand_brief" rows="4" placeholder="e.g. We sell predictive-maintenance sensors to mid-size manufacturing plants. Voice: direct, data-driven, not salesy."><?= h($brandBrief ?? '') ?></textarea>
    </label>
    <label>Self Brief
      <textarea name="self_brief" rows="4" placeholder="e.g. I'm a reliability engineer turned founder. Voice: candid, a bit informal, share real lessons not just wins."><?= h($selfBrief ?? '') ?></textarea>
    </label>
    <button type="submit" class="btn-primary">Save Briefs</button>
  </form>
</section>

<section class="card">
  <h2>Brand Palettes</h2>
  <p class="muted">Your own colors for rendered post images, selectable as a template alongside the 4 built-in presets when generating content. Background and Text are required; Accent, CTA, and Signature colors are optional — leave "Auto-generate" checked to derive them automatically with guaranteed-readable contrast. Signature specifically controls the footer name text — it switches along with whichever palette a post uses, so it stays consistent with that palette's own colors instead of a single fixed color that might clash with your other palettes. Each palette can also have its own background photo (upload it square, matching the post's 1:1 shape, for a clean fit — off-square images are still center-cropped automatically) — select "Image" as the Background when generating a post using that palette. Your palette's Background color is drawn as a semi-transparent tint over the photo so text stays readable.</p>
  <?php if ($brandPalettes): ?>
    <?php foreach ($brandPalettes as $bp): ?>
      <div class="account-row">
        <div class="account-info">
          <?php if ($bp['background_image_path']): ?>
            <img src="<?= h(slide_public_url($bp['background_image_path'])) ?>" style="width:32px; height:32px; object-fit:cover; border-radius:4px; border:1px solid #0002;">
          <?php endif; ?>
          <span><?= h($bp['name']) ?></span>
          <?php if ($bp['is_default']): ?><span class="badge badge-active">Default</span><?php endif; ?>
          <span class="palette-swatches">
            <span title="Background <?= h($bp['bg_color']) ?>" style="background:<?= h($bp['bg_color']) ?>"></span>
            <span title="Text <?= h($bp['text_color']) ?>" style="background:<?= h($bp['text_color']) ?>"></span>
            <?php if ($bp['accent_color']): ?><span title="Accent <?= h($bp['accent_color']) ?>" style="background:<?= h($bp['accent_color']) ?>"></span><?php endif; ?>
            <?php if ($bp['cta_color']): ?><span title="CTA <?= h($bp['cta_color']) ?>" style="background:<?= h($bp['cta_color']) ?>"></span><?php endif; ?>
            <?php if ($bp['signature_color']): ?><span title="Signature <?= h($bp['signature_color']) ?>" style="background:<?= h($bp['signature_color']) ?>"></span><?php endif; ?>
          </span>
        </div>
        <div class="inline-form">
          <button type="button" class="btn-tiny palette-edit-btn"
            data-name="<?= h($bp['name']) ?>"
            data-bg="<?= h($bp['bg_color']) ?>"
            data-text="<?= h($bp['text_color']) ?>"
            data-accent="<?= h($bp['accent_color'] ?? '') ?>"
            data-cta="<?= h($bp['cta_color'] ?? '') ?>"
            data-signature="<?= h($bp['signature_color'] ?? '') ?>">Edit</button>
          <?php if (!$bp['is_default']): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($token) ?>">
              <input type="hidden" name="form" value="palette_set_default">
              <input type="hidden" name="palette_id" value="<?= (int) $bp['id'] ?>">
              <button type="submit" class="btn-tiny">Set Default</button>
            </form>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="form" value="palette_bg_image_upload">
            <input type="hidden" name="palette_id" value="<?= (int) $bp['id'] ?>">
            <input type="file" name="palette_bg_image" accept="image/png,image/jpeg" required style="max-width:160px;">
            <button type="submit" class="btn-tiny"><?= $bp['background_image_path'] ? 'Replace' : 'Upload' ?> Background</button>
          </form>
          <?php if ($bp['background_image_path']): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($token) ?>">
              <input type="hidden" name="form" value="palette_bg_image_remove">
              <input type="hidden" name="palette_id" value="<?= (int) $bp['id'] ?>">
              <button type="submit" class="btn-tiny btn-danger">Remove Background</button>
            </form>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Remove this palette?');">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="form" value="palette_delete">
            <input type="hidden" name="palette_id" value="<?= (int) $bp['id'] ?>">
            <button type="submit" class="btn-tiny btn-danger">Remove</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No custom palettes yet — the 4 built-in presets are used automatically.</p>
  <?php endif; ?>
  <form method="post" class="stacked-form" id="paletteForm" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="palette_add">
    <label>Name
      <input type="text" name="palette_name" id="palette_name" placeholder="e.g. My Brand" required>
    </label>
    <label>Background
      <span class="color-field"><input type="color" name="palette_bg" id="palette_bg" value="#FBF5DD"><input type="text" class="hex-input" data-for="palette_bg" value="#FBF5DD" maxlength="7"></span>
    </label>
    <label>Text
      <span class="color-field"><input type="color" name="palette_text" id="palette_text" value="#0D530E"><input type="text" class="hex-input" data-for="palette_text" value="#0D530E" maxlength="7"></span>
    </label>
    <label class="checkbox-row"><input type="checkbox" class="auto-toggle" data-target="palette_accent" checked> Auto-generate Accent</label>
    <label>Accent
      <span class="color-field"><input type="color" name="palette_accent" id="palette_accent" value="#E7E1B1" disabled><input type="text" class="hex-input" data-for="palette_accent" value="#E7E1B1" maxlength="7" disabled></span>
    </label>
    <label class="checkbox-row"><input type="checkbox" class="auto-toggle" data-target="palette_cta" checked> Auto-generate CTA color</label>
    <label>CTA
      <span class="color-field"><input type="color" name="palette_cta" id="palette_cta" value="#0D530E" disabled><input type="text" class="hex-input" data-for="palette_cta" value="#0D530E" maxlength="7" disabled></span>
    </label>
    <label class="checkbox-row"><input type="checkbox" class="auto-toggle" data-target="palette_signature" checked> Auto-generate Signature color</label>
    <label>Signature
      <span class="color-field"><input type="color" name="palette_signature" id="palette_signature" value="#0D530E" disabled><input type="text" class="hex-input" data-for="palette_signature" value="#0D530E" maxlength="7" disabled></span>
    </label>
    <button type="submit" class="btn-secondary" id="paletteSubmitBtn">Add Palette</button>
    <button type="button" class="btn-tiny" id="paletteCancelEdit" style="display:none; align-self:flex-start;">Cancel edit</button>
  </form>
  <p class="muted" style="margin-top:8px;">Click "Edit" on a palette above to load its current colors into this form and update it in place — submitting a name that matches an existing palette always updates that palette rather than creating a duplicate.</p>
</section>

<section class="card">
  <h2>Footer Images</h2>
  <p class="muted">Shown in the circular footer on the last (CTA) slide of a carousel. Logo is used for company-category posts, Photo for personal-category posts (see the Company/Personal tag on Content Pillars below). Falls back to a default image until you upload your own.</p>
  <?php foreach (['logo' => 'Logo (company posts)', 'photo' => 'Photo (personal posts)'] as $slot => $label): ?>
    <div class="account-row">
      <div class="account-info">
        <?php if ($footerImages[$slot]): ?>
          <img src="<?= h($footerImages[$slot]) ?>" style="width:48px; height:48px; object-fit:cover; border-radius:50%;">
        <?php endif; ?>
        <span><?= h($label) ?></span>
        <span class="muted"><?= $footerImages[$slot] ? 'Set' : 'Using default' ?></span>
      </div>
      <div class="inline-form">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="footer_image_upload">
          <input type="hidden" name="image_slot" value="<?= h($slot) ?>">
          <input type="file" name="footer_image" accept="image/png,image/jpeg" required>
          <button type="submit" class="btn-tiny">Upload</button>
        </form>
        <?php if ($footerImages[$slot]): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="form" value="footer_image_remove">
            <input type="hidden" name="image_slot" value="<?= h($slot) ?>">
            <button type="submit" class="btn-tiny btn-danger">Remove</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>

<section class="card">
  <h2>Brand Logo</h2>
  <p class="muted">Shown top-left on every generated slide, across every Design Template and Color Palette. Aspect ratio is preserved (not cropped) — a transparent-background PNG wordmark works best. No logo means nothing is drawn.</p>
  <div class="account-row">
    <div class="account-info">
      <?php if ($brandLogoUrl): ?>
        <img src="<?= h($brandLogoUrl) ?>" style="max-width:120px; max-height:48px; object-fit:contain;">
      <?php endif; ?>
      <span class="muted"><?= $brandLogoUrl ? 'Set' : 'Not set' ?></span>
    </div>
    <div class="inline-form">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="form" value="brand_logo_upload">
        <input type="file" name="brand_logo" accept="image/png,image/jpeg" required>
        <button type="submit" class="btn-tiny">Upload</button>
      </form>
      <?php if ($brandLogoUrl): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="brand_logo_remove">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="card">
  <h2>Brand Fonts</h2>
  <p class="muted">Upload your own typefaces (Regular + Bold, .ttf or .otf), or add one already on the server below without uploading — a library to pick from, not a single active font. Assign one to <strong>Heading</strong> (headline text), one to <strong>Body</strong> (body text, numbered points, CTA banner, counter), and optionally one to <strong>Signature</strong> (the footer name) independently. Leave any unassigned to fall back to the next rule — Signature falls back to the Heading/Body toggle below, Heading/Body fall back to the built-in Inter.</p>
  <?php if ($brandFonts): ?>
    <?php foreach ($brandFonts as $bf): ?>
      <?php $isHeading = (int) $bf['id'] === $headingFontId; $isBody = (int) $bf['id'] === $bodyFontId; $isFooter = (int) $bf['id'] === $footerFontId; ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($bf['name']) ?></span>
          <?php if ($isHeading): ?><span class="badge badge-active">Heading</span><?php endif; ?>
          <?php if ($isBody): ?><span class="badge badge-active">Body</span><?php endif; ?>
          <?php if ($isFooter): ?><span class="badge badge-active">Signature</span><?php endif; ?>
        </div>
        <div class="inline-form">
          <?php if (!$isHeading): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($token) ?>">
              <input type="hidden" name="form" value="font_set_role">
              <input type="hidden" name="role" value="heading">
              <input type="hidden" name="font_id" value="<?= (int) $bf['id'] ?>">
              <button type="submit" class="btn-tiny">Use for Heading</button>
            </form>
          <?php endif; ?>
          <?php if (!$isBody): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($token) ?>">
              <input type="hidden" name="form" value="font_set_role">
              <input type="hidden" name="role" value="body">
              <input type="hidden" name="font_id" value="<?= (int) $bf['id'] ?>">
              <button type="submit" class="btn-tiny">Use for Body</button>
            </form>
          <?php endif; ?>
          <?php if (!$isFooter): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($token) ?>">
              <input type="hidden" name="form" value="font_set_role">
              <input type="hidden" name="role" value="footer">
              <input type="hidden" name="font_id" value="<?= (int) $bf['id'] ?>">
              <button type="submit" class="btn-tiny">Use for Signature</button>
            </form>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Remove this font?');">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="form" value="font_delete">
            <input type="hidden" name="font_id" value="<?= (int) $bf['id'] ?>">
            <button type="submit" class="btn-tiny btn-danger">Remove</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No custom fonts yet — Inter is used for both roles.</p>
  <?php endif; ?>
  <?php if ($headingFontId || $bodyFontId || $footerFontId): ?>
    <div class="inline-form" style="margin-top:8px;">
      <?php if ($headingFontId): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="font_clear_role">
          <input type="hidden" name="role" value="heading">
          <button type="submit" class="btn-tiny btn-danger">Reset Heading to Inter</button>
        </form>
      <?php endif; ?>
      <?php if ($bodyFontId): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="font_clear_role">
          <input type="hidden" name="role" value="body">
          <button type="submit" class="btn-tiny btn-danger">Reset Body to Inter</button>
        </form>
      <?php endif; ?>
      <?php if ($footerFontId): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="font_clear_role">
          <input type="hidden" name="role" value="footer">
          <button type="submit" class="btn-tiny btn-danger">Reset Signature to Heading/Body toggle</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($siteFonts): ?>
    <div class="mspec" style="margin-top:16px;">
      <div class="mspec-title">Already on the server (assets/fonts/) — add without uploading</div>
      <?php foreach ($siteFonts as $sf): ?>
        <div class="account-row">
          <div class="account-info"><span><?= h($sf['name']) ?></span></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="form" value="font_add_from_site">
            <input type="hidden" name="family" value="<?= h($sf['name']) ?>">
            <button type="submit" class="btn-tiny">Add to My Fonts</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" class="stacked-form" style="margin-top:16px;" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="font_add">
    <label>Name
      <input type="text" name="font_name" placeholder="e.g. My Brand Font" required>
    </label>
    <label>Regular weight <span class="muted">(.ttf or .otf)</span>
      <input type="file" name="font_regular" accept=".ttf,.otf,font/ttf,font/otf" required>
    </label>
    <label>Bold weight <span class="muted">(.ttf or .otf)</span>
      <input type="file" name="font_bold" accept=".ttf,.otf,font/ttf,font/otf" required>
    </label>
    <button type="submit" class="btn-secondary">Upload Font</button>
  </form>

  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="footer_font_role">
    <label>Footer name font <span class="muted">(used only when no dedicated Signature font is assigned above)</span>
      <select name="footer_font_role">
        <option value="body" <?= $footerFontRole === 'body' ? 'selected' : '' ?>>Body font</option>
        <option value="heading" <?= $footerFontRole === 'heading' ? 'selected' : '' ?>>Heading font</option>
      </select>
    </label>
    <button type="submit" class="btn-secondary">Save</button>
  </form>

  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="footer_name_style">
    <label>Signature size <span class="muted">(px, applies to Hook/Content/Single slides — the CTA slide's signature stays proportionally larger, same as today. For Signature color, see Brand Palettes above — it's set per palette so it stays in sync with whichever palette a post uses.)</span>
      <input type="number" name="footer_name_size" min="16" max="80" placeholder="Auto (33px)" value="<?= h($footerNameSize !== null ? (string) $footerNameSize : '') ?>">
    </label>
    <button type="submit" class="btn-secondary">Save</button>
  </form>
  <?php if ($footerNameSize !== null): ?>
    <form method="post" style="margin-top:8px;">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="form" value="footer_name_style_reset">
      <button type="submit" class="btn-tiny btn-danger">Reset Signature size to Auto</button>
    </form>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Personas, Pillars &amp; CTAs</h2>
  <p class="muted">Every new account starts with a generic starter set of personas, content pillars, and CTAs (editable/removable below). If you deleted them or never got them, load them again any time — this only adds what's missing, it never duplicates.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="seed_kb">
    <button type="submit" class="btn-secondary">Load Starter Content</button>
  </form>
</section>

<section class="card">
  <h2>Personas</h2>
  <p class="muted">Target audiences you write for. Pick one from New Post's "Generate with AI" panel instead of retyping who the post is for.</p>
  <?php if ($personas): ?>
    <?php foreach ($personas as $p): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($p['name']) ?></span>
          <span class="muted"><?= h(mb_strimwidth($p['description'] ?? '', 0, 80, '…')) ?></span>
        </div>
        <form method="post" onsubmit="return confirm('Remove this persona?');">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="persona_delete">
          <input type="hidden" name="persona_id" value="<?= (int) $p['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No personas added yet.</p>
  <?php endif; ?>
  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="persona_add">
    <label>Name
      <input type="text" name="persona_name" placeholder="e.g. Plant Reliability Manager" required>
    </label>
    <label>Description <span class="muted">(optional — pain points, goals, what they care about)</span>
      <textarea name="persona_description" rows="2"></textarea>
    </label>
    <button type="submit" class="btn-secondary">Add Persona</button>
  </form>
</section>

<section class="card">
  <h2>Content Pillars</h2>
  <p class="muted">The recurring themes you post about. Pick one from New Post's "Generate with AI" panel to keep content on-strategy.</p>
  <?php if ($contentPillars): ?>
    <?php foreach ($contentPillars as $cp): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($cp['name']) ?></span>
          <span class="badge <?= $cp['category'] === 'personal' ? 'badge-format' : 'badge-active' ?>"><?= $cp['category'] === 'personal' ? 'Personal' : 'Company' ?></span>
          <?php if ($cp['default_layout']): ?><span class="badge badge-campaign"><?= h(render_design_templates()[$cp['default_layout']]['name'] ?? $cp['default_layout']) ?></span><?php endif; ?>
          <?php if ($cp['default_palette']): ?><span class="badge badge-campaign"><?= h(palette_display_name($cp['default_palette'], $brandPalettes)) ?></span><?php endif; ?>
          <span class="muted"><?= h(mb_strimwidth($cp['description'] ?? '', 0, 80, '…')) ?></span>
        </div>
        <form method="post" onsubmit="return confirm('Remove this content pillar?');">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="pillar_delete">
          <input type="hidden" name="pillar_id" value="<?= (int) $cp['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No content pillars added yet.</p>
  <?php endif; ?>
  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="pillar_add">
    <label>Name
      <input type="text" name="pillar_name" placeholder="e.g. Case Studies" required>
    </label>
    <label>Category
      <select name="pillar_category">
        <option value="company">Company</option>
        <option value="personal">Personal</option>
      </select>
    </label>
    <label>Description <span class="muted">(optional)</span>
      <textarea name="pillar_description" rows="2"></textarea>
    </label>
    <label>Design Template for this pillar <span class="muted">(optional — overrides your Single Image/Carousel defaults below for posts tagged with this pillar; re-saving this pillar without picking one clears the override back to Auto)</span>
      <select name="pillar_layout">
        <option value="">Auto (use my Single Image/Carousel default)</option>
        <?php foreach (render_design_templates() as $tid => $t): ?>
          <option value="<?= h($tid) ?>"<?= ($cp['default_layout'] ?? '') === $tid ? ' selected' : '' ?>><?= h($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Color Palette for this pillar <span class="muted">(optional — overrides your Single Image/Carousel palette defaults below for posts tagged with this pillar)</span>
      <select name="pillar_palette">
        <?= render_palette_select_options('', $brandPalettes, true) ?>
      </select>
    </label>
    <button type="submit" class="btn-secondary">Add Content Pillar</button>
  </form>
</section>

<section class="card">
  <h2>Default Design Templates</h2>
  <p class="muted">Auto-assigns a Design Template and Color Palette during bulk generation (Content Studio CSV upload, Content Calendar Generator) so you don't have to pick them for every row by hand — the pickers still show on each row for a manual override, it's just already set sensibly. A Content Pillar's own Design Template/Color Palette above takes priority over these when a row/post is tagged with that pillar.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="default_layout_formats">
    <label>Single Image posts</label>
    <?= render_template_picker_html($defaultLayoutSingle ?: 'classic', '_single') ?>
    <label>Color Palette for Single Image posts
      <select name="default_palette_single">
        <?= render_palette_select_options($defaultPaletteSingle, $brandPalettes, true) ?>
      </select>
    </label>
    <label>Carousel posts</label>
    <?= render_template_picker_html($defaultLayoutCarousel ?: 'classic', '_carousel') ?>
    <label>Color Palette for Carousel posts
      <select name="default_palette_carousel">
        <?= render_palette_select_options($defaultPaletteCarousel, $brandPalettes, true) ?>
      </select>
    </label>
    <button type="submit" class="btn-secondary">Save Defaults</button>
  </form>
</section>

<section class="card">
  <h2>CTA Library</h2>
  <p class="muted">Reusable calls-to-action, optionally tagged with a funnel stage. Pick one from New Post's "Generate with AI" panel instead of writing a CTA from scratch each time.</p>
  <?php if ($ctaLibrary): ?>
    <?php foreach ($ctaLibrary as $cta): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($cta['text']) ?></span>
          <?php if ($cta['funnel_stage']): ?><span class="badge badge-format"><?= h($cta['funnel_stage']) ?></span><?php endif; ?>
        </div>
        <form method="post" onsubmit="return confirm('Remove this CTA?');">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="cta_delete">
          <input type="hidden" name="cta_id" value="<?= (int) $cta['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No CTAs added yet.</p>
  <?php endif; ?>
  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="cta_add">
    <label>CTA Text
      <input type="text" name="cta_text" placeholder="e.g. Book a call with our team" required>
    </label>
    <label>Funnel Stage <span class="muted">(optional)</span>
      <select name="cta_funnel_stage">
        <option value="">— None —</option>
        <?php foreach ($funnelStages as $stage): ?>
          <option value="<?= h($stage) ?>"><?= h($stage) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="btn-secondary">Add CTA</button>
  </form>
</section>

<section class="card">
  <h2>Tag Directory</h2>
  <p class="muted">LinkedIn only lets an app look up pages you administer — there's no way to search other companies by name. To tag a page you don't manage, find its numeric LinkedIn organization ID (visible in that page's public HTML source, e.g. as "urn:li:organization:12345") and add it here once. It'll then show up in the "@ Tag" button in the caption editor for every future post.</p>

  <?php if ($tagDirectory): ?>
    <?php foreach ($tagDirectory as $entry): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($entry['display_name']) ?></span>
          <span class="muted"><?= h($entry['target_urn']) ?></span>
        </div>
        <form method="post" onsubmit="return confirm('Remove this from your tag directory?');">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="tag_delete">
          <input type="hidden" name="tag_id" value="<?= (int) $entry['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No custom tags added yet.</p>
  <?php endif; ?>

  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="tag_add">
    <label>Display Name
      <input type="text" name="tag_name" placeholder="e.g. Acme Corp" required>
    </label>
    <label>LinkedIn Organization ID <span class="muted">(number, URN, or /company/&lt;number&gt;/ URL)</span>
      <input type="text" name="tag_org_id" placeholder="e.g. 12345 or urn:li:organization:12345" required>
    </label>
    <button type="submit" class="btn-secondary">Add to Directory</button>
  </form>
</section>

<script>
document.querySelectorAll('.auto-toggle').forEach(function (checkbox) {
  var target = document.querySelector('input[name="' + checkbox.dataset.target + '"]');
  if (!target) return;
  var hexInput = document.querySelector('.hex-input[data-for="' + checkbox.dataset.target + '"]');
  checkbox.addEventListener('change', function () {
    target.disabled = checkbox.checked;
    if (hexInput) hexInput.disabled = checkbox.checked;
  });
});

// Color picker <-> hex text field, kept in sync both directions so the
// current value is always readable (the native swatch alone gives no
// text feedback of what's selected).
document.querySelectorAll('.color-field').forEach(function (field) {
  var colorInput = field.querySelector('input[type="color"]');
  var hexInput = field.querySelector('.hex-input');
  if (!colorInput || !hexInput) return;
  colorInput.addEventListener('input', function () {
    hexInput.value = colorInput.value.toUpperCase();
  });
  hexInput.addEventListener('input', function () {
    var v = hexInput.value.trim();
    if (/^#[0-9A-Fa-f]{6}$/.test(v)) {
      colorInput.value = v;
    }
  });
});

// "Edit" on a saved palette loads its current colors into the Add form
// instead of leaving it blank, so updating a palette no longer means
// re-guessing every hex value from scratch.
(function () {
  var form = document.getElementById('paletteForm');
  var submitBtn = document.getElementById('paletteSubmitBtn');
  var cancelBtn = document.getElementById('paletteCancelEdit');
  if (!form || !submitBtn || !cancelBtn) return;

  function setField(name, value) {
    var colorInput = document.getElementById(name);
    var hexInput = document.querySelector('.hex-input[data-for="' + name + '"]');
    var toggle = document.querySelector('.auto-toggle[data-target="' + name + '"]');
    var hasValue = !!value;
    if (toggle) {
      toggle.checked = !hasValue;
      if (colorInput) colorInput.disabled = !hasValue;
      if (hexInput) hexInput.disabled = !hasValue;
    }
    if (hasValue && colorInput) {
      colorInput.value = value;
      if (hexInput) hexInput.value = value.toUpperCase();
    }
  }

  document.querySelectorAll('.palette-edit-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('palette_name').value = btn.dataset.name;
      setField('palette_bg', btn.dataset.bg);
      setField('palette_text', btn.dataset.text);
      setField('palette_accent', btn.dataset.accent);
      setField('palette_cta', btn.dataset.cta);
      setField('palette_signature', btn.dataset.signature);
      submitBtn.textContent = 'Update Palette';
      cancelBtn.style.display = 'inline-block';
      form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });

  cancelBtn.addEventListener('click', function () {
    form.reset();
    document.querySelectorAll('.color-field').forEach(function (field) {
      var colorInput = field.querySelector('input[type="color"]');
      var hexInput = field.querySelector('.hex-input');
      if (colorInput && hexInput) hexInput.value = colorInput.value.toUpperCase();
    });
    document.querySelectorAll('.auto-toggle').forEach(function (checkbox) {
      var target = document.querySelector('input[name="' + checkbox.dataset.target + '"]');
      var hexInput = document.querySelector('.hex-input[data-for="' + checkbox.dataset.target + '"]');
      checkbox.checked = true;
      if (target) target.disabled = true;
      if (hexInput) hexInput.disabled = true;
    });
    submitBtn.textContent = 'Add Palette';
    cancelBtn.style.display = 'none';
  });
})();
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
