<?php
// News-driven auto content: fetch trending headlines from Google News
// RSS (no API key — public feeds at news.google.com/rss/search?q=...),
// store them per user, and turn them into draft posts written in the
// user's voice (Brand/Self Brief + Content Pillar context) via the same
// AI generation path every other flow uses. Consumed by
// cron/news_daily.php (scheduled) and pages/news_studio.php (manual).
//
// Copyright posture: only the headline, source name, link, and date are
// stored or shown to the AI — never article body text. The generated
// post is the user's own first-person commentary on the story (see the
// NEWS block in includes/ai_generate.php build_generation_prompt()).

// Feed locale — override in config.php if your audience is elsewhere.
if (!defined('NEWS_FEED_LANG')) {
    define('NEWS_FEED_LANG', 'en-IN');   // hl
}
if (!defined('NEWS_FEED_COUNTRY')) {
    define('NEWS_FEED_COUNTRY', 'IN');   // gl; ceid becomes "IN:en"
}

const NEWS_ITEMS_PER_QUERY = 8;    // top-N freshest headlines kept per query
const NEWS_MAX_AGE_DAYS    = 7;    // older items in the feed are ignored

// ── Custom keyword topics (Settings CRUD) ────────────────────────────

function fetch_news_topics(int $userId): array
{
    $stmt = db()->prepare('SELECT id, query FROM news_topics WHERE user_id = ? ORDER BY query');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function add_news_topic(int $userId, string $query): void
{
    $query = trim($query);
    if ($query === '') {
        return;
    }
    db()->prepare('INSERT IGNORE INTO news_topics (user_id, query) VALUES (?, ?)')->execute([$userId, $query]);
}

function delete_news_topic(int $userId, int $id): void
{
    db()->prepare('DELETE FROM news_topics WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
}

// ── Query building ───────────────────────────────────────────────────

// Every Content Pillar name is searched automatically (that's what the
// user posts about), plus any custom keywords added in Settings. Pillar
// queries carry the pillar id through to the stored item, so generation
// can pull that pillar's description/brief/design defaults later.
function news_build_queries(int $userId): array
{
    $queries = [];
    foreach (fetch_content_pillars($userId) as $pillar) {
        $queries[] = ['query' => $pillar['name'], 'pillar_id' => (int) $pillar['id']];
    }
    foreach (fetch_news_topics($userId) as $topic) {
        $queries[] = ['query' => $topic['query'], 'pillar_id' => null];
    }
    // Dedupe by lowercased query text (a keyword duplicating a pillar
    // name would double-fetch the same feed) — first entry wins, so the
    // pillar-linked version is kept.
    $seen = [];
    return array_values(array_filter($queries, function ($q) use (&$seen) {
        $key = mb_strtolower($q['query']);
        if (isset($seen[$key])) {
            return false;
        }
        $seen[$key] = true;
        return true;
    }));
}

// ── Fetch + parse ────────────────────────────────────────────────────

function news_feed_url(string $query): string
{
    $lang = explode('-', NEWS_FEED_LANG)[0];
    return 'https://news.google.com/rss/search?q=' . urlencode($query)
        . '&hl=' . urlencode(NEWS_FEED_LANG)
        . '&gl=' . urlencode(NEWS_FEED_COUNTRY)
        . '&ceid=' . urlencode(NEWS_FEED_COUNTRY . ':' . $lang);
}

function news_fetch_feed_xml(string $query): string
{
    $ch = curl_init(news_feed_url($query));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LinkedInScheduler/1.0)',
    ]);
    $xml = curl_exec($ch);
    if ($xml === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Google News fetch failed: {$err}");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        throw new RuntimeException("Google News fetch failed: HTTP {$status}");
    }
    return $xml;
}

// Parses a Google News RSS document into
// [['title','url','source','published_at'], ...] — separated from the
// network call so it can be tested against fixture XML. Item titles
// come as "Headline - Source"; the trailing source is stripped when the
// <source> tag confirms it.
function news_parse_feed(string $xml): array
{
    $prev = libxml_use_internal_errors(true);
    $feed = simplexml_load_string($xml);
    libxml_use_internal_errors($prev);
    if ($feed === false || !isset($feed->channel->item)) {
        return [];
    }
    $items = [];
    foreach ($feed->channel->item as $item) {
        $title = trim((string) $item->title);
        $url = trim((string) $item->link);
        if ($title === '' || $url === '') {
            continue;
        }
        $source = trim((string) ($item->source ?? ''));
        if ($source !== '' && str_ends_with($title, ' - ' . $source)) {
            $title = substr($title, 0, -strlen(' - ' . $source));
        }
        $pubDate = trim((string) ($item->pubDate ?? ''));
        $publishedAt = $pubDate !== '' ? date('Y-m-d H:i:s', strtotime($pubDate) ?: time()) : null;
        $items[] = [
            'title'        => $title,
            'url'          => $url,
            'source'       => $source ?: null,
            'published_at' => $publishedAt,
        ];
    }
    return $items;
}

// ── Store ────────────────────────────────────────────────────────────

// Inserts parsed items for one query, skipping anything already seen
// (per-user url_hash unique key) or older than NEWS_MAX_AGE_DAYS.
// Returns how many new rows were stored.
function news_store_items(int $userId, string $query, ?int $pillarId, array $items): int
{
    $cutoff = date('Y-m-d H:i:s', strtotime('-' . NEWS_MAX_AGE_DAYS . ' days'));
    $stmt = db()->prepare(
        'INSERT IGNORE INTO news_items (user_id, topic_query, content_pillar_id, title, url, url_hash, source, published_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stored = 0;
    foreach (array_slice($items, 0, NEWS_ITEMS_PER_QUERY) as $item) {
        if ($item['published_at'] !== null && $item['published_at'] < $cutoff) {
            continue;
        }
        $stmt->execute([
            $userId, mb_substr($query, 0, 255), $pillarId,
            mb_substr($item['title'], 0, 500),
            mb_substr($item['url'], 0, 1000),
            sha1($item['url']),
            $item['source'] !== null ? mb_substr($item['source'], 0, 255) : null,
            $item['published_at'],
        ]);
        $stored += $stmt->rowCount() > 0 ? 1 : 0;
    }
    return $stored;
}

// Fetches every query's feed and stores fresh items. Per-query failures
// are collected, not fatal — one bad feed shouldn't lose the rest.
// Returns ['fetched' => queries tried, 'stored' => new items, 'errors' => [msg]].
function news_refresh(int $userId): array
{
    $stored = 0;
    $errors = [];
    $queries = news_build_queries($userId);
    foreach ($queries as $q) {
        try {
            $items = news_parse_feed(news_fetch_feed_xml($q['query']));
            $stored += news_store_items($userId, $q['query'], $q['pillar_id'], $items);
        } catch (Throwable $e) {
            $errors[] = "\"{$q['query']}\": " . $e->getMessage();
        }
    }
    return ['fetched' => count($queries), 'stored' => $stored, 'errors' => $errors];
}

// ── Generation ───────────────────────────────────────────────────────

// Turns one stored headline into a complete draft post: AI writes the
// user's first-person take (news context threaded into the prompt via
// the row's "News" field), the image is rendered immediately for
// Single Image/Carousel so the draft is fully reviewable, and the item
// is marked used. Returns the new post id.
function news_generate_draft(int $userId, array $newsItem, array $aiConfig, ?string $format = null): int
{
    $pillar = $newsItem['content_pillar_id'] ? fetch_content_pillar($userId, (int) $newsItem['content_pillar_id']) : null;

    if ($format === null) {
        $enabled = array_values(array_intersect(['Text Post', 'Single Image', 'Carousel'], get_enabled_formats($userId)));
        if (!$enabled) {
            throw new RuntimeException('All post formats are disabled in Settings.');
        }
        $format = $enabled[array_rand($enabled)];
    }

    $meta = array_filter([
        $newsItem['source'] ? 'reported by ' . $newsItem['source'] : null,
        $newsItem['published_at'] ? date('j M Y', strtotime($newsItem['published_at'])) : null,
    ]);
    $newsLine = $newsItem['title'] . ($meta ? ' (' . implode(', ', $meta) . ')' : '');

    $row = [
        'Topic / Title'  => $newsItem['title'],
        'Target Persona' => '',
        'Type'           => $pillar['name'] ?? ($newsItem['topic_query'] ?? ''),
        'CTA'            => '',
        'Tag Page'       => '',
        'Post Caption'   => '',
        'Final_Format'   => $format,
        'News'           => $newsLine,
    ];

    $brief = resolve_brief_for_pillar($userId, $pillar);
    $creative = generate_creative_via_ai($row, $aiConfig, $brief, null, $pillar);
    $creative['layout'] = resolve_default_layout($userId, $creative['format'], $pillar['name'] ?? null);
    $paletteDefault = resolve_default_palette($userId, $creative['format'], $pillar['name'] ?? null);
    if ($paletteDefault !== null && empty($creative['template'])) {
        $creative['template'] = str_starts_with($paletteDefault, 'custom:') ? $paletteDefault : (int) $paletteDefault;
    }

    $campaignId = 'NEWS-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    $title = trim($creative['title'] ?? '') ?: mb_substr($newsItem['title'], 0, 200);
    $caption = trim($creative['caption'] ?? '');

    db()->prepare(
        'INSERT INTO posts (user_id, campaign_id, title, format, caption, status, content_pillar_id, creative_json)
         VALUES (?, ?, ?, ?, ?, "draft", ?, ?)'
    )->execute([
        $userId, $campaignId, $title, $format, $caption,
        $newsItem['content_pillar_id'] ?: null, json_encode($creative),
    ]);
    $postId = (int) db()->lastInsertId();

    if (in_array($format, ['Single Image', 'Carousel'], true) && !empty($creative['slides'])) {
        $user = db()->prepare('SELECT name, email FROM users WHERE id = ?');
        $user->execute([$userId]);
        $u = $user->fetch();
        $footerName = trim($u['name'] ?? '') ?: explode('@', $u['email'] ?? 'Your Name')[0];
        $category = ($pillar['category'] ?? 'personal') === 'company' ? 'company' : 'personal';
        $photoPath = resolve_footer_image($userId, $category);
        $destDir = UPLOAD_DIR . '/' . $userId . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $campaignId);
        try {
            $slides = render_creative_to_slides($creative, $destDir, $footerName, $photoPath, $userId);
        } catch (Throwable $e) {
            db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
            throw new RuntimeException('Image rendering failed: ' . $e->getMessage());
        }
        $insertSlide = db()->prepare('INSERT INTO post_slides (post_id, slide_order, filename, filepath) VALUES (?, ?, ?, ?)');
        foreach ($slides as $order => $slide) {
            $insertSlide->execute([$postId, $order + 1, $slide['filename'], $slide['filepath']]);
        }
    }

    db()->prepare('UPDATE news_items SET status = "used", post_id = ? WHERE id = ? AND user_id = ?')
        ->execute([$postId, (int) $newsItem['id'], $userId]);

    return $postId;
}

// The freshest unused headlines, spread across queries so one hot topic
// doesn't take every auto-draft slot: picks at most one item per
// topic_query first, then fills remaining slots by recency.
function news_pick_items_for_drafts(int $userId, int $count): array
{
    $stmt = db()->prepare(
        "SELECT * FROM news_items
         WHERE user_id = ? AND status = 'new'
         ORDER BY COALESCE(published_at, fetched_at) DESC
         LIMIT 100"
    );
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll();

    $picked = [];
    $usedQueries = [];
    foreach ($items as $item) {
        if (count($picked) >= $count) {
            break;
        }
        if (!isset($usedQueries[$item['topic_query']])) {
            $usedQueries[$item['topic_query']] = true;
            $picked[] = $item;
        }
    }
    foreach ($items as $item) {
        if (count($picked) >= $count) {
            break;
        }
        if (!in_array($item, $picked, true)) {
            $picked[] = $item;
        }
    }
    return $picked;
}
