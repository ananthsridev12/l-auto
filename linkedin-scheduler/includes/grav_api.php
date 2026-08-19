<?php
// Grav REST API client — plain cURL, no library, same pattern as
// includes/wordpress_api.php and includes/jekyll_api.php. Unlike
// Jekyll, Grav is a live PHP CMS with no build step: a page created
// through this API is served immediately, no separate deploy step.
// Requires the official getgrav/grav-plugin-api plugin installed and
// enabled on the target site (user does this once via Grav's own
// Admin Panel — GPM install + generate an API key from their user
// profile). One site per workspace (workspaces.grav_site_url/
// grav_api_key/grav_route_prefix/grav_template).

function grav_configured(array $workspace): bool
{
    return !empty($workspace['grav_site_url']) && !empty($workspace['grav_api_key']);
}

function grav_site_url(array $workspace): string
{
    return rtrim((string) $workspace['grav_site_url'], '/');
}

// $pillar is the blog post's content_pillars row (fetch_content_pillar()),
// if it has one — its own grav_route_prefix takes priority over the
// workspace's when set (non-empty), so different pillars can route to
// different sections of the same Grav site (e.g. a "Product Updates"
// pillar under /blog/product/ vs "Industry News" under /blog/news/).
// NULL/empty on the pillar falls through to the workspace's value, same
// "no override" convention as content_pillars.default_layout.
function grav_route_prefix(array $workspace, ?array $pillar = null): string
{
    $pillarValue = trim((string) ($pillar['grav_route_prefix'] ?? ''));
    if ($pillarValue !== '') {
        return '/' . trim($pillarValue, '/');
    }
    // Precedence note: '.' binds tighter than '?:', so a bare
    // "'/' . trim(...) ?: '/blog'" would always return the truthy '/'
    // and never actually fall back — trim the workspace value first,
    // check IT for emptiness, then build the leading-slash path.
    $wsValue = trim((string) ($workspace['grav_route_prefix'] ?? ''), '/');
    return '/' . ($wsValue !== '' ? $wsValue : 'blog');
}

// Same pillar-overrides-workspace pattern as grav_route_prefix() above.
function grav_template(array $workspace, ?array $pillar = null): string
{
    $pillarValue = trim((string) ($pillar['grav_template'] ?? ''));
    if ($pillarValue !== '') {
        return $pillarValue;
    }
    return trim((string) ($workspace['grav_template'] ?? '')) ?: 'item';
}

function grav_auth_headers(array $workspace): array
{
    return [
        'X-API-Key: ' . $workspace['grav_api_key'],
        'Content-Type: application/json',
    ];
}

function grav_post_route(array $workspace, array $blogPost, ?array $pillar = null): string
{
    $prefix = rtrim(grav_route_prefix($workspace, $pillar), '/');
    return $prefix === '' ? '/' . $blogPost['slug'] : $prefix . '/' . $blogPost['slug'];
}

// Lightweight GET to confirm the URL + API key actually work before
// the user relies on them for a scheduled publish.
function grav_test_connection(array $workspace): array
{
    if (!grav_configured($workspace)) {
        return ['success' => false, 'error' => 'Grav site URL and API key are both required.'];
    }
    $ch = curl_init(grav_site_url($workspace) . '/api/v1/pages?per_page=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => grav_auth_headers($workspace),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'error' => "Connection failed: {$err}"];
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        $data = json_decode((string) $response, true);
        $msg = $data['message'] ?? substr((string) $response, 0, 200);
        return ['success' => false, 'error' => "Grav rejected the request (HTTP {$status}): {$msg}"];
    }
    return ['success' => true, 'user' => grav_site_url($workspace)];
}

// POST /api/v1/pages (create) or PUT /api/v1/pages/{route} (update —
// when external_post_id, which stores the page's route, is already
// set). Same return contract as wordpress_publish_post()/
// jekyll_publish_post() so call sites can branch on publish_target
// without caring which platform they're talking to. Unlike Jekyll,
// external_url here is reliable rather than a guess: the route is
// something we choose in the request, not server-assigned, so
// {site_url}/{route} is guaranteed to match once the page exists.
//
// $pillar is $blogPost's own content_pillars row (or null if untagged)
// — only consulted for a brand-new page's route/template (see
// grav_route_prefix()/grav_template()); an update always PUTs to the
// route the page was first created at, regardless of the pillar it's
// tagged with now, same as editing a page in place rather than moving it.
function grav_publish_post(array $workspace, array $blogPost, ?array $pillar = null): array
{
    if (!grav_configured($workspace)) {
        return ['success' => false, 'error' => 'This workspace has no Grav connection configured — add one in Settings.'];
    }

    $isUpdate = !empty($blogPost['external_post_id']);
    $route = $isUpdate ? $blogPost['external_post_id'] : grav_post_route($workspace, $blogPost, $pillar);

    $body = [
        'route'    => $route,
        'title'    => $blogPost['title'],
        'template' => grav_template($workspace, $pillar),
        'header'   => [
            'title' => $blogPost['title'],
            'date'  => date('c'),
        ],
        'content'  => (string) $blogPost['content_html'],
    ];
    if (!empty($blogPost['meta_description']) || !empty($blogPost['keywords'])) {
        $body['header']['metadata'] = array_filter([
            'description' => $blogPost['meta_description'] ?? null,
            'keywords'    => $blogPost['keywords'] ?? null,
        ]);
    }

    $url = grav_site_url($workspace) . '/api/v1/pages' . ($isUpdate ? '/' . ltrim($route, '/') : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $isUpdate ? 'PUT' : 'POST',
        CURLOPT_HTTPHEADER     => grav_auth_headers($workspace),
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'error' => "Grav publish failed: {$err}"];
    }
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string) $response, true);
    $page = $data['data'] ?? null;
    if ($status_code < 200 || $status_code >= 300 || !isset($page['route'])) {
        $msg = $data['message'] ?? substr((string) $response, 0, 300);
        return ['success' => false, 'error' => "Grav publish failed (HTTP {$status_code}): {$msg}"];
    }

    return [
        'success'          => true,
        'external_post_id' => (string) $page['route'],
        'external_url'     => grav_site_url($workspace) . $page['route'],
    ];
}

// Shared HTTP mechanics for grav_set_published()/grav_delete_post()
// below — both act on an already-published page's route rather than
// creating one, so both need the same "no page to act on" guard and
// the same request/response handling grav_publish_post() has inline.
function grav_page_request(array $workspace, string $route, string $method, ?array $body = null): array
{
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => grav_auth_headers($workspace),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $ch = curl_init(grav_site_url($workspace) . '/api/v1/pages/' . ltrim($route, '/'));
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'error' => "Grav request failed: {$err}"];
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        $data = json_decode((string) $response, true);
        $msg = $data['message'] ?? substr((string) $response, 0, 300);
        return ['success' => false, 'error' => "Grav request failed (HTTP {$status}): {$msg}"];
    }
    return ['success' => true];
}

// Soft "mark as deleted" — sets header.published = false via PUT rather
// than removing the page, so it's reversible (grav_set_published(...,
// true) to bring it back) and every other bit of page content/history
// stays intact. This is a dedicated action rather than something
// grav_publish_post() folds into its normal update PUT, specifically so
// a routine content edit never silently flips this flag back on: only
// this function (called from an explicit Unpublish/Republish button,
// see pages/blog_studio.php) ever touches it.
function grav_set_published(array $workspace, array $blogPost, bool $published): array
{
    if (!grav_configured($workspace)) {
        return ['success' => false, 'error' => 'This workspace has no Grav connection configured — add one in Settings.'];
    }
    if (empty($blogPost['external_post_id'])) {
        return ['success' => false, 'error' => 'This post has no Grav page to update yet.'];
    }
    return grav_page_request($workspace, $blogPost['external_post_id'], 'PUT', ['header' => ['published' => $published]]);
}

// A real, permanent delete — unlike grav_set_published(false, ...) the
// page itself is gone from Grav afterward, not just hidden. Callers
// should clear external_post_id/external_url on success (see
// mark_blog_post_deleted_from_platform() in includes/blog_posts.php) so
// a later "Publish Now" creates a fresh page rather than PUTing to a
// route that no longer exists.
function grav_delete_post(array $workspace, array $blogPost): array
{
    if (!grav_configured($workspace)) {
        return ['success' => false, 'error' => 'This workspace has no Grav connection configured — add one in Settings.'];
    }
    if (empty($blogPost['external_post_id'])) {
        return ['success' => false, 'error' => 'This post has no Grav page to delete.'];
    }
    return grav_page_request($workspace, $blogPost['external_post_id'], 'DELETE');
}
