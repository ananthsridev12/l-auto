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

// Grav's API plugin's own error responses look like
// {"status":404,"title":"Not Found","detail":"Page not found at
// route: ..."} — there is no top-level "message" key, so every call
// site here that used to look for $data['message'] always missed and
// fell through to dumping the raw JSON blob at the user instead of a
// clean sentence. This pulls Grav's actual human-readable field
// ("detail", falling back to "title"), with the raw-substring fallback
// kept only for responses that aren't this shape at all (e.g. an HTML
// error page from a misconfigured URL).
function grav_error_message(?array $data, string $rawResponse, int $maxLen = 300): string
{
    if (is_array($data)) {
        if (!empty($data['detail'])) {
            return (string) $data['detail'];
        }
        if (!empty($data['message'])) {
            return (string) $data['message'];
        }
        if (!empty($data['title'])) {
            return (string) $data['title'];
        }
    }
    return substr($rawResponse, 0, $maxLen);
}

// Applies a workspace's own Grav theme's table styling to every
// <table> in $html — a bare, unclassed <table> (what
// includes/blog_generate.php's Comparison-type posts produce) gets no
// styling from most themes and can overflow on mobile. Runs only on
// the copy of content sent to Grav in grav_publish_post() below, never
// on the app's own stored blog_posts.content_html — different
// workspaces can point at different Grav sites with different (or no)
// table conventions, so this must never be baked into the
// theme-agnostic content the app itself edits/displays/exports
// elsewhere (WordPress, Jekyll).
// $wrapTemplate, if set, must contain a literal "{{TABLE}}" placeholder
// — the table markup (with $tableClass already merged in) is
// substituted there. $tableClass is merged into the table's existing
// class="..." attribute if it has one, rather than replacing it.
function grav_apply_table_style(string $html, ?string $wrapTemplate, ?string $tableClass): string
{
    $wrapTemplate = trim((string) $wrapTemplate);
    $tableClass = trim((string) $tableClass);
    if ($wrapTemplate === '' && $tableClass === '') {
        return $html;
    }
    $result = preg_replace_callback('/<table\b([^>]*)>(.*?)<\/table>/is', function (array $m) use ($wrapTemplate, $tableClass) {
        $attrs = $m[1];
        if ($tableClass !== '') {
            if (preg_match('/\bclass\s*=\s*"([^"]*)"/i', $attrs, $cm)) {
                $merged = trim($cm[1] . ' ' . $tableClass);
                $attrs = preg_replace('/\bclass\s*=\s*"[^"]*"/i', 'class="' . htmlspecialchars($merged, ENT_QUOTES) . '"', $attrs, 1);
            } else {
                $attrs .= ' class="' . htmlspecialchars($tableClass, ENT_QUOTES) . '"';
            }
        }
        $table = '<table' . $attrs . '>' . $m[2] . '</table>';
        if ($wrapTemplate !== '' && str_contains($wrapTemplate, '{{TABLE}}')) {
            return str_replace('{{TABLE}}', $table, $wrapTemplate);
        }
        return $table;
    }, $html);
    return $result ?? $html;
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
        $msg = grav_error_message(json_decode((string) $response, true), (string) $response, 200);
        return ['success' => false, 'error' => "Grav rejected the request (HTTP {$status}): {$msg}"];
    }
    return ['success' => true, 'user' => grav_site_url($workspace)];
}

// POST /api/v1/pages (create) or PATCH /api/v1/pages/{route} (update —
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
// grav_route_prefix()/grav_template()); an update normally PATCHes the
// route the page was first created at, regardless of the pillar it's
// tagged with now, same as editing a page in place rather than moving
// it — except when that route 404s (the page was deleted directly on
// Grav, outside this app), in which case this falls back to creating a
// fresh page instead of leaving the post stuck (see below).
function grav_publish_post(array $workspace, array $blogPost, ?array $pillar = null): array
{
    if (!grav_configured($workspace)) {
        return ['success' => false, 'error' => 'This workspace has no Grav connection configured — add one in Settings.'];
    }

    $isUpdate = !empty($blogPost['external_post_id']);
    $route = $isUpdate ? $blogPost['external_post_id'] : grav_post_route($workspace, $blogPost, $pillar);

    // Grav actually renders a page using its frontmatter's own
    // template: value (header.template below) — the top-level
    // `template` field on the create request is accepted by the API
    // plugin's schema but doesn't reliably end up in the saved page's
    // frontmatter, so a page created without header.template set
    // silently falls back to Grav's default template instead of the
    // pillar's ("news-item", "comparison", etc). Both are set here so
    // the page is correct regardless of what the top-level field does
    // internally.
    $template = grav_template($workspace, $pillar);
    $metadata = array_filter([
        'description' => $blogPost['meta_description'] ?? null,
        'keywords'    => $blogPost['keywords'] ?? null,
    ]);
    // Per the site's own taxonomy reference doc: category/service live
    // under header.taxonomy as single-value arrays; industry is a
    // PLAIN header field, deliberately NOT nested under taxonomy (a
    // taxonomy.industry key is inert — no template reads it). Only
    // fields that are actually set get sent, so a post with none of
    // these filled in publishes exactly as it did before this existed.
    $taxonomy = array_filter([
        'category' => !empty($blogPost['grav_category']) ? [$blogPost['grav_category']] : null,
        'service'  => !empty($blogPost['grav_service']) ? [$blogPost['grav_service']] : null,
    ]);
    $industry = trim((string) ($blogPost['grav_industry'] ?? ''));
    $buildBody = function (string $forRoute) use ($blogPost, $workspace, $template, $metadata, $taxonomy, $industry): array {
        $header = [
            'title'    => $blogPost['title'],
            'date'     => date('c'),
            'template' => $template,
        ];
        if ($metadata) {
            $header['metadata'] = $metadata;
        }
        if ($taxonomy) {
            $header['taxonomy'] = $taxonomy;
        }
        if ($industry !== '') {
            $header['industry'] = $industry;
        }
        return [
            'route'    => $forRoute,
            'title'    => $blogPost['title'],
            'template' => $template,
            'header'   => $header,
            'content'  => grav_apply_table_style((string) $blogPost['content_html'], $workspace['grav_table_wrap_html'] ?? null, $workspace['grav_table_class'] ?? null),
        ];
    };

    $doRequest = function (bool $update, string $forRoute) use ($workspace, $buildBody): array {
        $url = grav_site_url($workspace) . '/api/v1/pages' . ($update ? '/' . ltrim($forRoute, '/') : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // Grav's API plugin only allows GET/DELETE/PATCH on an
            // existing page's route (PUT gets a 405) — POST is
            // create-only, at the collection endpoint, not the per-page
            // one.
            CURLOPT_CUSTOMREQUEST  => $update ? 'PATCH' : 'POST',
            CURLOPT_HTTPHEADER     => grav_auth_headers($workspace),
            CURLOPT_POSTFIELDS     => json_encode($buildBody($forRoute)),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'error' => "Grav publish failed: {$err}"];
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'data' => json_decode((string) $response, true)];
    };

    $result = $doRequest($isUpdate, $route);
    // Self-heals a page deleted directly on the Grav site (bypassing
    // this app entirely) — the app's own stored external_post_id still
    // points at that now-gone route, so the PATCH above 404s. Since the
    // whole point of "Publish Now" here is "make this content live",
    // silently falling back to creating a brand-new page (a fresh
    // route, since the old slug may already be freed up for a
    // different page) achieves that goal instead of leaving the post
    // permanently stuck — see also grav_delete_post()'s matching
    // idempotent-404 handling, the other way to reach this same
    // recovered state.
    if ($isUpdate && $result['status'] === 404) {
        $route = grav_post_route($workspace, $blogPost, $pillar);
        $result = $doRequest(false, $route);
    }

    if (isset($result['error'])) {
        return ['success' => false, 'error' => $result['error']];
    }
    $page = $result['data']['data'] ?? null;
    if ($result['status'] < 200 || $result['status'] >= 300 || !isset($page['route'])) {
        $msg = grav_error_message($result['data'], (string) json_encode($result['data']));
        return ['success' => false, 'error' => "Grav publish failed (HTTP {$result['status']}): {$msg}"];
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
// $notFoundIsSuccess: for DELETE only — see grav_delete_post(). A 404
// means Grav has no such page, which for every other method here is a
// genuine failure (nothing to update), but for a delete it means the
// goal ("this page shouldn't exist") is already achieved.
function grav_page_request(array $workspace, string $route, string $method, ?array $body = null, bool $notFoundIsSuccess = false): array
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
    if ($status === 404 && $notFoundIsSuccess) {
        return ['success' => true];
    }
    if ($status < 200 || $status >= 300) {
        $msg = grav_error_message(json_decode((string) $response, true), (string) $response);
        return ['success' => false, 'error' => "Grav request failed (HTTP {$status}): {$msg}", 'status' => $status];
    }
    return ['success' => true];
}

// Soft "mark as deleted" — sets header.published = false via PATCH
// rather than removing the page, so it's reversible (grav_set_published(...,
// true) to bring it back) and every other bit of page content/history
// stays intact. This is a dedicated action rather than something
// grav_publish_post() folds into its normal update PATCH, specifically
// so a routine content edit never silently flips this flag back on:
// only this function (called from an explicit Unpublish/Republish
// button, see pages/blog_studio.php) ever touches it.
//
// Unlike grav_delete_post() below, a 404 here is a genuine error, not
// treated as success — there's no local state change
// (published/unpublished) that makes sense to record for a page that
// doesn't exist; the recovery path is grav_delete_post() (or a fresh
// "Publish Now", which self-heals the same way — see grav_publish_post()).
function grav_set_published(array $workspace, array $blogPost, bool $published): array
{
    if (!grav_configured($workspace)) {
        return ['success' => false, 'error' => 'This workspace has no Grav connection configured — add one in Settings.'];
    }
    if (empty($blogPost['external_post_id'])) {
        return ['success' => false, 'error' => 'This post has no Grav page to update yet.'];
    }
    $result = grav_page_request($workspace, $blogPost['external_post_id'], 'PATCH', ['header' => ['published' => $published]]);
    if (!$result['success'] && ($result['status'] ?? null) === 404) {
        // Points at the actual recovery path (grav_delete_post()'s
        // idempotent 404 handling) instead of leaving the user staring
        // at a raw "page not found" with no next step — this toggle
        // can't recreate a deleted page, only a fresh Publish Now can.
        $result['error'] = 'This page no longer exists on Grav (it was likely deleted directly on the Grav site). Click "Delete Permanently from Grav" below to reset this post to a Draft, then Publish Now to recreate it.';
    }
    return $result;
}

// A real, permanent delete — unlike grav_set_published(false, ...) the
// page itself is gone from Grav afterward, not just hidden. Callers
// should clear external_post_id/external_url on success (see
// mark_blog_post_deleted_from_platform() in includes/blog_posts.php) so
// a later "Publish Now" creates a fresh page rather than PATCHing a
// route that no longer exists.
//
// Idempotent by design (notFoundIsSuccess): if the page was already
// deleted directly on the Grav site (bypassing this app entirely),
// this app's own bookkeeping is still stuck thinking it's published —
// a 404 here means the underlying goal (no such page in Grav) is
// already true, so this still succeeds and lets the caller clear its
// own tracking. That's the only way to get an already-"published"
// post (whose Edit/Publish Now UI stays hidden while it's in that
// status) back to a re-publishable Draft state when Grav's copy is
// gone — see pages/blog_studio.php's "Delete Permanently from Grav".
function grav_delete_post(array $workspace, array $blogPost): array
{
    if (!grav_configured($workspace)) {
        return ['success' => false, 'error' => 'This workspace has no Grav connection configured — add one in Settings.'];
    }
    if (empty($blogPost['external_post_id'])) {
        return ['success' => false, 'error' => 'This post has no Grav page to delete.'];
    }
    return grav_page_request($workspace, $blogPost['external_post_id'], 'DELETE', null, true);
}
