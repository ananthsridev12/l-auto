<?php
// Sitemap-based internal linking — a per-workspace sitemap.xml URL,
// manually fetched (pages/settings.php's "Fetch sitemap now"), parsed
// into individual page links so Blog Studio's internal-linking prompt
// block (build_blog_prompt() in includes/blog_generate.php) can
// reference pages that exist on the site but weren't created through
// this app. No live page-title fetching — a sitemap only reliably
// gives <loc> (and sometimes <lastmod>), so title/category are both
// derived from the URL itself, not by crawling every page it lists.

const SITEMAP_MAX_LINKS = 200; // cap per fetch — a runaway sitemap shouldn't flood the table or the prompt

function fetch_sitemap_links(int $workspaceId): array
{
    $stmt = db()->prepare('SELECT * FROM sitemap_links WHERE workspace_id = ? ORDER BY category, title');
    $stmt->execute([$workspaceId]);
    return $stmt->fetchAll();
}

// "/blog/how-to-fix-x" -> "How To Fix X". Falls back to "Home" for the
// root path, since there's nothing slug-like to humanize there.
function sitemap_humanize_title(string $url): string
{
    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    if ($path === '') {
        return 'Home';
    }
    $last = basename($path);
    $words = array_filter(preg_split('/[-_]+/', $last) ?: [$last], fn ($w) => $w !== '');
    return $words ? implode(' ', array_map('ucfirst', $words)) : $last;
}

// First path segment as a coarse category ("/blog/..." -> "blog",
// "/guides/..." -> "guides"); a single-segment or root-level page gets
// "page". Deliberately simple — no taxonomy to configure, just enough
// to group internal-link suggestions in the prompt and in Settings.
function sitemap_category_from_url(string $url): string
{
    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    if ($path === '') {
        return 'page';
    }
    $segments = explode('/', $path);
    return count($segments) > 1 ? $segments[0] : 'page';
}

// Parses a <urlset> sitemap into a flat list of <loc> URLs. A
// <sitemapindex> (a sitemap that only lists other sitemaps, common on
// larger sites) isn't followed — that's a deliberate scope limit for a
// first version, not a bug — it just yields an empty list, which
// sitemap_fetch_and_store() below surfaces as a specific error rather
// than silently storing nothing.
function sitemap_parse_xml(string $xml): array
{
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    libxml_use_internal_errors($prev);
    if ($doc === false || !isset($doc->url)) {
        return [];
    }
    $urls = [];
    foreach ($doc->url as $entry) {
        $loc = trim((string) $entry->loc);
        if ($loc !== '') {
            $urls[] = $loc;
        }
    }
    return $urls;
}

// Fetches $url, parses it, and REPLACES this workspace's stored links
// wholesale (rather than only adding new ones) so a page removed from
// the live sitemap doesn't linger forever as a stale internal-link
// suggestion. Returns ['fetched' => int, 'stored' => int, 'error' => ?string].
function sitemap_fetch_and_store(int $workspaceId, string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LinkedInScheduler/1.0)',
    ]);
    $xml = curl_exec($ch);
    if ($xml === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['fetched' => 0, 'stored' => 0, 'error' => "Sitemap fetch failed: {$err}"];
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        return ['fetched' => 0, 'stored' => 0, 'error' => "Sitemap fetch failed: HTTP {$status}"];
    }

    $urls = array_slice(sitemap_parse_xml($xml), 0, SITEMAP_MAX_LINKS);
    if (!$urls) {
        return ['fetched' => 0, 'stored' => 0, 'error' => 'No <url><loc> entries found — is this a sitemap index (a sitemap of sitemaps) rather than a flat sitemap?'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM sitemap_links WHERE workspace_id = ?')->execute([$workspaceId]);
    $insert = $pdo->prepare('INSERT INTO sitemap_links (workspace_id, url, title, category) VALUES (?, ?, ?, ?)');
    foreach ($urls as $u) {
        $insert->execute([
            $workspaceId,
            mb_substr($u, 0, 1000),
            mb_substr(sitemap_humanize_title($u), 0, 500),
            sitemap_category_from_url($u),
        ]);
    }
    $pdo->commit();

    return ['fetched' => count($urls), 'stored' => count($urls), 'error' => null];
}
