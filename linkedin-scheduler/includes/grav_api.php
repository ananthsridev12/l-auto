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

function grav_route_prefix(array $workspace): string
{
    return '/' . trim((string) ($workspace['grav_route_prefix'] ?? ''), '/') ?: '/blog';
}

function grav_template(array $workspace): string
{
    return trim((string) ($workspace['grav_template'] ?? '')) ?: 'item';
}

function grav_auth_headers(array $workspace): array
{
    return [
        'X-API-Key: ' . $workspace['grav_api_key'],
        'Content-Type: application/json',
    ];
}

function grav_post_route(array $workspace, array $blogPost): string
{
    $prefix = rtrim(grav_route_prefix($workspace), '/');
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
function grav_publish_post(array $workspace, array $blogPost): array
{
    if (!grav_configured($workspace)) {
        return ['success' => false, 'error' => 'This workspace has no Grav connection configured — add one in Settings.'];
    }

    $isUpdate = !empty($blogPost['external_post_id']);
    $route = $isUpdate ? $blogPost['external_post_id'] : grav_post_route($workspace, $blogPost);

    $body = [
        'route'    => $route,
        'template' => grav_template($workspace),
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
