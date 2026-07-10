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
function build_context_block(?string $brandBrief, ?array $persona, ?array $pillar): string
{
    $parts = [];
    if ($brandBrief) {
        $parts[] = "Brand context: {$brandBrief}";
    }
    if ($persona && !empty($persona['description'])) {
        $parts[] = "Target persona \"{$persona['name']}\": {$persona['description']}";
    }
    if ($pillar && !empty($pillar['description'])) {
        $parts[] = "Content pillar \"{$pillar['name']}\": {$pillar['description']}";
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

function build_caption_rules(string $cta): string
{
    $ctaLine = $cta !== ''
        ? "End with this exact line: \"{$cta}\""
        : 'End with a natural closing line inviting engagement (a question or a soft call to action)';

    return <<<RULES
CAPTION RULES:
- 100 to 250 words
- Short paragraphs, 2 to 4 lines each
- Start directly with a hook line — no greeting or preamble
- {$ctaLine}
- Add 4 to 5 relevant hashtags on the final line, space-separated
RULES;
}

function build_generation_prompt(array $row, string $format, ?string $brandBrief = null, ?array $persona = null, ?array $pillar = null): string
{
    $context = build_context_block($brandBrief, $persona, $pillar);
    $styleRules = AI_STYLE_RULES;
    $topic    = trim($row['Topic / Title'] ?? $row['Topic/Title'] ?? '');
    $personaLabel = trim($row['Target Persona'] ?? '');
    $type     = trim($row['Type'] ?? '');
    $cta      = trim($row['CTA'] ?? '');
    $tagPage  = trim($row['Tag Page'] ?? '');
    $caption  = trim($row['Post Caption'] ?? '');

    if ($format === 'Text Post') {
        $captionBlock = $caption !== ''
            ? "Use this exact caption (do not change it):\n\"\"\"\n{$caption}\n\"\"\""
            : build_caption_rules($cta);

        return <<<PROMPT
{$context}You are a LinkedIn content specialist writing a text-only post for a B2B engineering/manufacturing audience.

POST DETAILS:
- Topic: {$topic}
- Target Audience: {$personaLabel}
- Content Style: {$type}

CAPTION:
{$captionBlock}

{$styleRules}

Return ONLY raw JSON — no markdown, no code fences, no explanation:
{
  "title": "short internal title for this post",
  "caption": "full LinkedIn post text including hashtags",
  "hashtags": ["#Tag1", "#Tag2", "#Tag3"],
  "slides": []
}
PROMPT;
    }

    $captionBlock = $caption !== ''
        ? "Use this exact caption (do not change it):\n\"\"\"\n{$caption}\n\"\"\""
        : build_caption_rules($cta);

    if ($format === 'Single Image') {
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

IMAGE TEXT RULES:
- Headline: max 8 words, states the core idea
- Body: exactly 1 sentence, max 25 words
- Points: exactly 3 to 4 short supporting points, max 10 words each — never leave points empty

EXAMPLE of the right length and style (topic: quoting delays in manufacturing):
  Body: "Manual quoting creates delays, errors, and lost revenue."
  Points: "Quote cycle from days to minutes" / "Pricing consistent across every deal" / "Engineering no longer involved in every quote" / "94% sales team adoption post-implementation"

{$styleRules}

Return ONLY raw JSON — no markdown, no code fences, no explanation:
{
  "title": "image title",
  "caption": "full LinkedIn caption text including hashtags",
  "hashtags": ["#Tag1", "#Tag2", "#Tag3"],
  "slides": [
    {
      "slide_number": 1,
      "headline": "Headline here",
      "body": "Body sentence.",
      "points": ["Point one", "Point two", "Point three"]
    }
  ]
}
PROMPT;
    }

    return <<<PROMPT
{$context}You are a LinkedIn content specialist creating a carousel post for a B2B engineering/manufacturing audience.

POST DETAILS:
- Topic: {$topic}
- Target Audience: {$personaLabel}
- Content Style: {$type}
- Slide Count: 5
- CTA: {$cta}
- Tag Page: {$tagPage}

CAPTION:
{$captionBlock}

SLIDE RULES:
- Slide 1 (Hook): Headline + Body only — NO points
- Slides 2-4 (Content): Headline + Body + exactly 3 points
- Slide 5 (CTA): Headline + Body + exactly 1 point, which is the CTA line
- Headline: max 8 words
- Body: max 25 words
- Points: max 10 words each

EXAMPLE of the right length and style (topic: quoting delays in manufacturing, 4 slides):
  Slide 1 (Hook): "Your Quote Cycle Is Leaking Revenue" / "When quoting takes too long, deals fall through."
  Slide 2: "The Hidden Cost of Manual Quoting" / "Most manufacturers never measure the revenue impact." / "Win rate drops when response exceeds 48 hours" / "Pricing errors create discount conversations that shouldn't happen" / "Sales teams avoid complex configs to reduce rework"
  Slide 3: "What a Fixed Quote Cycle Looks Like" / "CPQ implemented correctly changes the entire sales dynamic." / "Configuration logic in the system not in individuals" / "Pricing rules automated and consistently applied" / "Quotes generated in minutes not days"
  Slide 4 (CTA): "A Faster Quote Cycle Starts With an Assessment" / "The CPQ Readiness Checklist gives you a clear starting point." / "Comment CPQ and I will send you the checklist free"

{$styleRules}

Return ONLY raw JSON — no markdown, no code fences, no explanation:
{
  "title": "carousel title",
  "caption": "full LinkedIn caption text including hashtags",
  "hashtags": ["#Tag1", "#Tag2", "#Tag3"],
  "slides": [
    {"slide_number": 1, "headline": "Hook headline here", "body": "Teaser sentence.", "points": []},
    {"slide_number": 2, "headline": "Slide 2 headline", "body": "Brief explanatory text.", "points": ["Point one", "Point two", "Point three"]},
    {"slide_number": 3, "headline": "Slide 3 headline", "body": "Brief explanatory text.", "points": ["Point one", "Point two", "Point three"]},
    {"slide_number": 4, "headline": "Slide 4 headline", "body": "Brief explanatory text.", "points": ["Point one", "Point two", "Point three"]},
    {"slide_number": 5, "headline": "Closing headline", "body": "One closing sentence.", "points": ["Exact CTA line here"]}
  ]
}
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

// ── Shared entry point ───────────────────────────────────────────────

// $aiConfig is resolve_ai_config()'s shape: ['provider','api_key','model'].
// $persona/$pillar are full records (['name','description']) from
// includes/post_helpers.php fetch_persona()/fetch_content_pillar(), not
// just IDs — pass null for either when the caller has nothing selected.
function generate_creative_via_ai(array $row, array $aiConfig, ?string $brandBrief = null, ?array $persona = null, ?array $pillar = null): array
{
    $provider = $aiConfig['provider'] ?? 'gemini';
    $label = AI_PROVIDER_LABELS[$provider] ?? ucfirst($provider);

    if (!ai_configured($aiConfig)) {
        throw new RuntimeException("Add a {$label} API key in Settings to use AI generation, or fill in the Creative Content column for this row instead.");
    }

    $rawFormat = trim($row['Final_Format'] ?? '');
    $format = in_array($rawFormat, ['Single Image', 'Text Post'], true) ? $rawFormat : 'Carousel';
    $prompt = build_generation_prompt($row, $format, $brandBrief, $persona, $pillar);

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
    $creative['series_label'] = creative_series_label($row);
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
