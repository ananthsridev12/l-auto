<?php
// Knowledge Base hub — the app's 9-block ISE-pattern KB (docs/KNOWLEDGE_BASE.md),
// pulled out of Settings into its own page so it reads like a dedicated
// knowledge hub instead of two Settings tabs. Every section here is
// scoped to the active workspace (see includes/workspace.php) — nothing
// about the personal/company workspace split changes, this is purely a
// UI reorganization of where the same data lives.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/kb_documents.php';
require_once __DIR__ . '/../includes/ai_generate.php';
require_once __DIR__ . '/../includes/image_renderer.php';

require_login();
$userId = current_user_id();
$user = current_user();
$workspaceId = current_workspace_id();
$workspace = current_workspace();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'tag_add') {
        $name = trim($_POST['tag_name'] ?? '');
        $urn = normalize_organization_input($_POST['tag_org_id'] ?? '');
        if ($name === '' || $urn === null) {
            flash('error', 'Enter a name and a valid numeric LinkedIn organization ID.');
            redirect('pages/knowledge.php');
        }
        $stmt = db()->prepare(
            'INSERT INTO tag_directory (user_id, display_name, target_urn) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE target_urn = VALUES(target_urn)'
        );
        $stmt->execute([$userId, $name, $urn]);
        flash('success', "\"{$name}\" added to your tag directory.");
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'tag_delete') {
        $stmt = db()->prepare('DELETE FROM tag_directory WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['tag_id'] ?? 0), $userId]);
        flash('success', 'Removed from tag directory.');
        redirect('pages/knowledge.php');
    }

    // Company Identity tab. Split from the old single "workspace_profile"
    // handler (KB round 2, Phase 12 — docs/KNOWLEDGE_BASE.md) so saving
    // this tab never blanks the Tone & Voice tab's fields — each tab is
    // now its own <form>, targeting only its own columns.
    if (($_POST['form'] ?? '') === 'workspace_profile_company') {
        $name = trim($_POST['ws_name'] ?? '') ?: $workspace['name'];
        $accountId = (int) ($_POST['ws_linkedin_account_id'] ?? 0) ?: null;
        if ($accountId !== null) {
            $chk = db()->prepare('SELECT id FROM linkedin_accounts WHERE id = ? AND user_id = ? AND account_type = ?');
            $chk->execute([$accountId, $userId, $workspace['type']]);
            if (!$chk->fetch()) {
                $accountId = null;
                flash('error', 'That account is a different type than this workspace (' . $workspace['type'] . ') — not attached. Pick a matching account, or connect one first.');
            }
        }
        db()->prepare(
            'UPDATE workspaces SET name = ?, linkedin_account_id = ?, about = ?, industry = ?, target_audience = ?,
             goals = ?, content_rules = ?, website = ?,
             tagline = ?, founded_year = ?, company_size = ?, hq_location = ?, mission = ?, vision = ?, story = ?,
             credibility_statement = ?, notable_clients = ?, awards = ?
             WHERE id = ? AND user_id = ?'
        )->execute([
            $name, $accountId,
            trim($_POST['ws_about'] ?? '') ?: null,
            trim($_POST['ws_industry'] ?? '') ?: null,
            trim($_POST['ws_target_audience'] ?? '') ?: null,
            trim($_POST['ws_goals'] ?? '') ?: null,
            trim($_POST['ws_content_rules'] ?? '') ?: null,
            trim($_POST['ws_website'] ?? '') ?: null,
            trim($_POST['ws_tagline'] ?? '') ?: null,
            trim($_POST['ws_founded_year'] ?? '') ?: null,
            trim($_POST['ws_company_size'] ?? '') ?: null,
            trim($_POST['ws_hq_location'] ?? '') ?: null,
            trim($_POST['ws_mission'] ?? '') ?: null,
            trim($_POST['ws_vision'] ?? '') ?: null,
            trim($_POST['ws_story'] ?? '') ?: null,
            trim($_POST['ws_credibility_statement'] ?? '') ?: null,
            trim($_POST['ws_notable_clients'] ?? '') ?: null,
            trim($_POST['ws_awards'] ?? '') ?: null,
            $workspaceId, $userId,
        ]);
        flash('success', 'Company profile saved.');
        redirect('pages/knowledge.php#company');
    }

    if (($_POST['form'] ?? '') === 'workspace_profile_tone') {
        db()->prepare(
            'UPDATE workspaces SET tone_of_voice = ?, tone_descriptors = ?, anti_tone = ?, words_always = ?, words_never = ?,
             post_opening_style = ?, hook_style = ?, hashtag_strategy = ?, post_frequency = ?, cta_linkedin = ?,
             paragraph_style = ?, good_example = ?, bad_example = ?, custom_instructions = ?
             WHERE id = ? AND user_id = ?'
        )->execute([
            trim($_POST['ws_tone_of_voice'] ?? '') ?: null,
            trim($_POST['ws_tone_descriptors'] ?? '') ?: null,
            trim($_POST['ws_anti_tone'] ?? '') ?: null,
            trim($_POST['ws_words_always'] ?? '') ?: null,
            trim($_POST['ws_words_never'] ?? '') ?: null,
            trim($_POST['ws_post_opening_style'] ?? '') ?: null,
            trim($_POST['ws_hook_style'] ?? '') ?: null,
            trim($_POST['ws_hashtag_strategy'] ?? '') ?: null,
            trim($_POST['ws_post_frequency'] ?? '') ?: null,
            trim($_POST['ws_cta_linkedin'] ?? '') ?: null,
            in_array($_POST['ws_paragraph_style'] ?? '', ['one-liners', 'full-paragraphs', 'bullet-heavy'], true) ? $_POST['ws_paragraph_style'] : null,
            trim($_POST['ws_good_example'] ?? '') ?: null,
            trim($_POST['ws_bad_example'] ?? '') ?: null,
            trim($_POST['ws_custom_instructions'] ?? '') ?: null,
            $workspaceId, $userId,
        ]);
        flash('success', 'Tone & Voice saved.');
        redirect('pages/knowledge.php#tone');
    }

    if (($_POST['form'] ?? '') === 'seed_kb') {
        seed_default_knowledge_base($userId, $workspaceId);
        flash('success', 'Starter personas, content pillars, and CTAs added to this workspace — anything you already had was left untouched.');
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'kb_doc_upload') {
        if (empty($_FILES['kb_doc']['tmp_name']) || $_FILES['kb_doc']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Choose a PDF, Word (.docx), or text (.txt/.md) file to upload.');
            redirect('pages/knowledge.php');
        }
        if ($_FILES['kb_doc']['size'] > MAX_DOCUMENT_SIZE_BYTES) {
            flash('error', 'That file is too large — the limit is 10MB.');
            redirect('pages/knowledge.php');
        }
        $originalName = $_FILES['kb_doc']['name'];
        $contents = file_get_contents($_FILES['kb_doc']['tmp_name']);
        $kind = sniff_document_kind($contents, $originalName);
        if ($kind === null) {
            flash('error', 'Unrecognized file type — upload a PDF, Word (.docx), or plain text (.txt/.md) file.');
            redirect('pages/knowledge.php');
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
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'kb_doc_delete') {
        delete_knowledge_document($workspaceId, (int) ($_POST['doc_id'] ?? 0));
        flash('success', 'Document removed.');
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'kb_doc_summarize') {
        $doc = fetch_knowledge_document($workspaceId, (int) ($_POST['doc_id'] ?? 0));
        if (!$doc || $doc['extracted_text'] === null) {
            flash('error', 'This document has no extracted text to summarize.');
            redirect('pages/knowledge.php');
        }
        $aiConfig = resolve_ai_config($userId);
        if (!ai_configured($aiConfig)) {
            flash('error', 'Add an AI provider key in Settings first.');
            redirect('pages/knowledge.php');
        }
        try {
            $summary = ai_summarize_document($doc['extracted_text'], $aiConfig);
            db()->prepare('UPDATE knowledge_documents SET summary = ? WHERE id = ? AND workspace_id = ?')
                ->execute([$summary, $doc['id'], $workspaceId]);
            flash('success', "Summarized \"{$doc['filename']}\" — the summary (not the full text) is now used in AI context going forward.");
        } catch (Throwable $e) {
            flash('error', 'Summarization failed: ' . $e->getMessage());
        }
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'persona_add') {
        $name = trim($_POST['persona_name'] ?? '');
        $desc = trim($_POST['persona_description'] ?? '');
        if ($name === '') {
            flash('error', 'Enter a persona name.');
            redirect('pages/knowledge.php');
        }
        $seniority = in_array($_POST['persona_seniority'] ?? '', ['C-Suite', 'VP', 'Director', 'Manager', 'Individual Contributor'], true) ? $_POST['persona_seniority'] : null;
        $decisionRole = in_array($_POST['persona_decision_role'] ?? '', ['Economic Buyer', 'Champion', 'Technical Buyer', 'End User', 'Influencer', 'Blocker'], true) ? $_POST['persona_decision_role'] : null;
        $personaVerticalId = (int) ($_POST['persona_vertical_id'] ?? 0) ?: null;
        if ($personaVerticalId !== null && !fetch_vertical($workspaceId, $personaVerticalId)) {
            $personaVerticalId = null;
        }
        $personaServiceId = (int) ($_POST['persona_service_id'] ?? 0) ?: null;
        if ($personaServiceId !== null && !fetch_service($workspaceId, $personaServiceId)) {
            $personaServiceId = null;
        }
        $stmt = db()->prepare(
            'INSERT INTO personas (user_id, workspace_id, name, description, title, department, seniority,
             reporting_to, goals, pain_points, objections, kpis, decision_role, communication_style,
             preferred_content, watering_holes, content_hook, vertical_id, service_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description), workspace_id = VALUES(workspace_id),
             title = VALUES(title), department = VALUES(department), seniority = VALUES(seniority),
             reporting_to = VALUES(reporting_to), goals = VALUES(goals), pain_points = VALUES(pain_points),
             objections = VALUES(objections), kpis = VALUES(kpis), decision_role = VALUES(decision_role),
             communication_style = VALUES(communication_style), preferred_content = VALUES(preferred_content),
             watering_holes = VALUES(watering_holes), content_hook = VALUES(content_hook),
             vertical_id = VALUES(vertical_id), service_id = VALUES(service_id)'
        );
        $stmt->execute([
            $userId, $workspaceId, $name, $desc,
            trim($_POST['persona_title'] ?? '') ?: null,
            trim($_POST['persona_department'] ?? '') ?: null,
            $seniority,
            trim($_POST['persona_reporting_to'] ?? '') ?: null,
            trim($_POST['persona_goals'] ?? '') ?: null,
            trim($_POST['persona_pain_points'] ?? '') ?: null,
            trim($_POST['persona_objections'] ?? '') ?: null,
            trim($_POST['persona_kpis'] ?? '') ?: null,
            $decisionRole,
            trim($_POST['persona_communication_style'] ?? '') ?: null,
            trim($_POST['persona_preferred_content'] ?? '') ?: null,
            trim($_POST['persona_watering_holes'] ?? '') ?: null,
            trim($_POST['persona_content_hook'] ?? '') ?: null,
            $personaVerticalId,
            $personaServiceId,
        ]);
        flash('success', "Persona \"{$name}\" saved.");
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'persona_delete') {
        $stmt = db()->prepare('DELETE FROM personas WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['persona_id'] ?? 0), $userId]);
        flash('success', 'Persona removed.');
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'sender_add') {
        $fullName = trim($_POST['sender_full_name'] ?? '');
        if ($fullName === '') {
            flash('error', 'Enter a name.');
            redirect('pages/knowledge.php');
        }
        $yearsExp = trim($_POST['sender_years_experience'] ?? '');
        db()->prepare(
            'INSERT INTO senders (workspace_id, full_name, title, linkedin_headline, linkedin_about, background,
             credibility, years_experience, individual_tone, example_posts, post_topics)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $workspaceId, $fullName,
            trim($_POST['sender_title'] ?? '') ?: null,
            trim($_POST['sender_linkedin_headline'] ?? '') ?: null,
            trim($_POST['sender_linkedin_about'] ?? '') ?: null,
            trim($_POST['sender_background'] ?? '') ?: null,
            trim($_POST['sender_credibility'] ?? '') ?: null,
            $yearsExp !== '' ? (int) $yearsExp : null,
            trim($_POST['sender_individual_tone'] ?? '') ?: null,
            trim($_POST['sender_example_posts'] ?? '') ?: null,
            trim($_POST['sender_post_topics'] ?? '') ?: null,
        ]);
        $newSenderId = (int) db()->lastInsertId();
        if (count(fetch_senders($workspaceId)) === 1) {
            set_default_sender($workspaceId, $newSenderId);
        }
        flash('success', "Sender \"{$fullName}\" saved.");
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'sender_set_default') {
        set_default_sender($workspaceId, (int) ($_POST['sender_id'] ?? 0));
        flash('success', 'Default sender updated.');
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'sender_delete') {
        delete_sender($workspaceId, (int) ($_POST['sender_id'] ?? 0));
        flash('success', 'Sender removed.');
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'vertical_add') {
        $name = trim($_POST['vertical_name'] ?? '');
        if ($name === '') {
            flash('error', 'Enter a vertical name.');
            redirect('pages/knowledge.php');
        }
        $priority = in_array($_POST['vertical_priority'] ?? '', ['core', 'growth', 'emerging'], true) ? $_POST['vertical_priority'] : 'core';
        db()->prepare(
            'INSERT INTO verticals (workspace_id, name, focus, industries, priority, differentiators, head_name, positioning)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $workspaceId, $name,
            trim($_POST['vertical_focus'] ?? '') ?: null,
            trim($_POST['vertical_industries'] ?? '') ?: null,
            $priority,
            trim($_POST['vertical_differentiators'] ?? '') ?: null,
            trim($_POST['vertical_head_name'] ?? '') ?: null,
            trim($_POST['vertical_positioning'] ?? '') ?: null,
        ]);
        flash('success', "Vertical \"{$name}\" saved.");
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'vertical_delete') {
        delete_vertical($workspaceId, (int) ($_POST['vertical_id'] ?? 0));
        flash('success', 'Vertical removed.');
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'service_add') {
        $name = trim($_POST['service_name'] ?? '');
        if ($name === '') {
            flash('error', 'Enter a service name.');
            redirect('pages/knowledge.php');
        }
        $verticalId = (int) ($_POST['service_vertical_id'] ?? 0) ?: null;
        if ($verticalId !== null && !fetch_vertical($workspaceId, $verticalId)) {
            $verticalId = null;
        }
        db()->prepare(
            'INSERT INTO services (workspace_id, vertical_id, name, one_liner, industries, icp_size, buyer_titles,
             engagement_model, signal_keywords, signal_types, tech_triggers, competing_tools, description,
             problem_statement, outcomes, differentiators, proof_points)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $workspaceId, $verticalId, $name,
            trim($_POST['service_one_liner'] ?? '') ?: null,
            trim($_POST['service_industries'] ?? '') ?: null,
            trim($_POST['service_icp_size'] ?? '') ?: null,
            trim($_POST['service_buyer_titles'] ?? '') ?: null,
            trim($_POST['service_engagement_model'] ?? '') ?: null,
            trim($_POST['service_signal_keywords'] ?? '') ?: null,
            trim($_POST['service_signal_types'] ?? '') ?: null,
            trim($_POST['service_tech_triggers'] ?? '') ?: null,
            trim($_POST['service_competing_tools'] ?? '') ?: null,
            trim($_POST['service_description'] ?? '') ?: null,
            trim($_POST['service_problem_statement'] ?? '') ?: null,
            trim($_POST['service_outcomes'] ?? '') ?: null,
            trim($_POST['service_differentiators'] ?? '') ?: null,
            trim($_POST['service_proof_points'] ?? '') ?: null,
        ]);
        flash('success', "Service \"{$name}\" saved.");
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'service_delete') {
        delete_service($workspaceId, (int) ($_POST['service_id'] ?? 0));
        flash('success', 'Service removed.');
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'icp_add') {
        $name = trim($_POST['icp_name'] ?? '');
        if ($name === '') {
            flash('error', 'Enter an ICP name.');
            redirect('pages/knowledge.php');
        }
        $verticalId = (int) ($_POST['icp_vertical_id'] ?? 0) ?: null;
        if ($verticalId !== null && !fetch_vertical($workspaceId, $verticalId)) {
            $verticalId = null;
        }
        $serviceId = (int) ($_POST['icp_service_id'] ?? 0) ?: null;
        if ($serviceId !== null && !fetch_service($workspaceId, $serviceId)) {
            $serviceId = null;
        }
        db()->prepare(
            'INSERT INTO icps (workspace_id, vertical_id, service_id, name, size_range, revenue_range, industries,
             geographies, tech_stack_signals, trigger_events, perfect_fit, poor_fit, disqualifiers, buying_process)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $workspaceId, $verticalId, $serviceId, $name,
            trim($_POST['icp_size_range'] ?? '') ?: null,
            trim($_POST['icp_revenue_range'] ?? '') ?: null,
            trim($_POST['icp_industries'] ?? '') ?: null,
            trim($_POST['icp_geographies'] ?? '') ?: null,
            trim($_POST['icp_tech_stack_signals'] ?? '') ?: null,
            trim($_POST['icp_trigger_events'] ?? '') ?: null,
            trim($_POST['icp_perfect_fit'] ?? '') ?: null,
            trim($_POST['icp_poor_fit'] ?? '') ?: null,
            trim($_POST['icp_disqualifiers'] ?? '') ?: null,
            trim($_POST['icp_buying_process'] ?? '') ?: null,
        ]);
        flash('success', "ICP \"{$name}\" saved.");
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'icp_delete') {
        delete_icp($workspaceId, (int) ($_POST['icp_id'] ?? 0));
        flash('success', 'ICP removed.');
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'proof_point_add') {
        $clientName = trim($_POST['proof_client_name'] ?? '');
        if ($clientName === '') {
            flash('error', 'Enter a client name.');
            redirect('pages/knowledge.php');
        }
        $verticalId = (int) ($_POST['proof_vertical_id'] ?? 0) ?: null;
        if ($verticalId !== null && !fetch_vertical($workspaceId, $verticalId)) {
            $verticalId = null;
        }
        $serviceId = (int) ($_POST['proof_service_id'] ?? 0) ?: null;
        if ($serviceId !== null && !fetch_service($workspaceId, $serviceId)) {
            $serviceId = null;
        }
        db()->prepare(
            'INSERT INTO proof_points (workspace_id, vertical_id, service_id, client_name, client_industry,
             client_size, challenge, solution, outcomes, metrics, quote, quote_attribution, is_public)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $workspaceId, $verticalId, $serviceId, $clientName,
            trim($_POST['proof_client_industry'] ?? '') ?: null,
            trim($_POST['proof_client_size'] ?? '') ?: null,
            trim($_POST['proof_challenge'] ?? '') ?: null,
            trim($_POST['proof_solution'] ?? '') ?: null,
            trim($_POST['proof_outcomes'] ?? '') ?: null,
            trim($_POST['proof_metrics'] ?? '') ?: null,
            trim($_POST['proof_quote'] ?? '') ?: null,
            trim($_POST['proof_quote_attribution'] ?? '') ?: null,
            isset($_POST['proof_is_public']) ? 1 : 0,
        ]);
        flash('success', "Proof point for \"{$clientName}\" saved.");
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'proof_point_delete') {
        delete_proof_point($workspaceId, (int) ($_POST['proof_point_id'] ?? 0));
        flash('success', 'Proof point removed.');
        redirect('pages/knowledge.php');
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
            redirect('pages/knowledge.php');
        }
        $stmt = db()->prepare(
            'INSERT INTO content_pillars (user_id, workspace_id, name, description, category, default_layout, default_palette) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description), category = VALUES(category), default_layout = VALUES(default_layout), default_palette = VALUES(default_palette), workspace_id = VALUES(workspace_id)'
        );
        $stmt->execute([$userId, $workspaceId, $name, $desc, $category, $layout, $palette]);
        flash('success', "Content pillar \"{$name}\" saved.");
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'pillar_delete') {
        $stmt = db()->prepare('DELETE FROM content_pillars WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['pillar_id'] ?? 0), $userId]);
        flash('success', 'Content pillar removed.');
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'cta_add') {
        $text = trim($_POST['cta_text'] ?? '');
        $stage = $_POST['cta_funnel_stage'] ?? '';
        $stage = in_array($stage, ['Awareness', 'Consideration', 'Decision', 'Retention'], true) ? $stage : null;
        if ($text === '') {
            flash('error', 'Enter the CTA text.');
            redirect('pages/knowledge.php');
        }
        $stmt = db()->prepare('INSERT INTO cta_library (user_id, workspace_id, text, funnel_stage) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $workspaceId, $text, $stage]);
        flash('success', 'CTA added to your library.');
        redirect('pages/knowledge.php');
    }

    if (($_POST['form'] ?? '') === 'cta_delete') {
        $stmt = db()->prepare('DELETE FROM cta_library WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['cta_id'] ?? 0), $userId]);
        flash('success', 'CTA removed.');
        redirect('pages/knowledge.php');
    }

    flash('error', 'Unrecognized action.');
    redirect('pages/knowledge.php');
}

$tagDirectory = fetch_tag_directory($userId);
$personas = fetch_personas($userId, $workspaceId);
$senders = fetch_senders($workspaceId);
$verticals = fetch_verticals($workspaceId);
$services = fetch_services($workspaceId);
$icps = fetch_icps($workspaceId);
$proofPoints = fetch_proof_points($workspaceId);
$contentPillars = fetch_content_pillars($userId, $workspaceId);
$knowledgeDocuments = fetch_knowledge_documents($workspaceId);
$ctaLibrary = fetch_cta_library($userId, $workspaceId);
$funnelStages = ['Awareness', 'Consideration', 'Decision', 'Retention'];
$brandPalettes = fetch_brand_palettes($userId);

$pageTitle  = 'Knowledge Base';
$activePage = 'knowledge';
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>Knowledge Base — <?= h($workspace['name']) ?></h1></div>
<p class="muted" style="margin-top:-14px; margin-bottom:20px;">Everything here is scoped to the active workspace — switch workspaces with the selector at the top of the sidebar to edit a different one's knowledge base. This is the context every AI generation in this workspace automatically draws on.</p>

<nav class="settings-tabs" id="kbTabs">
  <button type="button" class="settings-tab-btn" data-tab-target="company">Company</button>
  <button type="button" class="settings-tab-btn" data-tab-target="verticals">Verticals</button>
  <button type="button" class="settings-tab-btn" data-tab-target="services">Services</button>
  <button type="button" class="settings-tab-btn" data-tab-target="icps">ICPs</button>
  <button type="button" class="settings-tab-btn" data-tab-target="personas">Personas</button>
  <button type="button" class="settings-tab-btn" data-tab-target="tone">Tone &amp; Voice</button>
  <button type="button" class="settings-tab-btn" data-tab-target="senders">Senders</button>
  <button type="button" class="settings-tab-btn" data-tab-target="proof">Proof Points</button>
  <button type="button" class="settings-tab-btn" data-tab-target="documents">Documents</button>
  <button type="button" class="settings-tab-btn" data-tab-target="pillars">Content Pillars</button>
  <button type="button" class="settings-tab-btn" data-tab-target="cta">CTA Library</button>
  <button type="button" class="settings-tab-btn" data-tab-target="tags">Tag Directory</button>
</nav>

<section class="card" data-tab="company">
  <h2>Company Identity — <?= h($workspace['name']) ?></h2>
  <p class="muted">This is who <?= $workspace['type'] === 'personal' ? 'you are' : 'this company is' ?> — every AI generation in this workspace automatically receives all of it as context, so the more you fill in, the more on-voice the content.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="workspace_profile_company">
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
    <label>Content goals
      <textarea name="ws_goals" rows="2" placeholder="e.g. Build authority in predictive maintenance; generate demo calls"><?= h($workspace['goals'] ?? '') ?></textarea>
    </label>
    <label>Content rules — do's &amp; don'ts <span class="muted">(followed strictly by the AI)</span>
      <textarea name="ws_content_rules" rows="3" placeholder="e.g. Never mention competitors by name. Always use metric units. No emojis in captions."><?= h($workspace['content_rules'] ?? '') ?></textarea>
    </label>
    <label>Website
      <input type="text" name="ws_website" value="<?= h($workspace['website'] ?? '') ?>" placeholder="https://example.com">
    </label>

    <details class="kb-details">
      <summary><?= $workspace['type'] === 'personal' ? 'More about you' : 'More about the company' ?> <span class="muted">(optional — richer identity context)</span></summary>
      <label>Tagline
        <input type="text" name="ws_tagline" value="<?= h($workspace['tagline'] ?? '') ?>" placeholder="e.g. Predictive maintenance, made simple">
      </label>
      <label>Founded
        <input type="text" name="ws_founded_year" value="<?= h($workspace['founded_year'] ?? '') ?>" placeholder="e.g. 2018">
      </label>
      <label><?= $workspace['type'] === 'personal' ? 'Years of experience' : 'Company size' ?>
        <input type="text" name="ws_company_size" value="<?= h($workspace['company_size'] ?? '') ?>" placeholder="e.g. 200-500 employees">
      </label>
      <label>Headquarters / based in
        <input type="text" name="ws_hq_location" value="<?= h($workspace['hq_location'] ?? '') ?>" placeholder="e.g. Austin, TX">
      </label>
      <label>Mission
        <textarea name="ws_mission" rows="2"><?= h($workspace['mission'] ?? '') ?></textarea>
      </label>
      <label>Vision
        <textarea name="ws_vision" rows="2"><?= h($workspace['vision'] ?? '') ?></textarea>
      </label>
      <label>Story
        <textarea name="ws_story" rows="3" placeholder="The longer origin story — used sparingly, mostly for longer-form content."><?= h($workspace['story'] ?? '') ?></textarea>
      </label>
      <label>Credibility statement <span class="muted">(2-3 sentences the AI can use nearly as-is)</span>
        <textarea name="ws_credibility_statement" rows="2" placeholder="e.g. We've helped 40+ manufacturers cut unplanned downtime by an average of 30%, backed by a decade of field data."><?= h($workspace['credibility_statement'] ?? '') ?></textarea>
      </label>
      <label>Notable clients
        <textarea name="ws_notable_clients" rows="2"><?= h($workspace['notable_clients'] ?? '') ?></textarea>
      </label>
      <label>Awards / recognition
        <textarea name="ws_awards" rows="2"><?= h($workspace['awards'] ?? '') ?></textarea>
      </label>
    </details>

    <button type="submit" class="btn-primary">Save Company Profile</button>
  </form>
</section>

<section class="card" data-tab="verticals">
  <h2>Verticals — <?= h($workspace['name']) ?></h2>
  <p class="muted">Business units, practice areas, or focus areas <?= $workspace['type'] === 'personal' ? "you're known for" : 'this company operates in' ?>. Services (below) and ideal customer profiles can optionally attach to one, so content stays on-topic for that area's audience.</p>
  <?php if ($verticals): ?>
    <?php foreach ($verticals as $v): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($v['name']) ?> <span class="badge"><?= h($v['priority']) ?></span></span>
          <span class="muted"><?= h(mb_strimwidth($v['focus'] ?? '', 0, 100, '…')) ?></span>
        </div>
        <form method="post" onsubmit="return confirm('Remove this vertical?');">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="vertical_delete">
          <input type="hidden" name="vertical_id" value="<?= (int) $v['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No verticals added yet.</p>
  <?php endif; ?>
  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="vertical_add">
    <label>Name
      <input type="text" name="vertical_name" placeholder="e.g. ERP Practice" required>
    </label>
    <label>Focus <span class="muted">(what this area specialises in)</span>
      <textarea name="vertical_focus" rows="2"></textarea>
    </label>
    <label>Priority
      <select name="vertical_priority">
        <option value="core" selected>Core</option>
        <option value="growth">Growth</option>
        <option value="emerging">Emerging</option>
      </select>
    </label>
    <details class="kb-details">
      <summary>More details <span class="muted">(optional)</span></summary>
      <label>Industries <span class="muted">(comma-separated)</span>
        <input type="text" name="vertical_industries" placeholder="e.g. Manufacturing, Retail">
      </label>
      <label>Differentiators
        <textarea name="vertical_differentiators" rows="2"></textarea>
      </label>
      <label>Lead / head of this area
        <input type="text" name="vertical_head_name">
      </label>
      <label>Positioning statement
        <textarea name="vertical_positioning" rows="2" placeholder="e.g. We implement ERP faster with fewer change requests"></textarea>
      </label>
    </details>
    <button type="submit" class="btn-secondary">Add Vertical</button>
  </form>
</section>

<section class="card" data-tab="services">
  <h2>Services — <?= h($workspace['name']) ?></h2>
  <p class="muted"><?= $workspace['type'] === 'personal' ? 'What you offer' : 'What this company sells' ?>, with enough detail that the AI can pitch the right one to the right audience instead of writing generically.</p>
  <?php if ($services): ?>
    <?php foreach ($services as $sv): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($sv['name']) ?></span>
          <span class="muted"><?= h(mb_strimwidth($sv['one_liner'] ?? '', 0, 100, '…')) ?></span>
        </div>
        <form method="post" onsubmit="return confirm('Remove this service?');">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="service_delete">
          <input type="hidden" name="service_id" value="<?= (int) $sv['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No services added yet.</p>
  <?php endif; ?>
  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="service_add">
    <label>Name
      <input type="text" name="service_name" placeholder="e.g. SAP S/4 Migration" required>
    </label>
    <label>One-liner pitch
      <input type="text" name="service_one_liner" placeholder="e.g. We move companies from SAP ECC to S/4HANA in 9 months">
    </label>
    <?php if ($verticals): ?>
      <label>Vertical <span class="muted">(optional)</span>
        <select name="service_vertical_id">
          <option value="">— None —</option>
          <?php foreach ($verticals as $v): ?>
            <option value="<?= (int) $v['id'] ?>"><?= h($v['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <details class="kb-details">
      <summary>More details <span class="muted">(optional — sharpens who this gets pitched to)</span></summary>
      <label>Industries <span class="muted">(comma-separated)</span>
        <input type="text" name="service_industries" placeholder="e.g. Manufacturing, Logistics">
      </label>
      <label>ICP size
        <input type="text" name="service_icp_size" placeholder="e.g. 500-5000 employees">
      </label>
      <label>Buyer titles <span class="muted">(comma-separated)</span>
        <input type="text" name="service_buyer_titles" placeholder="e.g. CIO, VP IT, SAP Program Manager">
      </label>
      <label>Engagement model
        <input type="text" name="service_engagement_model" placeholder="e.g. Fixed fee, Retainer, T&amp;M">
      </label>
      <label>Signal keywords <span class="muted">(comma-separated — used to detect a relevant trending topic)</span>
        <input type="text" name="service_signal_keywords" placeholder="e.g. sap ecc, legacy erp, s4hana, ecc upgrade">
      </label>
      <label>Signal types <span class="muted">(comma-separated)</span>
        <input type="text" name="service_signal_types" placeholder="e.g. ERP, Digital Transformation">
      </label>
      <label>Tech triggers <span class="muted">(comma-separated)</span>
        <input type="text" name="service_tech_triggers" placeholder="e.g. SAP ECC, R3, ECC 6.0">
      </label>
      <label>Competing tools
        <textarea name="service_competing_tools" rows="2"></textarea>
      </label>
      <label>Description
        <textarea name="service_description" rows="2"></textarea>
      </label>
      <label>Problem statement
        <textarea name="service_problem_statement" rows="2" placeholder="e.g. Companies on SAP ECC face end of maintenance in 2027 with no clear path forward"></textarea>
      </label>
      <label>Outcomes
        <textarea name="service_outcomes" rows="2" placeholder="e.g. Live on S/4HANA in 9 months. 30% lower TCO."></textarea>
      </label>
      <label>Differentiators
        <textarea name="service_differentiators" rows="2"></textarea>
      </label>
      <label>Proof points <span class="muted">(quick summary — full case studies live in Proof Points, once added)</span>
        <textarea name="service_proof_points" rows="2"></textarea>
      </label>
    </details>
    <button type="submit" class="btn-secondary">Add Service</button>
  </form>
</section>

<section class="card" data-tab="icps">
  <h2>Ideal Customer Profiles — <?= h($workspace['name']) ?></h2>
  <p class="muted">Who the perfect <?= $workspace['type'] === 'personal' ? 'reader/client' : 'customer' ?> is — company-level fit criteria, separate from an individual Persona (a role within that company).</p>
  <?php if ($icps): ?>
    <?php foreach ($icps as $ic): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($ic['name']) ?></span>
          <span class="muted"><?= h(mb_strimwidth($ic['perfect_fit'] ?? '', 0, 100, '…')) ?></span>
        </div>
        <form method="post" onsubmit="return confirm('Remove this ICP?');">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="icp_delete">
          <input type="hidden" name="icp_id" value="<?= (int) $ic['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No ICPs added yet.</p>
  <?php endif; ?>
  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="icp_add">
    <label>Name
      <input type="text" name="icp_name" placeholder="e.g. Mid-market Manufacturing CIO" required>
    </label>
    <?php if ($verticals || $services): ?>
      <?php if ($verticals): ?>
        <label>Vertical <span class="muted">(optional)</span>
          <select name="icp_vertical_id">
            <option value="">— None —</option>
            <?php foreach ($verticals as $v): ?>
              <option value="<?= (int) $v['id'] ?>"><?= h($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
      <?php if ($services): ?>
        <label>Service <span class="muted">(optional)</span>
          <select name="icp_service_id">
            <option value="">— None —</option>
            <?php foreach ($services as $sv): ?>
              <option value="<?= (int) $sv['id'] ?>"><?= h($sv['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
    <?php endif; ?>
    <label>Perfect fit
      <textarea name="icp_perfect_fit" rows="2" placeholder="e.g. On SAP ECC, under 5000 employees, has internal IT team"></textarea>
    </label>
    <details class="kb-details">
      <summary>More details <span class="muted">(optional)</span></summary>
      <label>Size range
        <input type="text" name="icp_size_range" placeholder="e.g. 500-5000 employees">
      </label>
      <label>Revenue range
        <input type="text" name="icp_revenue_range" placeholder="e.g. $100M-$1B">
      </label>
      <label>Industries <span class="muted">(comma-separated)</span>
        <input type="text" name="icp_industries" placeholder="e.g. Manufacturing, Industrial">
      </label>
      <label>Geographies <span class="muted">(comma-separated)</span>
        <input type="text" name="icp_geographies" placeholder="e.g. US, UK, Germany">
      </label>
      <label>Tech stack signals
        <input type="text" name="icp_tech_stack_signals">
      </label>
      <label>Trigger events
        <textarea name="icp_trigger_events" rows="2" placeholder="e.g. ECC end of maintenance announcement, merger, new CIO hire"></textarea>
      </label>
      <label>Poor fit
        <textarea name="icp_poor_fit" rows="2" placeholder="e.g. Greenfield with no ERP, under 100 employees"></textarea>
      </label>
      <label>Disqualifiers
        <input type="text" name="icp_disqualifiers" placeholder="e.g. Already on S/4HANA, using Oracle, no budget cycle">
      </label>
      <label>Buying process
        <textarea name="icp_buying_process" rows="2"></textarea>
      </label>
    </details>
    <button type="submit" class="btn-secondary">Add ICP</button>
  </form>
</section>

<section class="card" data-tab="personas">
  <h2>Personas, Pillars &amp; CTAs</h2>
  <p class="muted">Every new account starts with a generic starter set of personas, content pillars, and CTAs (editable/removable below). If you deleted them or never got them, load them again any time — this only adds what's missing, it never duplicates.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="seed_kb">
    <button type="submit" class="btn-secondary">Load Starter Content</button>
  </form>
</section>

<section class="card" data-tab="personas">
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
    <details class="kb-details">
      <summary>Advanced <span class="muted">(optional — sharpens targeting and hooks)</span></summary>
      <label>Title
        <input type="text" name="persona_title" placeholder="e.g. VP of Operations">
      </label>
      <label>Department
        <input type="text" name="persona_department">
      </label>
      <label>Seniority
        <select name="persona_seniority">
          <option value="">— Unset —</option>
          <option value="C-Suite">C-Suite</option>
          <option value="VP">VP</option>
          <option value="Director">Director</option>
          <option value="Manager">Manager</option>
          <option value="Individual Contributor">Individual Contributor</option>
        </select>
      </label>
      <label>Reports to
        <input type="text" name="persona_reporting_to">
      </label>
      <label>Goals
        <textarea name="persona_goals" rows="2"></textarea>
      </label>
      <label>Pain points
        <textarea name="persona_pain_points" rows="2"></textarea>
      </label>
      <label>Objections <span class="muted">(reasons they'd push back or hesitate)</span>
        <textarea name="persona_objections" rows="2"></textarea>
      </label>
      <label>KPIs they're measured on
        <input type="text" name="persona_kpis">
      </label>
      <label>Decision role
        <select name="persona_decision_role">
          <option value="">— Unset —</option>
          <option value="Economic Buyer">Economic Buyer</option>
          <option value="Champion">Champion</option>
          <option value="Technical Buyer">Technical Buyer</option>
          <option value="End User">End User</option>
          <option value="Influencer">Influencer</option>
          <option value="Blocker">Blocker</option>
        </select>
      </label>
      <label>Communication style
        <textarea name="persona_communication_style" rows="2"></textarea>
      </label>
      <label>Responds best to <span class="muted">(content format/angle)</span>
        <input type="text" name="persona_preferred_content" placeholder="e.g. Short, data-backed posts with a clear number in the hook">
      </label>
      <label>Where they hang out <span class="muted">(communities, hashtags)</span>
        <input type="text" name="persona_watering_holes">
      </label>
      <label>Good hook angle for this persona
        <textarea name="persona_content_hook" rows="2"></textarea>
      </label>
      <?php if ($verticals): ?>
        <label>Vertical <span class="muted">(optional)</span>
          <select name="persona_vertical_id">
            <option value="">— None —</option>
            <?php foreach ($verticals as $v): ?>
              <option value="<?= (int) $v['id'] ?>"><?= h($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
      <?php if ($services): ?>
        <label>Service <span class="muted">(optional)</span>
          <select name="persona_service_id">
            <option value="">— None —</option>
            <?php foreach ($services as $sv): ?>
              <option value="<?= (int) $sv['id'] ?>"><?= h($sv['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
    </details>
    <button type="submit" class="btn-secondary">Add Persona</button>
  </form>
</section>

<section class="card" data-tab="tone">
  <h2>Tone &amp; Voice — <?= h($workspace['name']) ?></h2>
  <p class="muted">How <?= $workspace['type'] === 'personal' ? 'you write' : 'this company writes' ?> — the fastest way to stop AI-generated posts sounding generic.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="workspace_profile_tone">
    <label>Tone of voice
      <input type="text" name="ws_tone_of_voice" value="<?= h($workspace['tone_of_voice'] ?? '') ?>" placeholder="e.g. Direct, practical, numbers over adjectives, never salesy">
    </label>
    <label>Tone descriptors
      <input type="text" name="ws_tone_descriptors" value="<?= h($workspace['tone_descriptors'] ?? '') ?>" placeholder="e.g. Direct, authoritative, no jargon">
    </label>
    <label>Avoid this tone
      <input type="text" name="ws_anti_tone" value="<?= h($workspace['anti_tone'] ?? '') ?>" placeholder="e.g. Never salesy, never vague">
    </label>
    <label>Words to favor
      <textarea name="ws_words_always" rows="2" placeholder="e.g. outcomes, measurable, practical"><?= h($workspace['words_always'] ?? '') ?></textarea>
    </label>
    <label>Words to never use <span class="muted">(followed strictly by the AI)</span>
      <textarea name="ws_words_never" rows="2" placeholder="e.g. synergy, leverage, disrupt, game-changer"><?= h($workspace['words_never'] ?? '') ?></textarea>
    </label>
    <label>Post opening style
      <textarea name="ws_post_opening_style" rows="2" placeholder="e.g. Lead with a relevant observation or number, not a pitch"><?= h($workspace['post_opening_style'] ?? '') ?></textarea>
    </label>
    <label>Hook style
      <textarea name="ws_hook_style" rows="2" placeholder="e.g. Start with a contrarian statement or a surprising number"><?= h($workspace['hook_style'] ?? '') ?></textarea>
    </label>
    <label>Hashtag strategy
      <input type="text" name="ws_hashtag_strategy" value="<?= h($workspace['hashtag_strategy'] ?? '') ?>" placeholder="e.g. 3 max, always include one niche tag">
    </label>
    <label>Posting frequency
      <input type="text" name="ws_post_frequency" value="<?= h($workspace['post_frequency'] ?? '') ?>" placeholder="e.g. 3x per week, Mon/Wed/Fri">
    </label>
    <label>LinkedIn CTA style
      <textarea name="ws_cta_linkedin" rows="2" placeholder="e.g. End with a question to drive comments"><?= h($workspace['cta_linkedin'] ?? '') ?></textarea>
    </label>
    <label>Paragraph style
      <select name="ws_paragraph_style">
        <option value="">— Unset —</option>
        <option value="one-liners"<?= ($workspace['paragraph_style'] ?? '') === 'one-liners' ? ' selected' : '' ?>>One-liners</option>
        <option value="full-paragraphs"<?= ($workspace['paragraph_style'] ?? '') === 'full-paragraphs' ? ' selected' : '' ?>>Full paragraphs</option>
        <option value="bullet-heavy"<?= ($workspace['paragraph_style'] ?? '') === 'bullet-heavy' ? ' selected' : '' ?>>Bullet-heavy</option>
      </select>
    </label>
    <label>A real example to mirror <span class="muted">(paste an actual post)</span>
      <textarea name="ws_good_example" rows="4"><?= h($workspace['good_example'] ?? '') ?></textarea>
    </label>
    <label>An example to avoid writing like
      <textarea name="ws_bad_example" rows="4"><?= h($workspace['bad_example'] ?? '') ?></textarea>
    </label>
    <label>Custom instructions <span class="muted">(anything else, appended to every AI prompt for this workspace)</span>
      <textarea name="ws_custom_instructions" rows="3"><?= h($workspace['custom_instructions'] ?? '') ?></textarea>
    </label>
    <button type="submit" class="btn-primary">Save Tone &amp; Voice</button>
  </form>
</section>

<section class="card" data-tab="senders">
  <h2>Senders — <?= h($workspace['name']) ?></h2>
  <p class="muted">Who this workspace's posts are written as. <?= $workspace['type'] === 'personal' ? 'For a personal workspace this is usually just you.' : 'A company workspace can have several, e.g. different people ghostwriting under the same brand.' ?> The default sender's voice (tone + real example posts) is automatically woven into every AI generation in this workspace — this is the single fastest way to stop posts sounding like generic AI.</p>
  <?php if ($senders): ?>
    <?php foreach ($senders as $s): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($s['full_name']) ?><?= $s['is_default'] ? ' <span class="badge badge-active">Default</span>' : '' ?></span>
          <span class="muted"><?= h($s['title'] ?? '') ?></span>
        </div>
        <div style="display:flex; gap:6px;">
          <?php if (!$s['is_default']): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($token) ?>">
              <input type="hidden" name="form" value="sender_set_default">
              <input type="hidden" name="sender_id" value="<?= (int) $s['id'] ?>">
              <button type="submit" class="btn-tiny">Make Default</button>
            </form>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Remove this sender?');">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="form" value="sender_delete">
            <input type="hidden" name="sender_id" value="<?= (int) $s['id'] ?>">
            <button type="submit" class="btn-tiny btn-danger">Remove</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No senders added yet.</p>
  <?php endif; ?>
  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="sender_add">
    <label>Full name
      <input type="text" name="sender_full_name" placeholder="e.g. Jane Smith" required>
    </label>
    <label>Title
      <input type="text" name="sender_title" placeholder="e.g. Managing Director">
    </label>
    <label>Individual tone <span class="muted">(how THIS person specifically writes — critical for sounding real)</span>
      <textarea name="sender_individual_tone" rows="2" placeholder="e.g. Direct and data-led. Short sentences. Always references a specific number or trend."></textarea>
    </label>
    <label>Example posts <span class="muted">(paste 2-3 real LinkedIn posts as style examples)</span>
      <textarea name="sender_example_posts" rows="4"></textarea>
    </label>
    <details class="kb-details">
      <summary>More details <span class="muted">(optional)</span></summary>
      <label>LinkedIn headline
        <input type="text" name="sender_linkedin_headline">
      </label>
      <label>LinkedIn "About" section
        <textarea name="sender_linkedin_about" rows="3"></textarea>
      </label>
      <label>Background <span class="muted">(career summary for AI context)</span>
        <textarea name="sender_background" rows="2"></textarea>
      </label>
      <label>Credibility <span class="muted">(why this person is worth listening to)</span>
        <textarea name="sender_credibility" rows="2"></textarea>
      </label>
      <label>Years of experience
        <input type="number" name="sender_years_experience" min="0">
      </label>
      <label>Post topics <span class="muted">(comma-separated)</span>
        <input type="text" name="sender_post_topics" placeholder="e.g. ERP migration, SAP tips, leadership">
      </label>
    </details>
    <button type="submit" class="btn-secondary">Add Sender</button>
  </form>
</section>

<section class="card" data-tab="proof">
  <h2>Proof Points — <?= h($workspace['name']) ?></h2>
  <p class="muted">Real <?= $workspace['type'] === 'personal' ? 'wins/results' : 'client outcomes' ?> the AI can cite as social proof.</p>
  <?php if ($proofPoints): ?>
    <?php foreach ($proofPoints as $pp): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($pp['client_name']) ?></span>
          <span class="muted"><?= h(mb_strimwidth($pp['metrics'] ?? $pp['outcomes'] ?? '', 0, 100, '…')) ?></span>
        </div>
        <form method="post" onsubmit="return confirm('Remove this proof point?');">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="proof_point_delete">
          <input type="hidden" name="proof_point_id" value="<?= (int) $pp['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No proof points added yet.</p>
  <?php endif; ?>
  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="proof_point_add">
    <label>Client name
      <input type="text" name="proof_client_name" placeholder="e.g. Acme Manufacturing" required>
    </label>
    <label>Metrics <span class="muted">(the headline number)</span>
      <input type="text" name="proof_metrics" placeholder="e.g. Reduced close time by 40%, saved $2M">
    </label>
    <?php if ($verticals || $services): ?>
      <?php if ($verticals): ?>
        <label>Vertical <span class="muted">(optional)</span>
          <select name="proof_vertical_id">
            <option value="">— None —</option>
            <?php foreach ($verticals as $v): ?>
              <option value="<?= (int) $v['id'] ?>"><?= h($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
      <?php if ($services): ?>
        <label>Service <span class="muted">(optional)</span>
          <select name="proof_service_id">
            <option value="">— None —</option>
            <?php foreach ($services as $sv): ?>
              <option value="<?= (int) $sv['id'] ?>"><?= h($sv['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
    <?php endif; ?>
    <details class="kb-details">
      <summary>More details <span class="muted">(optional)</span></summary>
      <label>Client industry
        <input type="text" name="proof_client_industry">
      </label>
      <label>Client size
        <input type="text" name="proof_client_size" placeholder="e.g. 500-5000 employees">
      </label>
      <label>Challenge
        <textarea name="proof_challenge" rows="2"></textarea>
      </label>
      <label>Solution
        <textarea name="proof_solution" rows="2"></textarea>
      </label>
      <label>Outcomes
        <textarea name="proof_outcomes" rows="2"></textarea>
      </label>
      <label>Quote
        <textarea name="proof_quote" rows="2"></textarea>
      </label>
      <label>Quote attribution
        <input type="text" name="proof_quote_attribution" placeholder="e.g. Jane Doe, VP Operations">
      </label>
      <label class="checkbox-row"><input type="checkbox" name="proof_is_public" checked> OK to share publicly</label>
    </details>
    <button type="submit" class="btn-secondary">Add Proof Point</button>
  </form>
</section>

<section class="card" data-tab="documents">
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

<section class="card" data-tab="pillars">
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
    <label>Design Template for this pillar <span class="muted">(optional — overrides your Single Image/Carousel defaults in Settings for posts tagged with this pillar; re-saving this pillar without picking one clears the override back to Auto)</span>
      <select name="pillar_layout">
        <option value="">Auto (use my Single Image/Carousel default)</option>
        <?php foreach (render_design_templates() as $tid => $t): ?>
          <option value="<?= h($tid) ?>"><?= h($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Color Palette for this pillar <span class="muted">(optional — overrides your Single Image/Carousel palette defaults in Settings for posts tagged with this pillar)</span>
      <select name="pillar_palette">
        <?= render_palette_select_options('', $brandPalettes, true) ?>
      </select>
    </label>
    <button type="submit" class="btn-secondary">Add Content Pillar</button>
  </form>
</section>

<section class="card" data-tab="cta">
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

<section class="card" data-tab="tags">
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
  (function () {
    var VALID_TABS = ['company', 'verticals', 'services', 'icps', 'personas', 'tone', 'senders', 'proof', 'documents', 'pillars', 'cta', 'tags'];
    var tabBtns = document.querySelectorAll('#kbTabs .settings-tab-btn');
    var panels = document.querySelectorAll('[data-tab]');

    function activate(tab) {
      if (VALID_TABS.indexOf(tab) === -1) tab = VALID_TABS[0];
      tabBtns.forEach(function (btn) {
        btn.classList.toggle('active', btn.dataset.tabTarget === tab);
      });
      panels.forEach(function (panel) {
        panel.style.display = panel.dataset.tab === tab ? '' : 'none';
      });
      try { localStorage.setItem('kbActiveTab', tab); } catch (e) {}
    }

    tabBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        activate(btn.dataset.tabTarget);
        history.replaceState(null, '', '#' + btn.dataset.tabTarget);
      });
    });

    var initial = (location.hash || '').replace('#', '');
    if (VALID_TABS.indexOf(initial) === -1) {
      try { initial = localStorage.getItem('kbActiveTab') || VALID_TABS[0]; } catch (e) { initial = VALID_TABS[0]; }
    }
    activate(initial);
  })();
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
