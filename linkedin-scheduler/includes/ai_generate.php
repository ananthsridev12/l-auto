<?php
// Ported from the local Python prototype's generate.py — used by Content
// Studio only when a CSV row's Creative Content column is blank but
// enough context (Topic / Title, Target Persona, Type, CTA) is present to
// ask an AI to write the copy. Calls Gemini instead of the Claude API
// generate.py used, since Gemini's free tier covers this app's volume
// indefinitely (see config.sample.php GEMINI_API_KEY / GEMINI_MODEL).
//
// Returns the same JSON shape as includes/creative_builder.php so both
// paths feed includes/image_renderer.php identically.

function gemini_configured(): bool
{
    return defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '';
}

function gemini_build_prompt(array $row, string $format): string
{
    $topic    = trim($row['Topic / Title'] ?? $row['Topic/Title'] ?? '');
    $persona  = trim($row['Target Persona'] ?? '');
    $type     = trim($row['Type'] ?? '');
    $cta      = trim($row['CTA'] ?? '');
    $tagPage  = trim($row['Tag Page'] ?? '');
    $caption  = trim($row['Post Caption'] ?? '');

    $captionBlock = $caption !== ''
        ? "Use this exact caption (do not change it):\n\"\"\"\n{$caption}\n\"\"\""
        : 'Write a professional LinkedIn caption matching the topic and tone, including 3-5 relevant hashtags at the end.';

    if ($format === 'Single Image') {
        return <<<PROMPT
You are a LinkedIn content specialist creating a single-image post for a B2B engineering/manufacturing audience.

POST DETAILS:
- Topic: {$topic}
- Target Audience: {$persona}
- Content Style: {$type}
- CTA Question: {$cta}
- Tag Page: {$tagPage}

CAPTION:
{$captionBlock}

IMAGE TEXT GUIDELINES:
- Headline: bold, max 8 words, states the core idea
- Body: 1-2 sentences, max 25 words
- Points: up to 4 short supporting points, max 10 words each (can be empty)
- Tone: professional, direct, insight-driven (not salesy)

Return ONLY raw JSON — no markdown, no code fences, no explanation:
{
  "title": "image title",
  "caption": "full LinkedIn caption text including hashtags",
  "hashtags": ["#Tag1", "#Tag2", "#Tag3"],
  "slides": [
    {
      "slide_number": 1,
      "headline": "Headline here",
      "body": "Body sentence or two.",
      "points": ["Point one", "Point two"]
    }
  ]
}
PROMPT;
    }

    return <<<PROMPT
You are a LinkedIn content specialist creating a carousel post for a B2B engineering/manufacturing audience.

POST DETAILS:
- Topic: {$topic}
- Target Audience: {$persona}
- Content Style: {$type}
- Slide Count: 5
- CTA Question: {$cta}
- Tag Page: {$tagPage}

CAPTION:
{$captionBlock}

SLIDE GUIDELINES:
- Slide 1 (Hook): Bold attention-grabbing headline (max 8 words). Short teaser body (1-2 sentences). No bullet points.
- Slides 2-4 (Content): Clear headline, brief body (1-2 sentences), exactly 3 concise bullet points each.
- Slide 5 (CTA): Summary headline, 1-sentence closing body, CTA question as the single bullet point.

CONSTRAINTS:
- Headlines: max 8 words
- Body text: max 25 words
- Bullet points: max 10 words each
- Tone: professional, direct, insight-driven (not salesy)

Return ONLY raw JSON — no markdown, no code fences, no explanation:
{
  "title": "carousel title",
  "caption": "full LinkedIn caption text including hashtags",
  "hashtags": ["#Tag1", "#Tag2", "#Tag3"],
  "slides": [
    {"slide_number": 1, "headline": "Hook headline here", "body": "Teaser sentence or two.", "points": []},
    {"slide_number": 2, "headline": "Slide 2 headline", "body": "Brief explanatory text.", "points": ["Point one", "Point two", "Point three"]},
    {"slide_number": 3, "headline": "Slide 3 headline", "body": "Brief explanatory text.", "points": ["Point one", "Point two", "Point three"]},
    {"slide_number": 4, "headline": "Slide 4 headline", "body": "Brief explanatory text.", "points": ["Point one", "Point two", "Point three"]},
    {"slide_number": 5, "headline": "Closing headline", "body": "One closing sentence.", "points": ["CTA question here?"]}
  ]
}
PROMPT;
}

function generate_creative_via_gemini(array $row): array
{
    if (!gemini_configured()) {
        throw new RuntimeException('Gemini API key is not configured — add GEMINI_API_KEY in config.php, or fill in the Creative Content column for this row instead.');
    }

    $format = trim($row['Final_Format'] ?? '') === 'Single Image' ? 'Single Image' : 'Carousel';
    $prompt = gemini_build_prompt($row, $format);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . urlencode(GEMINI_API_KEY);
    $body = [
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['responseMimeType' => 'application/json'],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

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

    $creative = json_decode(trim($text), true);
    if (!is_array($creative) || empty($creative['slides'])) {
        throw new RuntimeException('Gemini did not return valid JSON for this row.');
    }

    $creative['format']       = $format === 'Single Image' ? 'single' : 'carousel';
    $creative['series_label'] = creative_series_label($row);
    if (empty($creative['hashtags'])) {
        $creative['hashtags'] = creative_extract_hashtags($creative['caption'] ?? '');
    }

    return $creative;
}
