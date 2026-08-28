<?php
// Ported from the local Python prototype's generate.py — used by Content
// Studio and New Post's "Generate with AI" whenever there's no
// pre-written Creative Content but enough context (Topic / Title,
// Target Persona, Type, CTA) is present to ask an AI to write the copy.
//
// Supports 3 providers (Gemini, Claude, OpenAI) behind one shared
// dispatcher — see generate_creative_via_ai(). Which provider/key/model
// to use for a given user is resolved by resolve_ai_config() in
// includes/helpers.php (per-user preference + key, falling back to an
// admin-configured default — see config.sample.php). All three provider
// calls return the identical JSON shape includes/creative_builder.php
// also produces, so every path feeds includes/image_renderer.php
// identically.

const AI_PROVIDER_LABELS = ['gemini' => 'Gemini', 'claude' => 'Claude', 'openai' => 'OpenAI'];

function ai_configured(array $aiConfig): bool
{
    return !empty($aiConfig['api_key']);
}

// Legacy name kept as a thin alias — existing call sites/tests reference
// gemini_configured() specifically for the Gemini key.
function gemini_configured(?string $apiKey): bool
{
    return $apiKey !== null && trim($apiKey) !== '';
}

// Brand context injected ahead of the POST DETAILS section when the user
// has a brand brief and/or picked a persona/content pillar from their
// Content Knowledge Base (see includes/post_helpers.php fetch_personas()
// etc.) — richer than the short "Target Audience:" label alone.
// $workspace (a workspaces row, see includes/workspace.php) supersedes
// $brandBrief: its profile fields (about/industry/audience/tone/goals/
// rules) plus any uploaded reference documents become the context. The
// $brandBrief param remains for legacy callers without a workspace.
// $relatedMemory (see includes/content_memory.php
// content_memory_related_for_topic()) is this workspace's own past
// posts most similar to the new topic — empty when Memory & Context
// isn't active (Claude-only accounts have no embeddings endpoint) or
// there's simply no history yet.
function build_context_block(?string $brandBrief, ?array $persona, ?array $pillar, ?array $workspace = null, array $relatedMemory = [], ?array $service = null): string
{
    $parts = [];
    if ($workspace) {
        // KB expansion Phase 1 (docs/KNOWLEDGE_BASE.md) — sender voice
        // goes first, matching the design doc's stated prompt order
        // (who's writing, before who we are / what we offer).
        $sender = fetch_default_sender((int) $workspace['id']);
        $senderText = sender_context_text($sender);
        if ($senderText !== '') {
            $parts[] = $senderText;
        }
        $profile = workspace_context_text($workspace);
        if ($profile !== '') {
            $parts[] = $profile;
        }
        $docs = workspace_documents_text((int) $workspace['id']);
        if ($docs !== '') {
            $parts[] = $docs;
        }
    } elseif ($brandBrief) {
        $parts[] = "Brand context: {$brandBrief}";
    }
    // KB expansion Phase 9 (docs/KNOWLEDGE_BASE.md) — the service being
    // pitched (Block 3), positioned right after Company/Tone per the
    // design doc's prompt order. Omitted entirely when no service is
    // selected, same graceful-degradation pattern as everything else here.
    $serviceText = service_context_text($service);
    if ($serviceText !== '') {
        $parts[] = $serviceText;
    }
    if ($persona && !empty($persona['description'])) {
        $parts[] = "Target persona \"{$persona['name']}\": {$persona['description']}";
    }
    // KB expansion Phase 2 (docs/KNOWLEDGE_BASE.md) — richer persona
    // fields, appended as a separate block so a persona with only
    // name+description (the pre-Phase-2 shape) produces byte-identical
    // output to before this existed.
    if ($persona) {
        $personaExtra = [];
        foreach ([
            'Title'                => $persona['title'] ?? null,
            'Pain points'          => $persona['pain_points'] ?? null,
            'Objections'           => $persona['objections'] ?? null,
            'Cares about (KPIs)'   => $persona['kpis'] ?? null,
            'Communication style'  => $persona['communication_style'] ?? null,
            'Responds best to'     => $persona['preferred_content'] ?? null,
            'Good hook angle for this persona' => $persona['content_hook'] ?? null,
        ] as $key => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $personaExtra[] = "{$key}: {$value}";
            }
        }
        if ($personaExtra) {
            $parts[] = "More about the \"{$persona['name']}\" persona:\n" . implode("\n", $personaExtra);
        }
    }
    if ($pillar && !empty($pillar['description'])) {
        $parts[] = "Content pillar \"{$pillar['name']}\": {$pillar['description']}";
    }
    // KB expansion Phase 9 — a matching Proof Point (Block 8), auto-
    // attached rather than needing its own selector.
    if ($service && $workspace) {
        $proof = fetch_matching_proof_point((int) $workspace['id'], (int) $service['id'], $service['vertical_id'] ?? null);
        $proofText = proof_point_context_text($proof);
        if ($proofText !== '') {
            $parts[] = $proofText;
        }
    }
    if ($relatedMemory) {
        $lines = array_map(
            fn ($m) => '- ' . $m['summary'] . ' (' . date('j M Y', strtotime($m['created_at'])) . ')',
            $relatedMemory
        );
        $parts[] = "RECENT POSTS FROM THIS WORKSPACE (avoid repeating these — cover new ground; if one is clearly the start of a series this topic continues, build on it naturally instead of restating it):\n"
            . implode("\n", $lines);
    }
    return $parts ? implode("\n", $parts) . "\n\n" : '';
}

// Rules that apply to every generated field regardless of format — ported
// from the user's own working manual brief (previously pasted by hand
// into a Claude.ai chat before this app existed), which produced content
// that reliably fit the rendered image layout. The word limits below
// match includes/image_renderer.php's actual slide layout budget, so
// sticking to them is what keeps generated content from overflowing —
// see also the auto-shrink fallback in render_numbered_card()/
// render_cta_banner() for cases where a model overshoots anyway.
const AI_STYLE_RULES = <<<RULES
STYLE & QUALITY RULES (apply to everything you write):
- Tone: professional, direct, insight-driven — no fluff, no emojis
- Write in British/Indian English spelling (organisation, not organization; programme, not program)
- Do not mention competitor names
- Do not invent statistics — use phrases like "significantly" or "on average" instead of fabricated numbers
- Never use the characters | or ;; anywhere in any text field
- Keep everything specific to the given topic/context — no generic filler
RULES;

// Caption/blog length is a user choice (New Post's AI panel, Content
// Studio's "Content Length" CSV column, Content Calendar's generate
// form, Blog Studio, News Studio) rather than a fixed constant. Named
// tiers, not raw word-count inputs, so every caller gets one consistent
// picker regardless of which content type it's generating — same
// pattern as ALL_POST_FORMATS/AI_PROVIDER_LABELS above: a fixed list
// defined here, not a runtime-configurable admin setting.
const CAPTION_LENGTH_PRESETS = [
    'very_short'  => ['label' => 'Very Short (~40-60 words)',    'words' => '40 to 60 words'],
    'short'       => ['label' => 'Short (~80-120 words)',        'words' => '80 to 120 words'],
    'medium'      => ['label' => 'Medium (~150-250 words)',      'words' => '150 to 250 words'],
    'long'        => ['label' => 'Long (~300-400 words)',        'words' => '300 to 400 words'],
    'blog_length' => ['label' => 'Blog Length (~500-700 words)', 'words' => '500 to 700 words'],
];
// 'medium' matches what every caption used before this was
// configurable, so anything that doesn't pass a length (old code
// paths, automated cron drafts with no per-run UI) behaves unchanged.
const CAPTION_LENGTH_DEFAULT = 'medium';

const BLOG_LENGTH_PRESETS = [
    'w100'  => ['label' => '100 words (Quick Update)',   'words' => 'approximately 100 words'],
    'w200'  => ['label' => '200 words (Short)',          'words' => 'approximately 200 words'],
    'w500'  => ['label' => '500 words (Standard)',       'words' => 'approximately 500 words'],
    'w1000' => ['label' => '1000 words (In-Depth)',      'words' => 'approximately 1000 words'],
    'w2000' => ['label' => '2000 words (Comprehensive)', 'words' => 'approximately 2000 words'],
];
// 'w1000' is closest to the old fixed 700-1200 word blog default.
const BLOG_LENGTH_DEFAULT = 'w1000';

// Accepts a preset key ("short") or its spaced/hyphenated label form
// ("Very Short", "very-short") so free-text CSV input matches without
// requiring the exact internal key spelling.
function resolve_length_preset(?string $key, array $presets, string $defaultKey): string
{
    $key = strtolower(trim((string) $key));
    $key = preg_replace('/[\s-]+/', '_', $key);
    return $presets[$key]['words'] ?? $presets[$defaultKey]['words'];
}

function build_caption_rules(string $cta, string $length = CAPTION_LENGTH_DEFAULT): string
{
    $ctaLine = $cta !== ''
        ? "End with this exact line: \"{$cta}\""
        : 'End with a natural closing line inviting engagement (a question or a soft call to action)';
    $wordCount = resolve_length_preset($length, CAPTION_LENGTH_PRESETS, CAPTION_LENGTH_DEFAULT);

    return <<<RULES
CAPTION RULES:
- {$wordCount}
- Short paragraphs, 2 to 4 lines each
- Start directly with a hook line — no greeting or preamble
- {$ctaLine}
- Add 4 to 5 relevant hashtags on the final line, space-separated
RULES;
}

// Structural word/slide-count rules per format — split out of
// build_generation_prompt() so generate_creative_via_ai_custom_prompt()
// (the "Custom Prompt" AI mode, which skips all Knowledge Base/brand
// context) can reuse them verbatim: these are renderer-driven
// constraints (what keeps a slide from overflowing), not brand content,
// so they still apply even when nothing else about the prompt is
// KB-informed. Text Post has no slide content, so no rules to return.
function build_slide_rules_bullets(string $format, int $slideCount = 5): string
{
    if ($format === 'Single Image') {
        return <<<RULES
IMAGE TEXT RULES (strict — the renderer truncates anything over these limits with an ellipsis, so staying within them is what keeps the image looking clean):
- Headline: max 8 words, one line of thought, no trailing punctuation
- Body: exactly 1 sentence, max 25 words
- Points: EXACTLY 3, max 10 words each, never empty
- All 3 points must be the same kind of thing — three parallel facts, problems, or benefits at the same level. Never mix in a 4th idea, a solution/pivot, a brand or company name, or a CTA — that always belongs in the caption or a CTA slide, never inside the points list.
- Write like a specific, opinionated LinkedIn post, not a generic corporate summary — concrete nouns and numbers beat vague phrases like "leverage synergies" or "drive results"
- Optional: mark up to 1-2 key words or a number/percentage anywhere in the headline, subheading, body, or points using these markers: **word** for accent color, ++word++ for highlight color, *word* for italic, __word__ for bold (e.g. "60% faster **ESG reporting**") — wrap one marker inside another, e.g. **__word__**, to combine effects on the same word. Every template supports this now. Use sparingly — a whole sentence in markers looks worse than one sharp phrase.
- Optional: a short "subheading" line (max 8 words) directly under the headline, for extra context that doesn't fit the headline itself. Leave it as an empty string unless it genuinely adds something the headline can't.
RULES;
    }
    if ($format === 'Carousel') {
        // Slide count is caller-configurable (News Studio's "Create
        // Draft" slide-count picker; every other caller keeps the
        // original fixed-5 default) — position rules generate for
        // whatever count was asked for: slide 1 is always the hook,
        // the last slide is always the CTA, everything between is a
        // 3-point content slide.
        $last = max(2, $slideCount);
        $positions = ["- Slide 1 (Hook): Headline + Body only — NO points"];
        if ($last === 3) {
            $positions[] = "- Slide 2 (Content): Headline + Body + EXACTLY 3 points";
        } elseif ($last > 3) {
            $positions[] = "- Slides 2-" . ($last - 1) . " (Content): Headline + Body + EXACTLY 3 points";
        }
        $positions[] = "- Slide {$last} (CTA): Headline + Body + a separate \"cta\" field carrying the CTA line — leave \"points\" empty unless there's a genuine short supporting fact to add alongside it";
        $positionRules = implode("\n", $positions);
        return <<<RULES
SLIDE RULES (strict — the renderer truncates anything over these limits with an ellipsis, so staying within them is what keeps every slide looking clean):
{$positionRules}
- Headline: max 8 words, one line of thought, no trailing punctuation
- Body: exactly 1 sentence, max 25 words
- Points: max 10 words each
- Within one Content slide, all 3 points must be the same kind of thing — three parallel facts, problems, or benefits at the same level. Never mix in a 4th idea, a solution/pivot, a brand or company name, or a CTA — save that for the final slide.
- Optional: mark up to 1-2 key words or a number/percentage anywhere in a slide's headline, subheading, body, or points using these markers: **word** for accent color, ++word++ for highlight color, *word* for italic, __word__ for bold (e.g. "60% faster **ESG reporting**") — wrap one marker inside another, e.g. **__word__**, to combine effects on the same word. Every template supports this now. Use sparingly — a whole sentence in markers looks worse than one sharp phrase.
- Optional: any slide's "subheading" (max 8 words) can carry a short supporting line under its headline. Leave it as an empty string on slides where it doesn't add anything.
RULES;
    }
    return '';
}

// The exact "Return ONLY raw JSON..." tail per format — split out for
// the same reason as build_slide_rules_bullets() above (Custom Prompt
// mode needs the output contract even without any KB context).
function build_json_schema_block(string $format, int $slideCount = 5): string
{
    if ($format === 'Single Image') {
        return <<<SCHEMA
Return ONLY raw JSON — no markdown, no code fences, no explanation:
{
  "title": "image title",
  "caption": "full LinkedIn caption text including hashtags",
  "hashtags": ["#Tag1", "#Tag2", "#Tag3"],
  "slides": [
    {
      "slide_number": 1,
      "headline": "Headline here",
      "subheading": "",
      "body": "Body sentence.",
      "points": ["Point one", "Point two", "Point three"]
    }
  ]
}
SCHEMA;
    }
    if ($format === 'Carousel') {
        $last = max(2, $slideCount);
        $slideExamples = ['{"slide_number": 1, "headline": "Hook headline here", "subheading": "", "body": "Teaser sentence.", "points": []}'];
        for ($n = 2; $n < $last; $n++) {
            $slideExamples[] = '{"slide_number": ' . $n . ', "headline": "Slide ' . $n . ' headline", "subheading": "", "body": "Brief explanatory text.", "points": ["Point one", "Point two", "Point three"]}';
        }
        $slideExamples[] = '{"slide_number": ' . $last . ', "headline": "Closing headline", "subheading": "", "body": "One closing sentence.", "points": [], "cta": "Exact CTA line here"}';
        $slidesJson = "    " . implode(",\n    ", $slideExamples);
        return <<<SCHEMA
Return ONLY raw JSON — no markdown, no code fences, no explanation:
{
  "title": "carousel title",
  "caption": "full LinkedIn caption text including hashtags",
  "hashtags": ["#Tag1", "#Tag2", "#Tag3"],
  "slides": [
{$slidesJson}
  ]
}
SCHEMA;
    }
    // Text Post
    return <<<SCHEMA
Return ONLY raw JSON — no markdown, no code fences, no explanation:
{
  "title": "short internal title for this post",
  "caption": "full LinkedIn post text including hashtags",
  "hashtags": ["#Tag1", "#Tag2", "#Tag3"],
  "slides": []
}
SCHEMA;
}

function build_generation_prompt(array $row, string $format, ?string $brandBrief = null, ?array $persona = null, ?array $pillar = null, ?array $workspace = null, array $relatedMemory = [], ?array $service = null): string
{
    $context = build_context_block($brandBrief, $persona, $pillar, $workspace, $relatedMemory, $service);

    // News-reaction posts (includes/news_fetch.php news_generate_draft())
    // pass the headline/source/date in the row's "News" field. Only the
    // headline is ever shown to the model — the post must be the user's
    // own commentary, not a rewrite of an article nobody pasted in.
    $news = trim($row['News'] ?? '');
    if ($news !== '') {
        $context .= <<<NEWSBLOCK
THIS POST IS A REACTION TO A CURRENT NEWS STORY:
{$news}

NEWS REACTION RULES:
- Write the author's own first-person take: what this news means for their audience, a lesson from their experience it confirms or challenges, or a prediction — an opinion, not a news report
- Reference the story in one short phrase early on so readers have context; assume they haven't seen the article
- You only know the headline above. Do NOT invent details, quotes, or figures from the article — everything beyond the headline must come from the author's expertise
- Do not copy or paraphrase the headline as the post's hook; lead with the author's angle on it


NEWSBLOCK;
    }
    $styleRules = AI_STYLE_RULES;
    $topic    = trim($row['Topic / Title'] ?? $row['Topic/Title'] ?? '');
    $personaLabel = trim($row['Target Persona'] ?? '');
    $type     = trim($row['Type'] ?? '');
    $cta      = trim($row['CTA'] ?? '');
    $tagPage  = trim($row['Tag Page'] ?? '');
    $caption  = trim($row['Post Caption'] ?? '');
    $length   = trim($row['Content Length'] ?? 'medium');

    if ($format === 'Text Post') {
        $captionBlock = $caption !== ''
            ? "Use this exact caption (do not change it):\n\"\"\"\n{$caption}\n\"\"\""
            : build_caption_rules($cta, $length);

        $schema = build_json_schema_block('Text Post');
        return <<<PROMPT
{$context}You are a LinkedIn content specialist writing a text-only post for a B2B engineering/manufacturing audience.

POST DETAILS:
- Topic: {$topic}
- Target Audience: {$personaLabel}
- Content Style: {$type}

CAPTION:
{$captionBlock}

{$styleRules}

{$schema}
PROMPT;
    }

    $captionBlock = $caption !== ''
        ? "Use this exact caption (do not change it):\n\"\"\"\n{$caption}\n\"\"\""
        : build_caption_rules($cta, $length);

    if ($format === 'Single Image') {
        $bullets = build_slide_rules_bullets('Single Image');
        $schema = build_json_schema_block('Single Image');
        return <<<PROMPT
{$context}You are a LinkedIn content specialist creating a single-image post for a B2B engineering/manufacturing audience.

POST DETAILS:
- Topic: {$topic}
- Target Audience: {$personaLabel}
- Content Style: {$type}
- CTA: {$cta}
- Tag Page: {$tagPage}

CAPTION:
{$captionBlock}

{$bullets}

EXAMPLE of the right length and style (topic: quoting delays in manufacturing):
  Body: "Manual quoting creates delays, errors, and lost revenue."
  Points: "Quote cycle from days to minutes" / "Pricing consistent across every deal" / "Engineering no longer involved in every quote"

{$styleRules}

{$schema}
PROMPT;
    }

    // Caller-configurable (News Studio's "Create Draft" slide-count
    // picker sets $row['Slide Count']); every other caller leaves it
    // unset and gets the original fixed count of 5.
    $slideCount = max(2, min(10, (int) trim($row['Slide Count'] ?? '') ?: 5));
    $bullets = build_slide_rules_bullets('Carousel', $slideCount);
    $schema = build_json_schema_block('Carousel', $slideCount);
    return <<<PROMPT
{$context}You are a LinkedIn content specialist creating a carousel post for a B2B engineering/manufacturing audience.

POST DETAILS:
- Topic: {$topic}
- Target Audience: {$personaLabel}
- Content Style: {$type}
- Slide Count: {$slideCount}
- CTA: {$cta}
- Tag Page: {$tagPage}

CAPTION:
{$captionBlock}

{$bullets}

EXAMPLE of the right length and style (topic: quoting delays in manufacturing — illustrative only, follow the SLIDE RULES above for the actual slide count and positions):
  Slide 1 (Hook): "Your Quote Cycle Is Leaking Revenue" / "When quoting takes too long, deals fall through."
  Slide 2: "The Hidden Cost of Manual Quoting" / "Most manufacturers never measure the revenue impact." / "Win rate drops when response exceeds 48 hours" / "Pricing errors create discount conversations that shouldn't happen" / "Sales teams avoid complex configs to reduce rework"
  Slide 3: "What a Fixed Quote Cycle Looks Like" / "CPQ implemented correctly changes the entire sales dynamic." / "Configuration logic in the system not in individuals" / "Pricing rules automated and consistently applied" / "Quotes generated in minutes not days"
  Slide 4 (CTA): "A Faster Quote Cycle Starts With an Assessment" / "The CPQ Readiness Checklist gives you a clear starting point." / CTA: "Comment CPQ and I will send you the checklist free"

{$styleRules}

{$schema}
PROMPT;
}

// ── HTTP mechanics, one function per provider ───────────────────────
// Each returns the raw text the model produced (expected to be a JSON
// string); generate_creative_via_ai() does the shared decode/validation.

function ai_http_post_json(string $url, array $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    return [$status, $response, $curlErr];
}

function ai_call_gemini(string $prompt, string $apiKey, string $model): string
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($apiKey);
    $body = [
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['responseMimeType' => 'application/json'],
    ];
    [$status, $response, $curlErr] = ai_http_post_json($url, $body, ['Content-Type: application/json']);

    if ($response === false) {
        throw new RuntimeException("Gemini request failed: {$curlErr}");
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Gemini request failed ({$status}): " . substr($response, 0, 300));
    }

    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text === null) {
        $blockReason = $data['promptFeedback']['blockReason'] ?? null;
        throw new RuntimeException($blockReason
            ? "Gemini declined to generate this row: {$blockReason}"
            : 'Gemini returned an unexpected response shape.');
    }
    return $text;
}

function ai_call_claude(string $prompt, string $apiKey, string $model): string
{
    $body = [
        'model'      => $model,
        'max_tokens' => 2000,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ];
    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ];
    [$status, $response, $curlErr] = ai_http_post_json('https://api.anthropic.com/v1/messages', $body, $headers);

    if ($response === false) {
        throw new RuntimeException("Claude request failed: {$curlErr}");
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Claude request failed ({$status}): " . substr($response, 0, 300));
    }

    $data = json_decode($response, true);
    $text = $data['content'][0]['text'] ?? null;
    if ($text === null) {
        throw new RuntimeException('Claude returned an unexpected response shape.');
    }

    // Claude has no forced-JSON response mode like Gemini/OpenAI — strip
    // markdown code fences in case it wrapped the JSON anyway (same
    // safety net the original generate.py's Claude integration used).
    $text = trim($text);
    if (str_starts_with($text, '```')) {
        $text = preg_replace('/^```(?:json)?\s*/', '', $text);
        $text = preg_replace('/```\s*$/', '', $text);
    }
    return trim($text);
}

function ai_call_openai(string $prompt, string $apiKey, string $model): string
{
    $body = [
        'model'           => $model,
        'messages'        => [['role' => 'user', 'content' => $prompt]],
        'response_format' => ['type' => 'json_object'],
    ];
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];
    [$status, $response, $curlErr] = ai_http_post_json('https://api.openai.com/v1/chat/completions', $body, $headers);

    if ($response === false) {
        throw new RuntimeException("OpenAI request failed: {$curlErr}");
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("OpenAI request failed ({$status}): " . substr($response, 0, 300));
    }

    $data = json_decode($response, true);
    $text = $data['choices'][0]['message']['content'] ?? null;
    if ($text === null) {
        throw new RuntimeException('OpenAI returned an unexpected response shape.');
    }
    return trim($text);
}

// ── Raw (non-branded) image generation ──────────────────────────────
// New Post's "Stock/AI Photo" panel — a plain generated photo/graphic
// used directly as a Single Image post's image, distinct from
// generate_creative_via_ai() above (which produces branded slide JSON
// rendered by includes/image_renderer.php). Reuses whichever
// Gemini/OpenAI key the user already has configured for text
// generation — no separate credential needed. Claude has no image
// generation API, so it isn't offered here.
//
// Defaulted here (not just in config.sample.php) the same way
// NEWS_FEED_LANG/NEWS_FEED_COUNTRY are in includes/news_fetch.php — a
// live config.php doesn't get new constants automatically on deploy
// (it's gitignored, holds secrets), so this keeps the feature working
// out of the box; override in config.php if you want a different model.
if (!defined('GEMINI_IMAGE_MODEL')) {
    define('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image');
}
if (!defined('OPENAI_IMAGE_MODEL')) {
    define('OPENAI_IMAGE_MODEL', 'dall-e-3');
}

function ai_call_gemini_image(string $prompt, string $apiKey): array
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_IMAGE_MODEL . ':generateContent?key=' . urlencode($apiKey);
    $body = ['contents' => [['parts' => [['text' => $prompt]]]]];
    [$status, $response, $curlErr] = ai_http_post_json($url, $body, ['Content-Type: application/json']);

    if ($response === false) {
        throw new RuntimeException("Gemini image request failed: {$curlErr}");
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Gemini image request failed ({$status}): " . substr($response, 0, 300));
    }

    $data = json_decode($response, true);
    foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
        if (!empty($part['inlineData']['data'])) {
            return [
                'bytes' => base64_decode($part['inlineData']['data']),
                'mime'  => $part['inlineData']['mimeType'] ?? 'image/png',
            ];
        }
    }
    $blockReason = $data['promptFeedback']['blockReason'] ?? null;
    throw new RuntimeException($blockReason
        ? "Gemini declined to generate this image: {$blockReason}"
        : 'Gemini did not return an image for this prompt.');
}

function ai_call_openai_image(string $prompt, string $apiKey): array
{
    $body = [
        'model'           => OPENAI_IMAGE_MODEL,
        'prompt'          => $prompt,
        'size'            => '1024x1024',
        'response_format' => 'b64_json',
    ];
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
    [$status, $response, $curlErr] = ai_http_post_json('https://api.openai.com/v1/images/generations', $body, $headers);

    if ($response === false) {
        throw new RuntimeException("OpenAI image request failed: {$curlErr}");
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("OpenAI image request failed ({$status}): " . substr($response, 0, 300));
    }

    $data = json_decode($response, true);
    $b64 = $data['data'][0]['b64_json'] ?? null;
    if ($b64 === null) {
        throw new RuntimeException('OpenAI did not return image data for this prompt.');
    }
    return ['bytes' => base64_decode($b64), 'mime' => 'image/png'];
}

// $aiConfig is resolve_ai_config()'s shape. Returns ['bytes' => string, 'mime' => string].
function ai_generate_image(string $prompt, array $aiConfig): array
{
    if (!ai_configured($aiConfig)) {
        throw new RuntimeException('Add an AI provider key in Settings first.');
    }
    return match ($aiConfig['provider']) {
        'openai' => ai_call_openai_image($prompt, $aiConfig['api_key']),
        'claude' => throw new RuntimeException('Claude has no image generation — switch to Gemini or OpenAI in Settings, or use Stock Photo search instead.'),
        default  => ai_call_gemini_image($prompt, $aiConfig['api_key']),
    };
}

// ── Shared entry point ───────────────────────────────────────────────

// $aiConfig is resolve_ai_config()'s shape: ['provider','api_key','model'].
// $persona/$pillar are full records (['name','description']) from
// includes/post_helpers.php fetch_persona()/fetch_content_pillar(), not
// just IDs — pass null for either when the caller has nothing selected.
function generate_creative_via_ai(array $row, array $aiConfig, ?string $brandBrief = null, ?array $persona = null, ?array $pillar = null, ?array $workspace = null, array $relatedMemory = [], ?array $service = null): array
{
    if (!ai_configured($aiConfig)) {
        $label = AI_PROVIDER_LABELS[$aiConfig['provider'] ?? 'gemini'] ?? ucfirst($aiConfig['provider'] ?? 'gemini');
        throw new RuntimeException("Add a {$label} API key in Settings to use AI generation, or fill in the Creative Content column for this row instead.");
    }

    $rawFormat = trim($row['Final_Format'] ?? '');
    $format = in_array($rawFormat, ['Single Image', 'Text Post'], true) ? $rawFormat : 'Carousel';
    $prompt = build_generation_prompt($row, $format, $brandBrief, $persona, $pillar, $workspace, $relatedMemory, $service);

    return ai_generate_dispatch($prompt, $format, $aiConfig, creative_series_label($row));
}

// "Custom Prompt" AI mode — the user supplies the entire prompt
// themselves; no Knowledge Base/brand context, no persona/pillar
// targeting, no per-row POST DETAILS/CAPTION section, no domain-
// flavored EXAMPLE block. Still keeps the structural word/slide-count
// rules (build_slide_rules_bullets()) and the generic writing-quality
// guidance (AI_STYLE_RULES) — both are renderer/quality constraints,
// not brand content, so they still apply even with zero KB reference.
// series_label is always '' here (no Pillar/Type row exists to derive
// one from) — the user can still set the Eyebrow field manually.
function generate_creative_via_ai_custom_prompt(string $format, string $userPrompt, array $aiConfig): array
{
    if (!ai_configured($aiConfig)) {
        $label = AI_PROVIDER_LABELS[$aiConfig['provider'] ?? 'gemini'] ?? ucfirst($aiConfig['provider'] ?? 'gemini');
        throw new RuntimeException("Add a {$label} API key in Settings to use AI generation.");
    }

    $bullets = build_slide_rules_bullets($format);
    $schema = build_json_schema_block($format);
    $parts = array_filter([$userPrompt, $bullets, AI_STYLE_RULES, $schema], fn ($p) => $p !== '');
    $prompt = implode("\n\n", $parts);

    return ai_generate_dispatch($prompt, $format, $aiConfig, '');
}

// Shared by generate_creative_via_ai() and
// generate_creative_via_ai_custom_prompt() — provider dispatch, JSON
// validation, and $creative['format']/'series_label'/'hashtags']
// finalization. $seriesLabel is passed in rather than derived here
// since the two callers compute it differently (from a CSV/KB $row, or
// not at all in custom-prompt mode).
function ai_generate_dispatch(string $prompt, string $format, array $aiConfig, string $seriesLabel): array
{
    $provider = $aiConfig['provider'] ?? 'gemini';
    $label = AI_PROVIDER_LABELS[$provider] ?? ucfirst($provider);

    $text = match ($provider) {
        'claude' => ai_call_claude($prompt, $aiConfig['api_key'], $aiConfig['model']),
        'openai' => ai_call_openai($prompt, $aiConfig['api_key'], $aiConfig['model']),
        default  => ai_call_gemini($prompt, $aiConfig['api_key'], $aiConfig['model']),
    };

    $creative = json_decode(trim($text), true);
    if (!is_array($creative) || !isset($creative['slides']) || !is_array($creative['slides'])) {
        throw new RuntimeException("{$label} did not return valid JSON for this row.");
    }
    if ($format !== 'Text Post' && empty($creative['slides'])) {
        throw new RuntimeException("{$label} did not return valid JSON for this row.");
    }

    $creative['format']       = $format === 'Single Image' ? 'single' : ($format === 'Text Post' ? 'text' : 'carousel');
    $creative['series_label'] = $seriesLabel;
    if (empty($creative['hashtags'])) {
        $creative['hashtags'] = creative_extract_hashtags($creative['caption'] ?? '');
    }

    return $creative;
}

// Kept for any direct callers/tests that still want Gemini specifically
// without going through the provider dispatch.
function generate_creative_via_gemini(array $row, ?string $apiKey): array
{
    return generate_creative_via_ai($row, ['provider' => 'gemini', 'api_key' => $apiKey, 'model' => GEMINI_MODEL]);
}
