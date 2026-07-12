<?php
// WordPress REST API client — plain cURL, no library, same pattern as
// includes/linkedin_api.php. Auth is a WordPress Application Password
// (Users > Profile > Application Passwords on the target site), not the
// account password — a scoped, individually revocable credential, the
// same risk profile as linkedin_accounts.access_token. One site per
// workspace (workspaces.wordpress_url/username/app_password).
//
// publish_target on blog_posts stays a plain string, not locked to
// WordPress, so a second platform later is a new publish_to_{target}()
// function alongside this file, not a schema change.

function wordpress_configured(array $workspace): bool
{
    return !empty($workspace['wordpress_url']) && !empty($workspace['wordpress_username']) && !empty($workspace['wordpress_app_password']);
}

function wordpress_auth_header(array $workspace): string
{
    return 'Authorization: Basic ' . base64_encode($workspace['wordpress_username'] . ':' . $workspace['wordpress_app_password']);
}

function wordpress_site_url(array $workspace): string
{
    return rtrim((string) $workspace['wordpress_url'], '/');
}

// GET /wp-json/wp/v2/users/me — confirms the URL + credentials actually
// work before the user relies on them for a scheduled publish.
function wordpress_test_connection(array $workspace): array
{
    if (!wordpress_configured($workspace)) {
        return ['success' => false, 'error' => 'WordPress URL, username, and Application Password are all required.'];
    }
    $ch = curl_init(wordpress_site_url($workspace) . '/wp-json/wp/v2/users/me');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [wordpress_auth_header($workspace)],
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
    $data = json_decode($response, true);
    if ($status !== 200 || !isset($data['id'])) {
        $msg = $data['message'] ?? substr((string) $response, 0, 200);
        return ['success' => false, 'error' => "WordPress rejected the request (HTTP {$status}): {$msg}"];
    }
    return ['success' => true, 'user' => $data['name'] ?? $workspace['wordpress_username']];
}

// POST /wp-json/wp/v2/posts. Always publishes as WordPress status
// 'publish' — scheduling is our own concern (cron/auto_publish_blog.php
// only calls this once scheduled_at is actually due), same model as
// cron/auto_post.php for LinkedIn, so there's no need to also hand a
// future date_gmt to WordPress itself.
function wordpress_publish_post(array $workspace, array $blogPost): array
{
    if (!wordpress_configured($workspace)) {
        return ['success' => false, 'error' => 'This workspace has no WordPress connection configured — add one in Settings.'];
    }
    $body = [
        'title'   => $blogPost['title'],
        'slug'    => $blogPost['slug'],
        'content' => $blogPost['content_html'],
        'status'  => 'publish',
        'excerpt' => $blogPost['meta_description'] ?? '',
    ];
    $isUpdate = !empty($blogPost['external_post_id']);
    $url = wordpress_site_url($workspace) . '/wp-json/wp/v2/posts' . ($isUpdate ? '/' . $blogPost['external_post_id'] : '');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [wordpress_auth_header($workspace), 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'error' => "WordPress publish failed: {$err}"];
    }
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($response, true);
    if ($status_code < 200 || $status_code >= 300 || !isset($data['id'])) {
        $msg = $data['message'] ?? substr((string) $response, 0, 300);
        return ['success' => false, 'error' => "WordPress publish failed (HTTP {$status_code}): {$msg}"];
    }
    return [
        'success'           => true,
        'external_post_id'  => (string) $data['id'],
        'external_url'      => $data['link'] ?? null,
    ];
}
