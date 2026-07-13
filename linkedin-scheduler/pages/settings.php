<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/zip_import.php';
require_once __DIR__ . '/../includes/image_renderer.php';
require_once __DIR__ . '/../includes/news_fetch.php';
require_once __DIR__ . '/../includes/kb_documents.php';
require_once __DIR__ . '/../includes/ai_generate.php';
require_once __DIR__ . '/../includes/wordpress_api.php';
require_once __DIR__ . '/../includes/jekyll_api.php';
require_once __DIR__ . '/../includes/grav_api.php';

require_login();
$userId = current_user_id();
$user = current_user();
$workspaceId = current_workspace_id();
$workspace = current_workspace();

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

    if (($_POST['form'] ?? '') === 'reddit_credentials') {
        set_reddit_credentials($userId, $_POST['reddit_client_id'] ?? '', $_POST['reddit_client_secret'] ?? '');
        flash('success', 'Reddit credentials saved.');
        redirect('pages/settings.php');
    }

    // Active workspace's knowledge-hub profile — the context every AI
    // generation in this workspace receives (includes/workspace.php
    // workspace_context_text()).
    if (($_POST['form'] ?? '') === 'workspace_profile') {
        $name = trim($_POST['ws_name'] ?? '') ?: $workspace['name'];
        $accountId = (int) ($_POST['ws_linkedin_account_id'] ?? 0) ?: null;
        if ($accountId !== null) {
            // Must be owned by this user AND match this workspace's type —
            // a personal profile attached to a company workspace (or vice
            // versa) silently mixes that account's posts into the wrong
            // workspace's calendar with no way to tell from the UI.
            $chk = db()->prepare('SELECT id FROM linkedin_accounts WHERE id = ? AND user_id = ? AND account_type = ?');
            $chk->execute([$accountId, $userId, $workspace['type']]);
            if (!$chk->fetch()) {
                $accountId = null;
                flash('error', 'That account is a different type than this workspace (' . $workspace['type'] . ') — not attached. Pick a matching account, or connect one first.');
            }
        }
        db()->prepare(
            'UPDATE workspaces SET name = ?, linkedin_account_id = ?, about = ?, industry = ?, target_audience = ?,
             tone_of_voice = ?, goals = ?, content_rules = ?, website = ? WHERE id = ? AND user_id = ?'
        )->execute([
            $name, $accountId,
            trim($_POST['ws_about'] ?? '') ?: null,
            trim($_POST['ws_industry'] ?? '') ?: null,
            trim($_POST['ws_target_audience'] ?? '') ?: null,
            trim($_POST['ws_tone_of_voice'] ?? '') ?: null,
            trim($_POST['ws_goals'] ?? '') ?: null,
            trim($_POST['ws_content_rules'] ?? '') ?: null,
            trim($_POST['ws_website'] ?? '') ?: null,
            $workspaceId, $userId,
        ]);
        flash('success', 'Workspace profile saved.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'wordpress_settings') {
        db()->prepare('UPDATE workspaces SET wordpress_url = ?, wordpress_username = ?, wordpress_app_password = ? WHERE id = ? AND user_id = ?')
            ->execute([
                rtrim(trim($_POST['wordpress_url'] ?? ''), '/') ?: null,
                trim($_POST['wordpress_username'] ?? '') ?: null,
                trim($_POST['wordpress_app_password'] ?? '') ?: null,
                $workspaceId, $userId,
            ]);
        flash('success', 'WordPress connection saved.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'wordpress_test') {
        $ws = fetch_workspace($userId, $workspaceId);
        $result = wordpress_test_connection($ws);
        flash($result['success'] ? 'success' : 'error', $result['success']
            ? "Connected — authenticated as \"{$result['user']}\"."
            : $result['error']);
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'jekyll_settings') {
        db()->prepare('UPDATE workspaces SET jekyll_repo = ?, jekyll_branch = ?, jekyll_token = ?, jekyll_posts_path = ?, jekyll_site_url = ? WHERE id = ? AND user_id = ?')
            ->execute([
                trim($_POST['jekyll_repo'] ?? '') ?: null,
                trim($_POST['jekyll_branch'] ?? '') ?: null,
                trim($_POST['jekyll_token'] ?? '') ?: null,
                trim($_POST['jekyll_posts_path'] ?? '', '/') ?: null,
                rtrim(trim($_POST['jekyll_site_url'] ?? ''), '/') ?: null,
                $workspaceId, $userId,
            ]);
        flash('success', 'Jekyll connection saved.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'jekyll_test') {
        $ws = fetch_workspace($userId, $workspaceId);
        $result = jekyll_test_connection($ws);
        flash($result['success'] ? 'success' : 'error', $result['success']
            ? "Connected — token has access to \"{$result['user']}\"."
            : $result['error']);
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'grav_settings') {
        db()->prepare('UPDATE workspaces SET grav_site_url = ?, grav_api_key = ?, grav_route_prefix = ?, grav_template = ? WHERE id = ? AND user_id = ?')
            ->execute([
                rtrim(trim($_POST['grav_site_url'] ?? ''), '/') ?: null,
                trim($_POST['grav_api_key'] ?? '') ?: null,
                trim($_POST['grav_route_prefix'] ?? '', '/') ?: null,
                trim($_POST['grav_template'] ?? '') ?: null,
                $workspaceId, $userId,
            ]);
        flash('success', 'Grav connection saved.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'grav_test') {
        $ws = fetch_workspace($userId, $workspaceId);
        $result = grav_test_connection($ws);
        flash($result['success'] ? 'success' : 'error', $result['success']
            ? 'Connected — Grav API responded successfully.'
            : $result['error']);
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'workspace_add') {
        $name = trim($_POST['ws_name'] ?? '');
        if ($name === '') {
            flash('error', 'Enter a name for the company workspace.');
            redirect('pages/settings.php');
        }
        $newId = create_workspace($userId, 'company', $name);
        seed_default_knowledge_base($userId, $newId);
        set_current_workspace($userId, $newId);
        flash('success', "Workspace \"{$name}\" created with a starter knowledge base — you're now in it. Fill in its profile below.");
        redirect('pages/settings.php');
    }

    // Company workspaces only; scoped rows are kept but unassigned (they
    // become visible in every workspace) rather than destroyed.
    if (($_POST['form'] ?? '') === 'workspace_delete') {
        $wsDel = fetch_workspace($userId, (int) ($_POST['workspace_id'] ?? 0));
        if (!$wsDel || $wsDel['type'] === 'personal') {
            flash('error', 'The Personal workspace can\'t be deleted.');
            redirect('pages/settings.php');
        }
        foreach (['content_pillars', 'personas', 'cta_library', 'news_topics', 'news_items', 'news_trusted_sources', 'calendar_batches', 'posts'] as $tbl) {
            db()->prepare("UPDATE {$tbl} SET workspace_id = NULL WHERE workspace_id = ?")->execute([(int) $wsDel['id']]);
        }
        db()->prepare('DELETE FROM workspaces WHERE id = ? AND user_id = ?')->execute([(int) $wsDel['id'], $userId]);
        flash('success', "Workspace \"{$wsDel['name']}\" removed — its content is kept and now shows in all workspaces.");
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'seed_kb') {
        seed_default_knowledge_base($userId, $workspaceId);
        flash('success', 'Starter personas, content pillars, and CTAs added to this workspace — anything you already had was left untouched.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'kb_doc_upload') {
        if (empty($_FILES['kb_doc']['tmp_name']) || $_FILES['kb_doc']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Choose a PDF, Word (.docx), or text (.txt/.md) file to upload.');
            redirect('pages/settings.php');
        }
        if ($_FILES['kb_doc']['size'] > MAX_DOCUMENT_SIZE_BYTES) {
            flash('error', 'That file is too large — the limit is 10MB.');
            redirect('pages/settings.php');
        }
        $originalName = $_FILES['kb_doc']['name'];
        $contents = file_get_contents($_FILES['kb_doc']['tmp_name']);
        $kind = sniff_document_kind($contents, $originalName);
        if ($kind === null) {
            flash('error', 'Unrecognized file type — upload a PDF, Word (.docx), or plain text (.txt/.md) file.');
            redirect('pages/settings.php');
        }
        $dir = UPLOAD_DIR . "/{$userId}/workspace_{$workspaceId}/documents";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $safeName = preg_replace('/[^A-Za-z0-9_.-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $storedName = bin2hex(random_bytes(8)) . '_' . mb_substr($safeName, 0, 100) . '.' . $kind;
        $destPath = $dir . '/' . $storedName;
        file_put_contents($destPath, $contents);
        $extractedText = extract_document_text($destPath, $kind);
        db()->prepare('INSERT INTO knowledge_documents (workspace_id, filename, filepath, kind, extracted_text) VALUES (?, ?, ?, ?, ?)')
            ->execute([$workspaceId, mb_substr($originalName, 0, 255), $destPath, $kind, $extractedText]);
        flash($extractedText !== null ? 'success' : 'error',
            $extractedText !== null
                ? "\"{$originalName}\" uploaded — its text is now part of this workspace's AI context."
                : "\"{$originalName}\" uploaded, but no readable text could be extracted (scanned/image-only file?). It's saved but won't add any context until replaced with a text-based version.");
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'kb_doc_delete') {
        delete_knowledge_document($workspaceId, (int) ($_POST['doc_id'] ?? 0));
        flash('success', 'Document removed.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'kb_doc_summarize') {
        $doc = fetch_knowledge_document($workspaceId, (int) ($_POST['doc_id'] ?? 0));
        if (!$doc || $doc['extracted_text'] === null) {
            flash('error', 'This document has no extracted text to summarize.');
            redirect('pages/settings.php');
        }
        $aiConfig = resolve_ai_config($userId);
        if (!ai_configured($aiConfig)) {
            flash('error', 'Add an AI provider key in Settings first.');
            redirect('pages/settings.php');
        }
        try {
            $summary = ai_summarize_document($doc['extracted_text'], $aiConfig);
            db()->prepare('UPDATE knowledge_documents SET summary = ? WHERE id = ? AND workspace_id = ?')
                ->execute([$summary, $doc['id'], $workspaceId]);
            flash('success', "Summarized \"{$doc['filename']}\" — the summary (not the full text) is now used in AI context going forward.");
        } catch (Throwable $e) {
            flash('error', 'Summarization failed: ' . $e->getMessage());
        }
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
            'INSERT INTO personas (user_id, workspace_id, name, description) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description), workspace_id = VALUES(workspace_id)'
        );
        $stmt->execute([$userId, $workspaceId, $name, $desc]);
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
            'INSERT INTO content_pillars (user_id, workspace_id, name, description, category, default_layout, default_palette) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description), category = VALUES(category), default_layout = VALUES(default_layout), default_palette = VALUES(default_palette), workspace_id = VALUES(workspace_id)'
        );
        $stmt->execute([$userId, $workspaceId, $name, $desc, $category, $layout, $palette]);
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
        db()->prepare(
            'UPDATE workspaces SET default_layout_single = ?, default_layout_carousel = ?, default_palette_single = ?, default_palette_carousel = ? WHERE id = ? AND user_id = ?'
        )->execute([
            array_key_exists($single, $templates) ? $single : null,
            array_key_exists($carousel, $templates) ? $carousel : null,
            validate_palette_select_value($userId, trim($_POST['default_palette_single'] ?? '')),
            validate_palette_select_value($userId, trim($_POST['default_palette_carousel'] ?? '')),
            $workspaceId, $userId,
        ]);
        flash('success', 'Default Design Templates updated.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'news_settings') {
        $enabled = !empty($_POST['news_auto_enabled']) ? 1 : 0;
        $perDay = max(1, min(5, (int) ($_POST['news_drafts_per_day'] ?? 2)));
        db()->prepare('UPDATE workspaces SET news_auto_enabled = ?, news_drafts_per_day = ? WHERE id = ? AND user_id = ?')
            ->execute([$enabled, $perDay, $workspaceId, $userId]);
        flash('success', 'News auto-content settings saved.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'news_topic_add') {
        $query = trim($_POST['news_query'] ?? '');
        $sourceType = ($_POST['news_source_type'] ?? 'auto') === 'reddit' ? 'reddit' : 'auto';
        if ($sourceType === 'reddit') {
            $query = preg_replace('#^/?r/#i', '', $query); // stored bare, "r/" is added back for display
        }
        if ($query === '') {
            flash('error', $sourceType === 'reddit' ? 'Enter a subreddit name.' : 'Enter a search keyword, phrase, or RSS feed URL.');
        } elseif ($sourceType === 'auto' && news_topic_is_feed($query) && !filter_var($query, FILTER_VALIDATE_URL)) {
            flash('error', 'That looks like a URL but isn\'t a valid one — check it and try again.');
        } else {
            add_news_topic($userId, $query, $workspaceId, $sourceType);
            flash('success', $sourceType === 'reddit'
                ? "Subreddit \"r/{$query}\" added."
                : (news_topic_is_feed($query) ? 'RSS feed added — it will be fetched directly.' : "News keyword \"{$query}\" added."));
        }
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'news_source_add') {
        $source = trim($_POST['news_source'] ?? '');
        if ($source === '') {
            flash('error', 'Enter a publisher domain or name.');
        } else {
            add_news_trusted_source($userId, $source, $workspaceId);
            flash('success', "Trusted source \"{$source}\" added — Google News results are now limited to your trusted list.");
        }
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'news_source_delete') {
        delete_news_trusted_source($userId, (int) ($_POST['source_id'] ?? 0));
        flash('success', 'Trusted source removed.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'news_topic_delete') {
        delete_news_topic($userId, (int) ($_POST['topic_id'] ?? 0));
        flash('success', 'News keyword removed.');
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
        $stmt = db()->prepare('INSERT INTO cta_library (user_id, workspace_id, text, funnel_stage) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $workspaceId, $text, $stage]);
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
        $dir = UPLOAD_DIR . "/{$userId}/workspace_{$workspaceId}/branding";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        foreach (['png', 'jpg', 'jpeg'] as $existingExt) {
            @unlink("{$dir}/{$slot}.{$existingExt}");
        }
        file_put_contents("{$dir}/{$slot}.{$ext}", $contents);
        flash('success', ucfirst($slot) . ' updated for this workspace.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'footer_image_remove') {
        $slot = ($_POST['image_slot'] ?? '') === 'logo' ? 'logo' : 'photo';
        $dir = UPLOAD_DIR . "/{$userId}/workspace_{$workspaceId}/branding";
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            @unlink("{$dir}/{$slot}.{$ext}");
        }
        flash('success', ucfirst($slot) . ' removed from this workspace.');
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
        $dir = UPLOAD_DIR . "/{$userId}/workspace_{$workspaceId}/branding";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        foreach (['png', 'jpg', 'jpeg'] as $existingExt) {
            @unlink("{$dir}/brand_logo.{$existingExt}");
        }
        file_put_contents("{$dir}/brand_logo.{$ext}", $contents);
        flash('success', 'Brand logo updated for this workspace.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'brand_logo_remove') {
        $dir = UPLOAD_DIR . "/{$userId}/workspace_{$workspaceId}/branding";
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            @unlink("{$dir}/brand_logo.{$ext}");
        }
        flash('success', 'Brand logo removed from this workspace.');
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
$redditCreds = get_reddit_credentials($userId);
$workspaces = fetch_workspaces($userId);
$personas = fetch_personas($userId, $workspaceId);
$contentPillars = fetch_content_pillars($userId, $workspaceId);
$defaultLayoutSingle = $workspace['default_layout_single'] ?? null;
$defaultLayoutCarousel = $workspace['default_layout_carousel'] ?? null;
$defaultPaletteSingle = $workspace['default_palette_single'] ?? null;
$defaultPaletteCarousel = $workspace['default_palette_carousel'] ?? null;
$knowledgeDocuments = fetch_knowledge_documents($workspaceId);
$newsTopics = fetch_news_topics($userId, $workspaceId);
$newsTrustedSources = fetch_news_trusted_sources($userId, $workspaceId);
$newsSettings = ['news_auto_enabled' => $workspace['news_auto_enabled'] ?? 0, 'news_drafts_per_day' => $workspace['news_drafts_per_day'] ?? 2];
$ctaLibrary = fetch_cta_library($userId, $workspaceId);
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

// Per-workspace branding: each workspace can set its own footer
// logo/photo and brand logo — the display here only shows a workspace's
// OWN upload (not the fallback chain resolve_footer_image() applies at
// render time), so it's clear whether this specific workspace has one
// set versus inheriting the account-wide/bundled default.
$footerImages = [];
foreach (['logo', 'photo'] as $slot) {
    $footerImages[$slot] = null;
    foreach (['png', 'jpg', 'jpeg'] as $ext) {
        $path = UPLOAD_DIR . "/{$userId}/workspace_{$workspaceId}/branding/{$slot}.{$ext}";
        if (is_file($path)) {
            $footerImages[$slot] = slide_public_url($path);
            break;
        }
    }
}

$brandLogoUrl = null;
$brandLogoPath = null;
foreach (['png', 'jpg', 'jpeg'] as $ext) {
    $path = UPLOAD_DIR . "/{$userId}/workspace_{$workspaceId}/branding/brand_logo.{$ext}";
    if (is_file($path)) {
        $brandLogoPath = $path;
        break;
    }
}
if ($brandLogoPath) {
    $brandLogoUrl = slide_public_url($brandLogoPath);
}

$pageTitle  = 'Settings';
$activePage = 'settings';
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>Settings</h1></div>

<nav class="settings-tabs" id="settingsTabs">
  <button type="button" class="settings-tab-btn" data-tab-target="account">Account</button>
  <button type="button" class="settings-tab-btn" data-tab-target="brand">Brand &amp; Workspace</button>
  <button type="button" class="settings-tab-btn" data-tab-target="content">Content Strategy</button>
  <button type="button" class="settings-tab-btn" data-tab-target="integrations">Integrations</button>
</nav>

<section class="card" data-tab="account">
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

<section class="card" data-tab="account">
  <h2>LinkedIn Accounts</h2>
  <p class="muted">Manage which personal profile and Company Pages are connected from the <a href="<?= h(app_path('pages/accounts.php')) ?>">Accounts</a> page.</p>
</section>

<section class="card" data-tab="account">
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

<section class="card" data-tab="integrations">
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

<section class="card" data-tab="integrations">
  <h2>Reddit (News Studio trend source)</h2>
  <p class="muted">Lets News Studio pull trending discussion from subreddits you add below, alongside Google News. Create a free "script" app at <a href="https://www.reddit.com/prefs/apps" target="_blank" rel="noopener">reddit.com/prefs/apps</a> (no approval wait) and paste its Client ID/Secret here — this app never posts to Reddit or touches your Reddit account, it only reads public subreddit listings.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="reddit_credentials">
    <label>Client ID
      <input type="text" name="reddit_client_id" value="<?= h($redditCreds['client_id'] ?? '') ?>" placeholder="e.g. abcXYZ123-_"  autocomplete="off">
    </label>
    <label>Client Secret
      <input type="password" name="reddit_client_secret" value="<?= h($redditCreds['client_secret'] ?? '') ?>" placeholder="e.g. abcXYZ123-_..." autocomplete="off">
    </label>
    <button type="submit" class="btn-secondary">Save Reddit Credentials</button>
  </form>
</section>

<section class="card" data-tab="brand">
  <h2>Workspaces</h2>
  <p class="muted">Everything below Settings' AI Provider section is <strong>per workspace</strong> — knowledge profile, personas, content pillars, CTAs, news topics, and design defaults are all separate for your Personal voice and for each company page. Switch workspaces with the selector at the top of the sidebar; every page (New Post, Content Calendar, News Studio, Drafts…) follows it.</p>
  <?php foreach ($workspaces as $wsRow): ?>
    <div class="account-row">
      <div class="account-info">
        <span><strong><?= h($wsRow['name']) ?></strong></span>
        <span class="badge <?= $wsRow['type'] === 'personal' ? 'badge-format' : 'badge-active' ?>"><?= $wsRow['type'] === 'personal' ? 'Personal' : 'Company' ?></span>
        <?php if ((int) $wsRow['id'] === $workspaceId): ?><span class="badge badge-campaign">Active</span><?php endif; ?>
      </div>
      <?php if ($wsRow['type'] !== 'personal'): ?>
        <form method="post" onsubmit="return confirm('Remove this workspace? Its pillars/personas/posts are kept but become visible in every workspace.');">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="workspace_delete">
          <input type="hidden" name="workspace_id" value="<?= (int) $wsRow['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <form method="post" class="stacked-form" style="margin-top:12px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="workspace_add">
    <label>New company workspace
      <input type="text" name="ws_name" placeholder="e.g. Acme Manufacturing" required>
    </label>
    <button type="submit" class="btn-secondary">Add Company Workspace</button>
  </form>
</section>

<section class="card" data-tab="brand">
  <h2>Knowledge Hub — <?= h($workspace['name']) ?> profile</h2>
  <p class="muted">This is who <?= $workspace['type'] === 'personal' ? 'you are' : 'this company is' ?> — every AI generation in this workspace automatically receives all of it as context, so the more you fill in, the more on-voice the content. (Uploadable reference documents are coming to this hub next.)</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="workspace_profile">
    <label>Workspace name
      <input type="text" name="ws_name" value="<?= h($workspace['name']) ?>" required>
    </label>
    <label>Default LinkedIn account <span class="muted">(new posts in this workspace pre-select it)</span>
      <select name="ws_linkedin_account_id">
        <option value="">— None —</option>
        <?php foreach (fetch_user_accounts($userId) as $acct): ?>
          <?php if ($acct['account_type'] !== $workspace['type']) continue; ?>
          <option value="<?= (int) $acct['id'] ?>"<?= (int) ($workspace['linkedin_account_id'] ?? 0) === (int) $acct['id'] ? ' selected' : '' ?>><?= h($acct['display_name']) ?> (<?= h($acct['account_type']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <?php if (!array_filter(fetch_user_accounts($userId), fn ($a) => $a['account_type'] === $workspace['type'])): ?>
        <span class="muted">No <?= h($workspace['type']) ?> account connected yet — connect one on the <a href="<?= h(app_path('pages/accounts.php')) ?>">Accounts</a> page first.</span>
      <?php endif; ?>
    </label>
    <label><?= $workspace['type'] === 'personal' ? 'About you' : 'About the company' ?> <span class="muted">(the brief — who, what, voice)</span>
      <textarea name="ws_about" rows="4" placeholder="<?= $workspace['type'] === 'personal' ? "e.g. I'm a reliability engineer turned founder. Voice: candid, a bit informal, share real lessons not just wins." : 'e.g. We sell predictive-maintenance sensors to mid-size manufacturing plants. Voice: direct, data-driven, not salesy.' ?>"><?= h($workspace['about'] ?? '') ?></textarea>
    </label>
    <label>Industry
      <input type="text" name="ws_industry" value="<?= h($workspace['industry'] ?? '') ?>" placeholder="e.g. Industrial IoT / Manufacturing">
    </label>
    <label>Target audience
      <textarea name="ws_target_audience" rows="2" placeholder="e.g. Plant managers and reliability engineers at 200-2000 employee manufacturers"><?= h($workspace['target_audience'] ?? '') ?></textarea>
    </label>
    <label>Tone of voice
      <input type="text" name="ws_tone_of_voice" value="<?= h($workspace['tone_of_voice'] ?? '') ?>" placeholder="e.g. Direct, practical, numbers over adjectives, never salesy">
    </label>
    <label>Content goals
      <textarea name="ws_goals" rows="2" placeholder="e.g. Build authority in predictive maintenance; generate demo calls"><?= h($workspace['goals'] ?? '') ?></textarea>
    </label>
    <label>Content rules — do's &amp; don'ts <span class="muted">(followed strictly by the AI)</span>
      <textarea name="ws_content_rules" rows="3" placeholder="e.g. Never mention competitors by name. Always use metric units. No emojis in captions."><?= h($workspace['content_rules'] ?? '') ?></textarea>
    </label>
    <label>Website
      <input type="text" name="ws_website" value="<?= h($workspace['website'] ?? '') ?>" placeholder="https://example.com">
    </label>
    <button type="submit" class="btn-primary">Save Profile</button>
  </form>
</section>

<section class="card" data-tab="integrations">
  <h2>WordPress — <?= h($workspace['name']) ?></h2>
  <p class="muted">Connect a WordPress site to publish <a href="<?= h(app_path('pages/blog_studio.php')) ?>">Blog Studio</a> posts to directly. Use an <strong>Application Password</strong> (WordPress admin &gt; Users &gt; Profile &gt; Application Passwords), not your account password — it's scoped and revocable independently.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="wordpress_settings">
    <label>Site URL
      <input type="text" name="wordpress_url" value="<?= h($workspace['wordpress_url'] ?? '') ?>" placeholder="https://example.com">
    </label>
    <label>Username
      <input type="text" name="wordpress_username" value="<?= h($workspace['wordpress_username'] ?? '') ?>" autocomplete="off">
    </label>
    <label>Application Password
      <input type="password" name="wordpress_app_password" value="<?= h($workspace['wordpress_app_password'] ?? '') ?>" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" autocomplete="off">
    </label>
    <button type="submit" class="btn-secondary">Save WordPress Connection</button>
  </form>
  <?php if (wordpress_configured($workspace)): ?>
    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="form" value="wordpress_test">
      <button type="submit" class="btn-tiny">Test Connection</button>
    </form>
  <?php endif; ?>
</section>

<section class="card" data-tab="integrations">
  <h2>Jekyll — <?= h($workspace['name']) ?></h2>
  <p class="muted">Connect a Jekyll site's GitHub repo to publish <a href="<?= h(app_path('pages/blog_studio.php')) ?>">Blog Studio</a> posts to. Jekyll has no live API, so publishing commits a markdown file (with front matter) straight to <code>_posts/</code> in this repo — it does <strong>not</strong> deploy the live site. If your host deploys via cPanel Git Version Control (like this app does), you'll still click "Update from Remote" / "Deploy HEAD Commit" there after each publish. Use a fine-grained GitHub <strong>Personal Access Token</strong> scoped to just this repo with "Contents: Read and write" permission — not a broad account token.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="jekyll_settings">
    <label>Repo <span class="muted">(owner/repo)</span>
      <input type="text" name="jekyll_repo" value="<?= h($workspace['jekyll_repo'] ?? '') ?>" placeholder="yourname/your-jekyll-site">
    </label>
    <label>Branch
      <input type="text" name="jekyll_branch" value="<?= h($workspace['jekyll_branch'] ?? '') ?>" placeholder="main">
    </label>
    <label>Personal Access Token
      <input type="password" name="jekyll_token" value="<?= h($workspace['jekyll_token'] ?? '') ?>" placeholder="github_pat_..." autocomplete="off">
    </label>
    <label>Posts path
      <input type="text" name="jekyll_posts_path" value="<?= h($workspace['jekyll_posts_path'] ?? '') ?>" placeholder="_posts">
    </label>
    <label>Site URL <span class="muted">(optional — used to build the post link after publishing)</span>
      <input type="text" name="jekyll_site_url" value="<?= h($workspace['jekyll_site_url'] ?? '') ?>" placeholder="https://example.com">
    </label>
    <button type="submit" class="btn-secondary">Save Jekyll Connection</button>
  </form>
  <?php if (jekyll_configured($workspace)): ?>
    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="form" value="jekyll_test">
      <button type="submit" class="btn-tiny">Test Connection</button>
    </form>
  <?php endif; ?>
</section>

<section class="card" data-tab="integrations">
  <h2>Grav — <?= h($workspace['name']) ?></h2>
  <p class="muted">Connect a Grav CMS site to publish <a href="<?= h(app_path('pages/blog_studio.php')) ?>">Blog Studio</a> posts to directly — no git, no build/deploy step. Grav is a live PHP site, so a post created here is <strong>live immediately</strong>. Requires the official <strong>API</strong> plugin (<code>getgrav/grav-plugin-api</code>) installed and enabled on that site: in Grav's own Admin Panel (not cPanel), go to Plugins → Add and install "API", then generate a key from your user profile's API Keys section.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="grav_settings">
    <label>Site URL
      <input type="text" name="grav_site_url" value="<?= h($workspace['grav_site_url'] ?? '') ?>" placeholder="https://example.com">
    </label>
    <label>API Key
      <input type="password" name="grav_api_key" value="<?= h($workspace['grav_api_key'] ?? '') ?>" placeholder="grav_..." autocomplete="off">
    </label>
    <label>Route prefix <span class="muted">(where new posts go)</span>
      <input type="text" name="grav_route_prefix" value="<?= h($workspace['grav_route_prefix'] ?? '') ?>" placeholder="/blog">
    </label>
    <label>Template <span class="muted">(your blog post page's template name)</span>
      <input type="text" name="grav_template" value="<?= h($workspace['grav_template'] ?? '') ?>" placeholder="item">
    </label>
    <button type="submit" class="btn-secondary">Save Grav Connection</button>
  </form>
  <?php if (grav_configured($workspace)): ?>
    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="form" value="grav_test">
      <button type="submit" class="btn-tiny">Test Connection</button>
    </form>
  <?php endif; ?>
</section>

<section class="card" data-tab="brand">
  <h2>Reference Documents — <?= h($workspace['name']) ?></h2>
  <p class="muted">Upload PDFs, Word docs, or text files with facts, positioning, product details, or data you want the AI to draw on — pitch decks, one-pagers, FAQs, case studies. Extracted text is added to this workspace's AI context automatically. For longer documents, click "Summarize" once your AI provider is configured — it condenses the document into a compact summary that's reused instead of the full text on every generation.</p>
  <?php if ($knowledgeDocuments): ?>
    <?php foreach ($knowledgeDocuments as $doc): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($doc['filename']) ?></span>
          <span class="badge badge-format"><?= strtoupper(h($doc['kind'])) ?></span>
          <?php if (!$doc['has_text']): ?>
            <span class="badge badge-warning">No readable text</span>
          <?php elseif ($doc['has_summary']): ?>
            <span class="badge badge-active">Summarized</span>
          <?php else: ?>
            <span class="badge badge-scheduled">Using full text</span>
          <?php endif; ?>
          <span class="muted"><?= h(date('j M Y', strtotime($doc['uploaded_at']))) ?></span>
        </div>
        <div class="inline-form">
          <?php if ($doc['has_text']): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($token) ?>">
              <input type="hidden" name="form" value="kb_doc_summarize">
              <input type="hidden" name="doc_id" value="<?= (int) $doc['id'] ?>">
              <button type="submit" class="btn-tiny"><?= $doc['has_summary'] ? 'Re-summarize' : 'Summarize' ?></button>
            </form>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Remove this document from the knowledge hub?');">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="form" value="kb_doc_delete">
            <input type="hidden" name="doc_id" value="<?= (int) $doc['id'] ?>">
            <button type="submit" class="btn-tiny btn-danger">Remove</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No documents uploaded yet.</p>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="stacked-form" style="margin-top:12px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="kb_doc_upload">
    <label>Upload document <span class="muted">(PDF, .docx, .txt, or .md — up to 10MB)</span>
      <input type="file" name="kb_doc" accept=".pdf,.docx,.txt,.md" required>
    </label>
    <button type="submit" class="btn-secondary">Upload</button>
  </form>
</section>

<section class="card" data-tab="brand">
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

<section class="card" data-tab="brand">
  <h2>Footer Images — <?= h($workspace['name']) ?></h2>
  <p class="muted">Shown in the circular footer on the last (CTA) slide of a carousel. Logo is used for company-category posts, Photo for personal-category posts (see the Company/Personal tag on Content Pillars below). These are set per workspace — falls back to your account-wide upload (if any from before workspaces existed), then a bundled default, until this workspace has its own.</p>
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

<section class="card" data-tab="brand">
  <h2>Brand Logo — <?= h($workspace['name']) ?></h2>
  <p class="muted">Shown top-left on every generated slide in this workspace, across every Design Template and Color Palette. Aspect ratio is preserved (not cropped) — a transparent-background PNG wordmark works best. No logo means nothing is drawn.</p>
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

<section class="card" data-tab="brand">
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

<section class="card" data-tab="content">
  <h2>Personas, Pillars &amp; CTAs</h2>
  <p class="muted">Every new account starts with a generic starter set of personas, content pillars, and CTAs (editable/removable below). If you deleted them or never got them, load them again any time — this only adds what's missing, it never duplicates.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="seed_kb">
    <button type="submit" class="btn-secondary">Load Starter Content</button>
  </form>
</section>

<section class="card" data-tab="content">
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

<section class="card" data-tab="content">
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

<section class="card" data-tab="brand">
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

<section class="card" data-tab="integrations">
  <h2>News Auto-Content</h2>
  <p class="muted">The <a href="<?= h(app_path('pages/news_studio.php')) ?>">News Studio</a> searches Google News for every Content Pillar name above, plus the extra keywords below, and turns trending headlines into draft posts written in your voice. With auto-drafting on, the daily cron generates drafts each morning for you to review — nothing is ever posted without your approval.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="news_settings">
    <label class="checkbox-row">
      <input type="checkbox" name="news_auto_enabled" value="1" <?= !empty($newsSettings['news_auto_enabled']) ? 'checked' : '' ?>>
      Auto-generate news drafts daily (requires the news cron job — see cron/news_daily.php)
    </label>
    <label>Drafts per day
      <select name="news_drafts_per_day">
        <?php for ($n = 1; $n <= 5; $n++): ?>
          <option value="<?= $n ?>"<?= (int) $newsSettings['news_drafts_per_day'] === $n ? ' selected' : '' ?>><?= $n ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <button type="submit" class="btn-secondary">Save News Settings</button>
  </form>
  <h3 style="margin-top:20px;">Extra news keywords, RSS feeds &amp; subreddits</h3>
  <p class="muted">Searched in addition to your Content Pillar names — use these for topics you follow but don't have a pillar for (e.g. a competitor, a technology, an industry event). You can also paste a publication's own <strong>RSS feed URL</strong> to fetch that feed directly instead of searching Google News, or add a <strong>subreddit</strong> (requires Reddit credentials below) to pull trending discussion instead of headlines. Direct feeds and subreddits both skip the trusted-sources filter below, since adding one is itself the trust decision.</p>
  <?php if ($newsTopics): ?>
    <?php foreach ($newsTopics as $nt): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($nt['source_type'] === 'reddit' ? 'r/' . $nt['query'] : $nt['query']) ?></span>
          <?php if ($nt['source_type'] === 'reddit'): ?>
            <span class="badge badge-active">Reddit</span>
          <?php elseif (news_topic_is_feed($nt['query'])): ?>
            <span class="badge badge-scheduled">Direct feed</span>
          <?php endif; ?>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="news_topic_delete">
          <input type="hidden" name="topic_id" value="<?= (int) $nt['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No extra keywords yet — your Content Pillar names are always searched.</p>
  <?php endif; ?>
  <form method="post" class="stacked-form" style="margin-top:12px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="news_topic_add">
    <label>Source type
      <select name="news_source_type">
        <option value="auto">Google News keyword / RSS feed URL</option>
        <option value="reddit">Subreddit (Reddit)</option>
      </select>
    </label>
    <label>Keyword, phrase, RSS feed URL, or subreddit name
      <input type="text" name="news_query" placeholder="e.g. predictive maintenance India — or https://example.com/feed.xml — or r/manufacturing" required>
    </label>
    <button type="submit" class="btn-secondary">Add</button>
  </form>

  <h3 style="margin-top:20px;">Trusted sources</h3>
  <p class="muted">Restrict Google News results to publishers you trust — enter a domain (<code>economictimes.indiatimes.com</code>) or a name (<code>Reuters</code>). While this list has entries, headlines from anyone else are dropped at fetch time. Leave it empty to allow all sources.</p>
  <?php if ($newsTrustedSources): ?>
    <?php foreach ($newsTrustedSources as $ns): ?>
      <div class="account-row">
        <div class="account-info"><span><?= h($ns['source']) ?></span></div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="news_source_delete">
          <input type="hidden" name="source_id" value="<?= (int) $ns['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No trusted sources set — headlines from any publisher are accepted.</p>
  <?php endif; ?>
  <form method="post" class="stacked-form" style="margin-top:12px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="news_source_add">
    <label>Publisher domain or name
      <input type="text" name="news_source" placeholder="e.g. economictimes.indiatimes.com or Reuters" required>
    </label>
    <button type="submit" class="btn-secondary">Add Trusted Source</button>
  </form>
</section>

<section class="card" data-tab="content">
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

<section class="card" data-tab="content">
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

<script>
  (function () {
    var VALID_TABS = ['account', 'brand', 'content', 'integrations'];
    var tabBtns = document.querySelectorAll('#settingsTabs .settings-tab-btn');
    var panels = document.querySelectorAll('[data-tab]');

    function activate(tab) {
      if (VALID_TABS.indexOf(tab) === -1) tab = VALID_TABS[0];
      tabBtns.forEach(function (btn) {
        btn.classList.toggle('active', btn.dataset.tabTarget === tab);
      });
      panels.forEach(function (panel) {
        panel.style.display = panel.dataset.tab === tab ? '' : 'none';
      });
      try { localStorage.setItem('settingsActiveTab', tab); } catch (e) {}
    }

    tabBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        activate(btn.dataset.tabTarget);
        history.replaceState(null, '', '#' + btn.dataset.tabTarget);
      });
    });

    var initial = (location.hash || '').replace('#', '');
    if (VALID_TABS.indexOf(initial) === -1) {
      try { initial = localStorage.getItem('settingsActiveTab') || VALID_TABS[0]; } catch (e) { initial = VALID_TABS[0]; }
    }
    activate(initial);
  })();
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
