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
// grounded in: (a) sibling headlines (Original Take mode) or short RSS
// snippets (Grounded Rewrite mode, see BLOG_MODE_GROUNDED below) from
// the same News Studio fetch that produced this topic; (b) the
// workspace's Knowledge Hub documents; (c) related past blog posts via
// Memory & Context. Internal links are woven into the same generation
// call by passing existing published posts' title/slug pairs.

// Named content skeleton every generated post follows, replacing what
// used to be a single loose "3-6 H2 subheadings" line. TOC_MIN_WORDS
// gates the two long-form-only additions (Table of Contents, FAQ) —
// forcing them onto a ~100-200 word post would overwhelm it.
const BLOG_TOC_MIN_WORDS = 1000; // BLOG_LENGTH_PRESETS keys w1000/w2000
const BLOG_STRUCTURE = <<<STRUCT
STRUCTURE (follow this shape; adapt section count to the target word count — a short post may compress steps into fewer, shorter sections rather than dropping the shape entirely):
1. Hook — 1-2 sentences, no heading. Open with a stat, a question, or a contrarian line — not a throat-clearing intro.
2. Context (<h2>) — why this matters right now.
3. 2-4 Analysis sections (<h2> each) — the actual substance, one clear idea per section.
4. Takeaways (<h2>) — 3-5 bullet points: practical "what to do with this."
5. Conclusion (<h2>, can be titled naturally) — short wrap-up + a natural call to action.
STRUCT;
const BLOG_STRUCTURE_LONG_FORM_ADDITIONS = <<<ADD

Since this is a long-form post, also include:
- A short Table of Contents right after the hook — a <ul> of <a href="#section-id"> links, one per <h2>, each <h2> given a matching id="section-id" attribute.
- A closing FAQ (<h2>FAQ</h2>) with 2-4 genuinely relevant questions and answers.
ADD;

// Generation mode for "Write Blog Post" (News Studio) — see
// pages/news_studio.php. Content Studio/manual "New Blog Post" always
// uses BLOG_MODE_ORIGINAL (no news item to ground against).
const BLOG_MODE_ORIGINAL = 'original';  // the author's own opinion/analysis — no source facts used at all
const BLOG_MODE_GROUNDED = 'grounded';  // extracts factual values from the source snippet(s) and re-expresses them originally

// $topic: ['title' => string, 'news_line' => ?string, 'length' => ?string].
// $researchContext: sibling headlines text (Original mode only), or null.
// $existingPosts: [['title'=>...,'slug'=>...], ...] for internal linking.
// $sourceSnippets (Grounded mode only): [['text'=>string,'source'=>?string,'url'=>string], ...] —
// the primary item plus any siblings that had a usable RSS <description>
// (see news_clean_description() in includes/news_fetch.php). Empty when
// no snippet was available, in which case grounded mode silently has no
// facts to work from beyond the headline — same as Original mode.
// 'length' is one of BLOG_LENGTH_PRESETS' keys (includes/ai_generate.php)
// — defaults to BLOG_LENGTH_DEFAULT ('w1000', closest to the fixed
// 700-1200 words every blog post used before this was configurable)
// when absent/invalid.
function build_blog_prompt(
    array $topic,
    ?array $workspace,
    array $relatedMemory,
    ?string $researchContext,
    array $existingPosts,
    string $mode = BLOG_MODE_ORIGINAL,
    bool $includeReference = false,
    array $sourceSnippets = []
): string {
    $context = build_context_block(null, null, null, $workspace, $relatedMemory);

    $parts = [$context];

    if ($mode === BLOG_MODE_GROUNDED && $sourceSnippets) {
        $lines = array_map(
            fn ($s) => '- ' . $s['text'] . ($s['source'] ? ' (' . $s['source'] . ')' : ''),
            $sourceSnippets
        );
        $parts[] = "SOURCE FACTS (extract ONLY the concrete facts/values below — numbers, names, dates, what specifically happened — and express them in entirely new sentences and structure of your own; do NOT copy or closely paraphrase any wording from this text, and do not quote it directly):\n"
            . implode("\n", $lines);
    } elseif ($researchContext) {
        $parts[] = "RELATED HEADLINES FROM THE SAME TREND (for grounding — don't just summarize these, write original commentary/analysis):\n{$researchContext}";
    }

    if ($mode === BLOG_MODE_GROUNDED) {
        $parts[] = $includeReference && $sourceSnippets
            ? 'End the post with a short "Source:" line crediting ' . ($sourceSnippets[0]['source'] ?: 'the original source')
                . ' with a link: <a href="' . h($sourceSnippets[0]['url']) . '">' . ($sourceSnippets[0]['source'] ?: 'source') . '</a>.'
            : 'Do not cite, link to, or mention any source by name.';
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
    $lengthKey = $topic['length'] ?? BLOG_LENGTH_DEFAULT;
    $wordCount = resolve_length_preset($lengthKey, BLOG_LENGTH_PRESETS, BLOG_LENGTH_DEFAULT);
    $isLongForm = in_array($lengthKey, ['w1000', 'w2000'], true);
    $structure = BLOG_STRUCTURE . ($isLongForm ? BLOG_STRUCTURE_LONG_FORM_ADDITIONS : '');
    $parts[] = <<<PROMPT
TASK: Write an original, SEO-friendly blog post on this topic: "{$topic['title']}"
{$newsLine}

{$structure}

Requirements:
- {$wordCount}, written in the voice/tone described above.
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

function generate_blog_post_via_ai(
    array $topic,
    array $aiConfig,
    ?array $workspace,
    array $relatedMemory = [],
    ?string $researchContext = null,
    array $existingPosts = [],
    string $mode = BLOG_MODE_ORIGINAL,
    bool $includeReference = false,
    array $sourceSnippets = []
): array {
    $provider = $aiConfig['provider'] ?? 'gemini';
    $label = AI_PROVIDER_LABELS[$provider] ?? ucfirst($provider);

    if (!ai_configured($aiConfig)) {
        throw new RuntimeException("Add a {$label} API key in Settings to generate a blog post.");
    }

    $prompt = build_blog_prompt($topic, $workspace, $relatedMemory, $researchContext, $existingPosts, $mode, $includeReference, $sourceSnippets);

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
