<?php
// Blog post generation — deliberately separate from includes/ai_generate.php
// (the LinkedIn creative-JSON schema doesn't fit long-form HTML). Reuses
// that file's provider dispatch (ai_call_gemini/ai_call_claude/ai_call_openai)
// and includes/workspace.php's context builders, plus Memory & Context
// (includes/content_memory.php) so a workspace's blog avoids repeating
// its own past posts. Requires ai_generate.php, workspace.php to already
// be loaded.
//
// "Research" is scoped honestly — no live web crawling. The prompt is
// grounded in: (a) sibling headlines from the same News Studio fetch
// that produced this topic, when generating from a headline; (b) the
// workspace's Knowledge Hub documents; (c) related past blog posts via
// Memory & Context. Internal links are woven into the same generation
// call by passing existing published posts' title/slug pairs.

// $topic: ['title' => string, 'news_line' => ?string, 'length' => ?string].
// $researchContext: sibling headlines text, or null.
// $existingPosts: [['title'=>...,'slug'=>...], ...] for internal linking.
// 'length' is one of BLOG_LENGTH_PRESETS' keys (includes/ai_generate.php)
// — defaults to BLOG_LENGTH_DEFAULT ('w1000', closest to the fixed
// 700-1200 words every blog post used before this was configurable)
// when absent/invalid.
function build_blog_prompt(array $topic, ?array $workspace, array $relatedMemory, ?string $researchContext, array $existingPosts): string
{
    $context = build_context_block(null, null, null, $workspace, $relatedMemory);

    $parts = [$context];

    if ($researchContext) {
        $parts[] = "RELATED HEADLINES FROM THE SAME TREND (for grounding — don't just summarize these, write original commentary/analysis):\n{$researchContext}";
    }

    if ($existingPosts) {
        $links = implode("\n", array_map(
            fn ($p) => "- \"{$p['title']}\" -> {$p['slug']}",
            array_slice($existingPosts, 0, 15)
        ));
        $website = trim((string) ($workspace['website'] ?? ''));
        $base = $website !== '' ? rtrim($website, '/') : '';
        $parts[] = "EXISTING BLOG POSTS ON THIS SITE (weave in 2-4 contextual links where genuinely relevant, as <a href=\"{$base}/{slug}\">anchor text</a> — never force a link that doesn't fit):\n{$links}";
    }

    $newsLine = trim((string) ($topic['news_line'] ?? ''));
    $wordCount = resolve_length_preset($topic['length'] ?? BLOG_LENGTH_DEFAULT, BLOG_LENGTH_PRESETS, BLOG_LENGTH_DEFAULT);
    $parts[] = <<<PROMPT
TASK: Write an original, SEO-friendly blog post on this topic: "{$topic['title']}"
{$newsLine}

Requirements:
- {$wordCount}, written in the voice/tone described above.
- Structure with an engaging intro, 3-6 <h2> subheadings, and a short conclusion with a natural call to action.
- Original analysis and perspective, not a rehash of any single source.
- Naturally incorporate the target keywords if any are implied by the topic.
- HTML body only (content_html) — use <h2>, <p>, <ul>/<li>, <strong>, <a> as appropriate. No <html>/<body>/<script> tags, no inline styles.

Return ONLY valid JSON with this exact shape, no markdown fences, no commentary:
{
  "title": "string, the blog post's actual headline (can differ from the topic phrasing above)",
  "slug": "string, lowercase-hyphenated, derived from the title, no special characters",
  "meta_description": "string, 120-155 characters, SEO meta description",
  "keywords": "string, 3-6 comma-separated target keywords/phrases",
  "content_html": "string, the full HTML body as described above"
}
PROMPT;

    return implode("\n\n", array_filter($parts));
}

function generate_blog_post_via_ai(array $topic, array $aiConfig, ?array $workspace, array $relatedMemory = [], ?string $researchContext = null, array $existingPosts = []): array
{
    $provider = $aiConfig['provider'] ?? 'gemini';
    $label = AI_PROVIDER_LABELS[$provider] ?? ucfirst($provider);

    if (!ai_configured($aiConfig)) {
        throw new RuntimeException("Add a {$label} API key in Settings to generate a blog post.");
    }

    $prompt = build_blog_prompt($topic, $workspace, $relatedMemory, $researchContext, $existingPosts);

    $text = match ($provider) {
        'claude' => ai_call_claude($prompt, $aiConfig['api_key'], $aiConfig['model']),
        'openai' => ai_call_openai($prompt, $aiConfig['api_key'], $aiConfig['model']),
        default  => ai_call_gemini($prompt, $aiConfig['api_key'], $aiConfig['model']),
    };

    $creative = json_decode(trim($text), true);
    if (!is_array($creative) || empty($creative['title']) || empty($creative['content_html'])) {
        throw new RuntimeException("{$label} did not return valid JSON for this blog post.");
    }

    $creative['slug'] = !empty($creative['slug']) ? blog_slugify($creative['slug']) : blog_slugify($creative['title']);
    $creative['meta_description'] = trim((string) ($creative['meta_description'] ?? ''));
    $creative['keywords'] = trim((string) ($creative['keywords'] ?? ''));

    return $creative;
}
