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
// call by passing existing published posts' title/slug pairs, plus
// (optionally) sitemap-discovered pages (includes/sitemap.php) for
// pages that exist on the site but weren't created through this app.
//
// "Fresh Context" (no Memory & Context, no Knowledge Hub voice) isn't a
// parameter here — the caller achieves it by simply passing
// $workspace = null and $relatedMemory = [], the same as generating
// with nothing configured at all. No separate code path needed.

// Named content skeleton every generated post follows, keyed by
// BLOG_CONTENT_TYPES below — replacing what used to be a single loose
// "3-6 H2 subheadings" line, and now a family of them for different
// post shapes. BLOG_TOC_MIN_WORDS gates the two long-form-only
// additions (Table of Contents, FAQ) — forcing them onto a ~100-200
// word post would overwhelm it.
const BLOG_CONTENT_TYPE_DEFAULT = 'analysis';

const BLOG_STRUCTURE_ANALYSIS = <<<STRUCT
STRUCTURE — ANALYSIS/OPINION (follow this shape; adapt section count to the target word count — a short post may compress steps into fewer, shorter sections rather than dropping the shape entirely):
1. Hook — 1-2 sentences, no heading. Open with a stat, a question, or a contrarian line — not a throat-clearing intro.
2. Context (<h2>) — why this matters right now.
3. 2-4 Analysis sections (<h2> each) — the actual substance, one clear idea per section.
4. Takeaways (<h2>) — 3-5 bullet points: practical "what to do with this."
5. Conclusion (<h2>, can be titled naturally) — short wrap-up + a natural call to action.
STRUCT;

const BLOG_STRUCTURE_LISTICLE = <<<STRUCT
STRUCTURE — LISTICLE:
1. Hook — 1-2 sentences framing why this list matters right now, ending on the "here are N ways/things..." premise.
2. One <h2> per list item — a short, punchy sub-headline per item, then a paragraph on that single idea. Pick a number of items that suits the target word count (roughly one item per 100-150 words) — never pad with a filler item just to hit a round number.
3. Takeaways (<h2>) — 3-5 bullet points recapping the single most useful line from each item.
4. Conclusion (<h2>) — short wrap-up + a natural call to action.
STRUCT;

const BLOG_STRUCTURE_HOWTO = <<<STRUCT
STRUCTURE — HOW-TO / GUIDE:
1. Hook — 1-2 sentences on the outcome this guide delivers and why it's worth the reader's time.
2. Context (<h2>) — what's needed before starting (prerequisites, when this approach applies).
3. Numbered Steps — one <h2> per step ("Step 1: ...", "Step 2: ...", etc.), each with clear, actionable instructions. Pick a number of steps that suits the target word count.
4. Common Mistakes (<h2>) — 2-4 bullet points on pitfalls to avoid.
5. Conclusion (<h2>) — short wrap-up + a natural call to action.
STRUCT;

const BLOG_STRUCTURE_COMPARISON = <<<STRUCT
STRUCTURE — COMPARISON:
1. Hook — 1-2 sentences framing the decision the reader is trying to make.
2. Context (<h2>) — what's being compared and why it matters.
3. Side-by-side sections — one <h2> per comparison dimension (pick dimensions that genuinely fit the topic, e.g. cost, ease of use, scalability), each covering both sides briefly.
4. Summary Table (<h2>) — an HTML <table> with a header row and one row per dimension, one column per option compared.
5. Recommendation (<h2>) — a clear, opinionated verdict on when to choose which option, + a natural call to action.
STRUCT;

const BLOG_STRUCTURE_NEWS_ROUNDUP = <<<STRUCT
STRUCTURE — NEWS ROUNDUP:
1. Hook — 1-2 sentences on the overall theme connecting these stories.
2. One <h2> per story — a headline-style sub-heading, 2-3 sentences summarizing what happened (from the SOURCE FACTS below, entirely in your own words), followed by 1-2 sentences of your own take on why it matters.
3. Takeaways (<h2>) — 3-5 bullet points: the through-line connecting all the stories.
4. Conclusion (<h2>) — short wrap-up + a natural call to action.
STRUCT;

const BLOG_STRUCTURE_CASE_STUDY = <<<STRUCT
STRUCTURE — CASE STUDY:
1. Hook — 1-2 sentences stating the headline result (a number if you have one).
2. Situation (<h2>) — the problem/context before.
3. Approach (<h2>) — what was actually done, specifically.
4. Results (<h2>) — the concrete outcomes, ideally with numbers.
5. Takeaways (<h2>) — 3-5 bullet points on what other readers can apply from this.
6. Conclusion (<h2>) — short wrap-up + a natural call to action.
Only use real client/case details actually present in the Proof Point context above — never invent a client, number, or outcome. If no relevant Proof Point exists, write a realistic composite scenario and clearly label it as illustrative rather than presenting invented specifics as real.
STRUCT;

const BLOG_STRUCTURE_CHECKLIST = <<<STRUCT
STRUCTURE — CHECKLIST:
1. Hook — 1-2 sentences on what this checklist helps the reader avoid or achieve.
2. Context (<h2>) — when/why to use this checklist.
3. The Checklist (<h2>) — a single <ul> of checklist items (plain <li> text, not literal checkbox markup), each a short, specific, actionable statement. Pick a number of items that suits the target word count.
4. Conclusion (<h2>) — short wrap-up + a natural call to action.
STRUCT;

// label: shown in the Content Type picker. structure: the STRUCTURE
// block swapped into the prompt. requires_grounded: true means this
// type only makes sense with real source facts (News Roundup) —
// build_blog_prompt() silently falls back to 'analysis' if picked
// without BLOG_MODE_GROUNDED source snippets, same graceful-degradation
// pattern as the mode fallback itself (see pages/news_studio.php).
const BLOG_CONTENT_TYPES = [
    'analysis'     => ['label' => 'Analysis / Opinion', 'structure' => BLOG_STRUCTURE_ANALYSIS,     'requires_grounded' => false],
    'listicle'     => ['label' => 'Listicle',            'structure' => BLOG_STRUCTURE_LISTICLE,     'requires_grounded' => false],
    'howto'        => ['label' => 'How-To / Guide',       'structure' => BLOG_STRUCTURE_HOWTO,        'requires_grounded' => false],
    'comparison'   => ['label' => 'Comparison (X vs Y)',  'structure' => BLOG_STRUCTURE_COMPARISON,   'requires_grounded' => false],
    'news_roundup' => ['label' => 'News Roundup',         'structure' => BLOG_STRUCTURE_NEWS_ROUNDUP, 'requires_grounded' => true],
    'case_study'   => ['label' => 'Case Study',           'structure' => BLOG_STRUCTURE_CASE_STUDY,   'requires_grounded' => false],
    'checklist'    => ['label' => 'Checklist',             'structure' => BLOG_STRUCTURE_CHECKLIST,    'requires_grounded' => false],
];

const BLOG_TOC_MIN_WORDS_KEYS = ['w1000', 'w2000']; // BLOG_LENGTH_PRESETS keys long enough to earn a TOC/FAQ
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
// $existingPosts: [['title'=>string,'slug'=>?string,'url'=>?string,'category'=>?string], ...]
// for internal linking — either an in-app post (slug set, url null, built
// as {workspace website}/{slug}) or a sitemap-discovered page (url set
// directly, see includes/sitemap.php); 'category' is optional context,
// shown alongside the title when present.
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
    array $sourceSnippets = [],
    string $contentType = BLOG_CONTENT_TYPE_DEFAULT
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
        $website = trim((string) ($workspace['website'] ?? ''));
        $base = $website !== '' ? rtrim($website, '/') : '';
        $links = implode("\n", array_map(
            function ($p) use ($base) {
                $href = $p['url'] ?? ($base . '/' . ltrim((string) ($p['slug'] ?? ''), '/'));
                $tag = !empty($p['category']) ? ' [' . $p['category'] . ']' : '';
                return "- \"{$p['title']}\"{$tag} -> {$href}";
            },
            array_slice($existingPosts, 0, 20)
        ));
        $parts[] = "EXISTING PAGES ON THIS SITE (weave in 2-4 contextual links where genuinely relevant, as <a href=\"{url}\">anchor text</a> — never force a link that doesn't fit):\n{$links}";
    }

    // A caller that asks for News Roundup without real source facts gets
    // the same silent fallback as an unset/invalid content type — the
    // same principle as BLOG_MODE_GROUNDED itself falling back to
    // Original Take with nothing to ground on (see pages/news_studio.php).
    $typeKey = array_key_exists($contentType, BLOG_CONTENT_TYPES) ? $contentType : BLOG_CONTENT_TYPE_DEFAULT;
    if (BLOG_CONTENT_TYPES[$typeKey]['requires_grounded'] && !($mode === BLOG_MODE_GROUNDED && $sourceSnippets)) {
        $typeKey = BLOG_CONTENT_TYPE_DEFAULT;
    }

    $newsLine = trim((string) ($topic['news_line'] ?? ''));
    $lengthKey = $topic['length'] ?? BLOG_LENGTH_DEFAULT;
    $wordCount = resolve_length_preset($lengthKey, BLOG_LENGTH_PRESETS, BLOG_LENGTH_DEFAULT);
    $isLongForm = in_array($lengthKey, BLOG_TOC_MIN_WORDS_KEYS, true);
    $structure = BLOG_CONTENT_TYPES[$typeKey]['structure'] . ($isLongForm ? BLOG_STRUCTURE_LONG_FORM_ADDITIONS : '');
    $parts[] = <<<PROMPT
TASK: Write an original, SEO-friendly blog post on this topic: "{$topic['title']}"
{$newsLine}

{$structure}

Requirements:
- {$wordCount}, written in the voice/tone described above.
- Original analysis and perspective, not a rehash of any single source.
- Naturally incorporate the target keywords if any are implied by the topic.
- HTML body only (content_html) — use <h2>, <p>, <ul>/<li>, <strong>, <a>, and (Comparison type only) <table> as appropriate. No <html>/<body>/<script> tags, no inline styles.
- content_html must NOT start with a heading that repeats or rephrases the "title" field — the title is already displayed separately by the page template as its own <h1> above the body, so restating it in content_html duplicates it on the page. Start content_html directly with the opening paragraph; the first <h2> (if any) should be the first real subsection heading, not a restatement of the title/topic.

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
    array $sourceSnippets = [],
    string $contentType = BLOG_CONTENT_TYPE_DEFAULT
): array {
    $provider = $aiConfig['provider'] ?? 'gemini';
    $label = AI_PROVIDER_LABELS[$provider] ?? ucfirst($provider);

    if (!ai_configured($aiConfig)) {
        throw new RuntimeException("Add a {$label} API key in Settings to generate a blog post.");
    }

    $prompt = build_blog_prompt($topic, $workspace, $relatedMemory, $researchContext, $existingPosts, $mode, $includeReference, $sourceSnippets, $contentType);

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
    $creative['content_html'] = blog_strip_duplicate_title_heading((string) $creative['content_html'], (string) $creative['title']);

    return $creative;
}

// Safety net on top of the prompt's own "don't restate the title" rule
// above — models occasionally do it anyway. The title is always
// rendered as its own <h1> by the page template and by every publish
// target's theme (WordPress/Jekyll/Grav all send title and content_html
// as separate fields, see includes/wordpress_api.php etc.), so a
// leading heading that just repeats it would show up twice on the
// actual page. Only strips a *leading* <h1>/<h2> that closely matches
// the title — a real first subsection heading that happens to share a
// few words is left alone.
function blog_strip_duplicate_title_heading(string $html, string $title): string
{
    $normalize = fn (string $s) => trim(preg_replace('/[^a-z0-9]+/', '', mb_strtolower(strip_tags($s))));
    $titleNorm = $normalize($title);
    if ($titleNorm === '' || !preg_match('/^\s*<h[12][^>]*>(.*?)<\/h[12]>\s*/is', $html, $m)) {
        return $html;
    }
    $headingNorm = $normalize($m[1]);
    if ($headingNorm === '') {
        return $html;
    }
    similar_text($titleNorm, $headingNorm, $percent);
    if ($headingNorm === $titleNorm || $percent > 70) {
        return ltrim(substr($html, strlen($m[0])));
    }
    return $html;
}
