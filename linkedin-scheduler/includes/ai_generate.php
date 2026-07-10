<?php
// Ported from the local Python prototype's generate.py — used by Content
// Studio and New Post's "Generate with AI" whenever there's no
// pre-written Creative Content but enough context (Topic / Title,
// Target Persona, Type, CTA) to ask an AI to write the copy. Calls
// Gemini instead of the Claude API generate.py used, since Gemini's
// free tier covers this app's volume indefinitely. Each user supplies
// their own key in Settings (includes/helpers.php get_gemini_api_key())
// — there is no app-wide key, so every call here takes $apiKey explicitly
// rather than reading a shared config constant.
//
// Returns the same JSON shape as includes/creative_builder.php so both
// paths feed includes/image_renderer.php identically.

function gemini_configured(?string $apiKey): bool
{
    return $apiKey !== null && trim($apiKey) !== '';
}

function gemini_build_prompt(array $row, string $format): string
{
    if ($format === 'Text Post') {
        $topic   = trim($row['Topic / Title'] ?? $row['Topic/Title'] ?? '');
        $persona = trim($row['Target Persona'] ?? '');
        $type    = trim($row['Type'] ?? '');
        $caption = trim($row['Post Caption'] ?? '');
        $captionBlock = $caption !== ''
            ? "Use this exact caption (do not change it):\n\"\"\"\n{$caption}\n\"\"\""
            : 'Write a professional LinkedIn text post matching the topic and tone, including 3-5 relevant hashtags at the end.';

        return <<<PROMPT
You are a LinkedIn content specialist writing a text-only post for a B2B engineering/manufacturing audience.

POST DETAILS:
- Topic: {$topic}
- Target Audience: {$persona}
- Content Style: {$type}

CAPTION:
{$captionBlock}

CONSTRAINTS:
- Tone: professional, direct, insight-driven (not salesy)
- Length: 3-6 short paragraphs, LinkedIn-native formatting (short lines, no walls of text)

Return ONLY raw JSON — no markdown, no code fences, no explanation:
{
  "title": "short internal title for this post",
  "caption": "full LinkedIn post text including hashtags",
  "hashtags": ["#Tag1", "#Tag2", "#Tag3"],
  "slides": []
}
PROMPT;
    }

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

function generate_creative_via_gemini(array $row, ?string $apiKey): array
{
    if (!gemini_configured($apiKey)) {
        throw new RuntimeException('Add a Gemini API key in Settings to use AI generation, or fill in the Creative Content column for this row instead.');
    }

    $rawFormat = trim($row['Final_Format'] ?? '');
    $format = in_array($rawFormat, ['Single Image', 'Text Post'], true) ? $rawFormat : 'Carousel';
    $prompt = gemini_build_prompt($row, $format);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . urlencode($apiKey);
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
    if (!is_array($creative) || !isset($creative['slides']) || !is_array($creative['slides'])) {
        throw new RuntimeException('Gemini did not return valid JSON for this row.');
    }
    if ($format !== 'Text Post' && empty($creative['slides'])) {
        throw new RuntimeException('Gemini did not return valid JSON for this row.');
    }

    $creative['format']       = $format === 'Single Image' ? 'single' : ($format === 'Text Post' ? 'text' : 'carousel');
    $creative['series_label'] = creative_series_label($row);
    if (empty($creative['hashtags'])) {
        $creative['hashtags'] = creative_extract_hashtags($creative['caption'] ?? '');
    }

    return $creative;
}
