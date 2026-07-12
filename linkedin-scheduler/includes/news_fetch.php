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

function fetch_news_topics(int $userId, ?int $workspaceId = null): array
{
    if ($workspaceId === null) {
        $stmt = db()->prepare('SELECT id, query FROM news_topics WHERE user_id = ? ORDER BY query');
        $stmt->execute([$userId]);
    } else {
        $stmt = db()->prepare('SELECT id, query FROM news_topics WHERE user_id = ? AND (workspace_id = ? OR workspace_id IS NULL) ORDER BY query');
        $stmt->execute([$userId, $workspaceId]);
    }
    return $stmt->fetchAll();
}

function add_news_topic(int $userId, string $query, ?int $workspaceId = null): void
{
    $query = trim($query);
    if ($query === '') {
        return;
    }
    db()->prepare('INSERT IGNORE INTO news_topics (user_id, workspace_id, query) VALUES (?, ?, ?)')->execute([$userId, $workspaceId, $query]);
}

function delete_news_topic(int $userId, int $id): void
{
    db()->prepare('DELETE FROM news_topics WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
}

// A topic entered as a URL is a direct RSS feed to fetch as-is, rather
// than a Google News search query — lets the user pull from specific
// publications they trust instead of (or on top of) open search.
function news_topic_is_feed(string $query): bool
{
    return (bool) preg_match('#^https?://#i', trim($query));
}

// ── Trusted sources allowlist ────────────────────────────────────────
// When non-empty, only Google News results whose publisher matches an
// entry (domain or name) are kept — everything else is dropped at fetch
// time. Direct feed URLs the user added themselves bypass this: adding
// the feed IS the trust decision.

function fetch_news_trusted_sources(int $userId, ?int $workspaceId = null): array
{
    if ($workspaceId === null) {
        $stmt = db()->prepare('SELECT id, source FROM news_trusted_sources WHERE user_id = ? ORDER BY source');
        $stmt->execute([$userId]);
    } else {
        $stmt = db()->prepare('SELECT id, source FROM news_trusted_sources WHERE user_id = ? AND (workspace_id = ? OR workspace_id IS NULL) ORDER BY source');
        $stmt->execute([$userId, $workspaceId]);
    }
    return $stmt->fetchAll();
}

function add_news_trusted_source(int $userId, string $source, ?int $workspaceId = null): void
{
    $source = trim($source);
    if ($source === '') {
        return;
    }
    db()->prepare('INSERT IGNORE INTO news_trusted_sources (user_id, workspace_id, source) VALUES (?, ?, ?)')->execute([$userId, $workspaceId, mb_substr($source, 0, 255)]);
}

function delete_news_trusted_source(int $userId, int $id): void
{
    db()->prepare('DELETE FROM news_trusted_sources WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
}

// "reuters.com", "www.reuters.com", "https://reuters.com/world" and
// "Reuters" should all behave the same — reduce an entry (or an item's
// domain) to a bare lowercase domain when it looks like one, else keep
// it as a lowercase name for substring matching.
function news_normalize_source_entry(string $entry): string
{
    $entry = mb_strtolower(trim($entry));
    $entry = preg_replace('#^https?://#', '', $entry);
    $entry = explode('/', $entry)[0];
    return preg_replace('/^www\./', '', $entry);
}

function news_source_is_trusted(array $item, array $trustedEntries): bool
{
    $domain = news_normalize_source_entry((string) ($item['source_domain'] ?? ''));
    $name = mb_strtolower((string) ($item['source'] ?? ''));
    foreach ($trustedEntries as $entry) {
        $t = news_normalize_source_entry($entry);
        if ($t === '') {
            continue;
        }
        if ($domain !== '' && ($domain === $t || str_ends_with($domain, '.' . $t))) {
            return true;
        }
        if ($name !== '' && str_contains($name, $t)) {
            return true;
        }
    }
    return false;
}

// ── Query building ───────────────────────────────────────────────────

// Every Content Pillar name is searched automatically (that's what the
// user posts about), plus any custom keywords added in Settings. Pillar
// queries carry the pillar id through to the stored item, so generation
// can pull that pillar's description/brief/design defaults later.
function news_build_queries(int $userId, ?int $workspaceId = null): array
{
    $queries = [];
    foreach (fetch_content_pillars($userId, $workspaceId) as $pillar) {
        $queries[] = ['query' => $pillar['name'], 'pillar_id' => (int) $pillar['id'], 'feed' => false];
    }
    foreach (fetch_news_topics($userId, $workspaceId) as $topic) {
        $queries[] = ['query' => $topic['query'], 'pillar_id' => null, 'feed' => news_topic_is_feed($topic['query'])];
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

// Fetches raw feed XML — $target is either a search query (turned into
// a Google News RSS URL) or, for direct feed topics, the feed URL itself.
function news_fetch_feed_xml(string $target, bool $isFeedUrl = false): string
{
    $url = $isFeedUrl ? $target : news_feed_url($target);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LinkedInScheduler/1.0)',
    ]);
    $xml = curl_exec($ch);
    $label = $isFeedUrl ? 'Feed fetch' : 'Google News fetch';
    if ($xml === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("{$label} failed: {$err}");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        throw new RuntimeException("{$label} failed: HTTP {$status}");
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
        // Publisher domain, for the trusted-sources allowlist: Google
        // News carries it in <source url="...">; plain publication feeds
        // have no <source> tag, but there the article link itself points
        // at the publisher (unlike Google News's redirect links).
        $sourceUrl = trim((string) ($item->source['url'] ?? ''));
        $sourceDomain = parse_url($sourceUrl !== '' ? $sourceUrl : $url, PHP_URL_HOST) ?: null;
        $pubDate = trim((string) ($item->pubDate ?? ''));
        $publishedAt = $pubDate !== '' ? date('Y-m-d H:i:s', strtotime($pubDate) ?: time()) : null;
        $items[] = [
            'title'         => $title,
            'url'           => $url,
            'source'        => $source ?: null,
            'source_domain' => $sourceDomain,
            'published_at'  => $publishedAt,
        ];
    }
    return $items;
}

// ── Store ────────────────────────────────────────────────────────────

// Inserts parsed items for one query, skipping anything already seen
// (per-user url_hash unique key) or older than NEWS_MAX_AGE_DAYS.
// Returns how many new rows were stored.
function news_store_items(int $userId, string $query, ?int $pillarId, array $items, ?int $workspaceId = null): int
{
    $cutoff = date('Y-m-d H:i:s', strtotime('-' . NEWS_MAX_AGE_DAYS . ' days'));
    $stmt = db()->prepare(
        'INSERT IGNORE INTO news_items (user_id, workspace_id, topic_query, content_pillar_id, title, url, url_hash, source, published_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stored = 0;
    foreach (array_slice($items, 0, NEWS_ITEMS_PER_QUERY) as $item) {
        if ($item['published_at'] !== null && $item['published_at'] < $cutoff) {
            continue;
        }
        $stmt->execute([
            $userId, $workspaceId, mb_substr($query, 0, 255), $pillarId,
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
// Google News results are filtered through the trusted-sources
// allowlist when one is configured; direct feed URLs bypass it (adding
// the feed was the trust decision).
// Returns ['fetched' => queries tried, 'stored' => new items, 'errors' => [msg]].
function news_refresh(int $userId, ?int $workspaceId = null): array
{
    $stored = 0;
    $errors = [];
    $queries = news_build_queries($userId, $workspaceId);
    $trusted = array_column(fetch_news_trusted_sources($userId, $workspaceId), 'source');
    foreach ($queries as $q) {
        $isFeed = !empty($q['feed']);
        try {
            $items = news_parse_feed(news_fetch_feed_xml($q['query'], $isFeed));
            if ($isFeed) {
                // Plain publication feeds usually have no <source> tag —
                // label items with the feed's host so the UI shows where
                // they came from.
                $feedHost = news_normalize_source_entry($q['query']);
                foreach ($items as &$item) {
                    $item['source'] = $item['source'] ?? $feedHost;
                }
                unset($item);
            } elseif ($trusted) {
                $items = array_values(array_filter($items, fn ($item) => news_source_is_trusted($item, $trusted)));
            }
            $stored += news_store_items($userId, $q['query'], $q['pillar_id'], $items, $workspaceId);
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

    // Workspace context (profile fields + uploaded documents) beats the
    // legacy brief pair when the item belongs to a workspace.
    $workspace = !empty($newsItem['workspace_id']) ? fetch_workspace($userId, (int) $newsItem['workspace_id']) : null;
    $wsId = $workspace ? (int) $workspace['id'] : null;
    $brief = $workspace ? null : resolve_brief_for_pillar($userId, $pillar);
    $relatedMemory = $wsId ? content_memory_related_for_topic($wsId, $newsItem['title'], $aiConfig) : [];
    $creative = generate_creative_via_ai($row, $aiConfig, $brief, null, $pillar, $workspace, $relatedMemory);
    $creative['layout'] = resolve_default_layout($userId, $creative['format'], $pillar['name'] ?? null, $wsId);
    $paletteDefault = resolve_default_palette($userId, $creative['format'], $pillar['name'] ?? null, $wsId);
    if ($paletteDefault !== null && empty($creative['template'])) {
        $creative['template'] = str_starts_with($paletteDefault, 'custom:') ? $paletteDefault : (int) $paletteDefault;
    }

    $campaignId = 'NEWS-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    $title = trim($creative['title'] ?? '') ?: mb_substr($newsItem['title'], 0, 200);
    $caption = trim($creative['caption'] ?? '');

    db()->prepare(
        'INSERT INTO posts (user_id, workspace_id, linkedin_account_id, campaign_id, title, format, caption, status, content_pillar_id, creative_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, "draft", ?, ?)'
    )->execute([
        $userId, $wsId, $workspace['linkedin_account_id'] ?? null, $campaignId, $title, $format, $caption,
        $newsItem['content_pillar_id'] ?: null, json_encode($creative),
    ]);
    $postId = (int) db()->lastInsertId();

    if (in_array($format, ['Single Image', 'Carousel'], true) && !empty($creative['slides'])) {
        $user = db()->prepare('SELECT name, email FROM users WHERE id = ?');
        $user->execute([$userId]);
        $u = $user->fetch();
        $footerName = trim($u['name'] ?? '') ?: explode('@', $u['email'] ?? 'Your Name')[0];
        $category = $workspace ? ($workspace['type'] === 'company' ? 'company' : 'personal')
            : (($pillar['category'] ?? 'personal') === 'company' ? 'company' : 'personal');
        $photoPath = resolve_footer_image($userId, $category, $wsId);
        $destDir = UPLOAD_DIR . '/' . $userId . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $campaignId);
        try {
            $slides = render_creative_to_slides($creative, $destDir, $footerName, $photoPath, $userId, $wsId);
        } catch (Throwable $e) {
            db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
            throw new RuntimeException('Image rendering failed: ' . $e->getMessage());
        }
        $insertSlide = db()->prepare('INSERT INTO post_slides (post_id, slide_order, filename, filepath) VALUES (?, ?, ?, ?)');
        foreach ($slides as $order => $slide) {
            $insertSlide->execute([$postId, $order + 1, $slide['filename'], $slide['filepath']]);
        }
    }

    if ($wsId && $caption !== '') {
        save_content_memory($wsId, $postId, trim($title . ' ' . $caption), $title ?: mb_substr($caption, 0, 200), $aiConfig);
    }

    db()->prepare('UPDATE news_items SET status = "used", post_id = ? WHERE id = ? AND user_id = ?')
        ->execute([$postId, (int) $newsItem['id'], $userId]);

    return $postId;
}

// The freshest unused headlines, spread across queries so one hot topic
// doesn't take every auto-draft slot: picks at most one item per
// topic_query first, then fills remaining slots by recency.
function news_pick_items_for_drafts(int $userId, int $count, ?int $workspaceId = null): array
{
    if ($workspaceId === null) {
        $stmt = db()->prepare(
            "SELECT * FROM news_items WHERE user_id = ? AND status = 'new'
             ORDER BY COALESCE(published_at, fetched_at) DESC LIMIT 100"
        );
        $stmt->execute([$userId]);
    } else {
        $stmt = db()->prepare(
            "SELECT * FROM news_items WHERE user_id = ? AND (workspace_id = ? OR workspace_id IS NULL) AND status = 'new'
             ORDER BY COALESCE(published_at, fetched_at) DESC LIMIT 100"
        );
        $stmt->execute([$userId, $workspaceId]);
    }
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
