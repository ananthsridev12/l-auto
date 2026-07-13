<?php
// GitHub Contents API client for publishing to a Jekyll site — plain
// cURL, no library, same pattern as includes/wordpress_api.php. Jekyll
// itself has no live API: a "post" is a markdown file in _posts/ that a
// separate build step turns into static HTML. Auth is a GitHub Personal
// Access Token scoped to "Contents: Read and write" on one repo, same
// risk profile as wordpress_app_password (plain storage, revocable
// independently of the account password). One repo per workspace
// (workspaces.jekyll_repo/branch/token/posts_path/site_url).
//
// Publishing here only commits the file to the repo — it does not
// deploy the live site. The user's Jekyll host rebuilds through cPanel
// Git Version Control, which they trigger manually ("Update from
// Remote" + "Deploy HEAD Commit"), same as their main app. This client
// removes the "write the file and commit it by hand" step, not that
// final deploy click.

// Overridable only by tests (define() it before requiring this file to
// point at a local fixture server) — production never sets this, so it
// always falls through to the real GitHub API.
if (!defined('JEKYLL_API_BASE')) {
    define('JEKYLL_API_BASE', 'https://api.github.com');
}

function jekyll_configured(array $workspace): bool
{
    return !empty($workspace['jekyll_repo']) && !empty($workspace['jekyll_token']);
}

function jekyll_branch(array $workspace): string
{
    return trim((string) ($workspace['jekyll_branch'] ?? '')) ?: 'main';
}

function jekyll_posts_path(array $workspace): string
{
    return trim((string) ($workspace['jekyll_posts_path'] ?? ''), '/') ?: '_posts';
}

function jekyll_auth_headers(array $workspace): array
{
    return [
        'Authorization: Bearer ' . $workspace['jekyll_token'],
        'Accept: application/vnd.github+json',
        'User-Agent: l-auto-linkedin-scheduler',
    ];
}

// Escapes a string for use inside a double-quoted YAML scalar in Jekyll
// front matter (titles/descriptions can contain colons or quotes that
// would otherwise break the front matter block).
function jekyll_yaml_escape(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

// GET /repos/{repo} — confirms the repo + token actually work before
// the user relies on them for a scheduled publish.
function jekyll_test_connection(array $workspace): array
{
    if (!jekyll_configured($workspace)) {
        return ['success' => false, 'error' => 'GitHub repo and Personal Access Token are both required.'];
    }
    $ch = curl_init(JEKYLL_API_BASE . '/repos/' . $workspace['jekyll_repo']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => jekyll_auth_headers($workspace),
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
    if ($status !== 200 || !isset($data['full_name'])) {
        $msg = $data['message'] ?? substr((string) $response, 0, 200);
        return ['success' => false, 'error' => "GitHub rejected the request (HTTP {$status}): {$msg}"];
    }
    return ['success' => true, 'user' => $data['full_name']];
}

// Builds the Jekyll _posts markdown file (front matter + content_html
// as-is — kramdown, Jekyll's default processor, passes raw HTML blocks
// through untouched, so no HTML->Markdown conversion is needed here).
function jekyll_build_post_file(array $blogPost): string
{
    $lines = ['---'];
    $lines[] = 'layout: post';
    $lines[] = 'title: ' . jekyll_yaml_escape((string) $blogPost['title']);
    $dateSource = $blogPost['published_at'] ?? $blogPost['scheduled_at'] ?? null;
    $timestamp = $dateSource ? strtotime($dateSource) : time();
    $lines[] = 'date: ' . date('Y-m-d H:i:s O', $timestamp ?: time());
    if (!empty($blogPost['meta_description'])) {
        $lines[] = 'description: ' . jekyll_yaml_escape((string) $blogPost['meta_description']);
    }
    if (!empty($blogPost['keywords'])) {
        $lines[] = 'keywords: ' . jekyll_yaml_escape((string) $blogPost['keywords']);
    }
    $lines[] = '---';
    $lines[] = '';
    $lines[] = (string) $blogPost['content_html'];
    return implode("\n", $lines) . "\n";
}

function jekyll_post_file_path(array $workspace, array $blogPost): string
{
    $dateSource = $blogPost['published_at'] ?? $blogPost['scheduled_at'] ?? null;
    $timestamp = $dateSource ? strtotime($dateSource) : time();
    $date = date('Y-m-d', $timestamp ?: time());
    return jekyll_posts_path($workspace) . '/' . $date . '-' . $blogPost['slug'] . '.md';
}

// rawurlencode() on the whole path would turn "/" into "%2F", breaking
// GitHub's nested-path routing (a repo path is a sequence of segments,
// not one opaque string) — encode each segment on its own instead.
function jekyll_encode_path(string $path): string
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

// GET /repos/{repo}/contents/{path} — fetches the current blob SHA for
// an already-published post, required by the PUT below to update it
// (the SHA changes on every commit, so it can't just be reused from an
// earlier publish).
function jekyll_fetch_file_sha(array $workspace, string $path): ?string
{
    $url = JEKYLL_API_BASE . '/repos/' . $workspace['jekyll_repo'] . '/contents/' . jekyll_encode_path($path)
        . '?ref=' . rawurlencode(jekyll_branch($workspace));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => jekyll_auth_headers($workspace),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $status !== 200) {
        return null;
    }
    $data = json_decode($response, true);
    return $data['sha'] ?? null;
}

// PUT /repos/{repo}/contents/{path} — creates or updates the post's
// markdown file as a commit on the configured branch. Same return
// contract as wordpress_publish_post() so call sites can branch on
// publish_target without caring which platform they're talking to.
function jekyll_publish_post(array $workspace, array $blogPost): array
{
    if (!jekyll_configured($workspace)) {
        return ['success' => false, 'error' => 'This workspace has no Jekyll (GitHub) connection configured — add one in Settings.'];
    }

    $path = jekyll_post_file_path($workspace, $blogPost);
    $isUpdate = !empty($blogPost['external_post_id']);
    $sha = null;
    if ($isUpdate) {
        $sha = jekyll_fetch_file_sha($workspace, $path);
        if ($sha === null) {
            return ['success' => false, 'error' => "Couldn't find the existing file on GitHub to update it (it may have been moved or deleted there) — check the repo directly."];
        }
    }

    $body = [
        'message' => ($isUpdate ? 'Update' : 'Add') . ' blog post: ' . $blogPost['title'],
        'content' => base64_encode(jekyll_build_post_file($blogPost)),
        'branch'  => jekyll_branch($workspace),
    ];
    if ($sha !== null) {
        $body['sha'] = $sha;
    }

    $ch = curl_init(JEKYLL_API_BASE . '/repos/' . $workspace['jekyll_repo'] . '/contents/' . jekyll_encode_path($path));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_HTTPHEADER     => array_merge(jekyll_auth_headers($workspace), ['Content-Type: application/json']),
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'error' => "GitHub commit failed: {$err}"];
    }
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($response, true);
    if ($status_code < 200 || $status_code >= 300 || !isset($data['content']['sha'])) {
        $msg = $data['message'] ?? substr((string) $response, 0, 300);
        return ['success' => false, 'error' => "GitHub commit failed (HTTP {$status_code}): {$msg}"];
    }

    $siteUrl = trim((string) ($workspace['jekyll_site_url'] ?? ''));
    $externalUrl = $siteUrl !== ''
        ? rtrim($siteUrl, '/') . '/' . $blogPost['slug'] . '/'
        : ($data['content']['html_url'] ?? null);

    return [
        'success'          => true,
        'external_post_id' => (string) $data['content']['sha'],
        'external_url'     => $externalUrl,
    ];
}
