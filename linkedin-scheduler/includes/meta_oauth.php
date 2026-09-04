<?php
// Facebook Pages + Instagram Business OAuth — one Meta Developer App,
// one Facebook Login dialog, covering both platforms. Instagram has no
// separate login of its own here: its Graph API calls are authenticated
// with the parent Facebook Page's own access token (see
// meta_list_pages()'s instagram_business_account lookup), so connecting
// a Page that has a linked Instagram Business account is enough to also
// connect Instagram. Mirrors includes/linkedin_oauth.php's shape.
require_once __DIR__ . '/../config.php';

function meta_http_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($body ?: '', true) ?? []];
}

function meta_build_auth_url(string $state): string
{
    $scope = 'pages_show_list,pages_manage_posts,pages_read_engagement,instagram_basic,instagram_content_publish,business_management';
    $params = [
        'client_id'     => FB_APP_ID,
        'redirect_uri'  => META_REDIRECT_URI,
        'state'         => $state,
        'scope'         => $scope,
        'response_type' => 'code',
    ];
    return META_OAUTH_DIALOG_URL . '?' . http_build_query($params);
}

function meta_exchange_code(string $code): array
{
    $url = META_TOKEN_URL . '?' . http_build_query([
        'client_id'     => FB_APP_ID,
        'client_secret' => FB_APP_SECRET,
        'redirect_uri'  => META_REDIRECT_URI,
        'code'          => $code,
    ]);
    [$status, $data] = meta_http_get($url);
    if ($status !== 200 || empty($data['access_token'])) {
        throw new RuntimeException('Facebook token exchange failed: ' . ($data['error']['message'] ?? 'unknown error'));
    }
    return $data;
}

// Meta's short-lived user tokens (~1-2h) are exchanged for a long-lived
// one (~60 days) via the same endpoint with grant_type=fb_exchange_token.
// Flag for implementation-time verification against a real Meta app —
// this is the documented shape but has not been exercised against the
// live Graph API.
function meta_get_long_lived_token(string $shortToken): array
{
    $url = META_TOKEN_URL . '?' . http_build_query([
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => FB_APP_ID,
        'client_secret'     => FB_APP_SECRET,
        'fb_exchange_token' => $shortToken,
    ]);
    [$status, $data] = meta_http_get($url);
    if ($status !== 200 || empty($data['access_token'])) {
        throw new RuntimeException('Facebook long-lived token exchange failed: ' . ($data['error']['message'] ?? 'unknown error'));
    }
    return $data;
}

// Pages the authenticated user administers, each with its own
// long-lived page access token and (if linked) the Instagram Business
// account id backing it.
function meta_list_pages(string $userToken): array
{
    $url = META_GRAPH_BASE . '/' . META_GRAPH_API_VERSION . '/me/accounts?' . http_build_query([
        'access_token' => $userToken,
        'fields'       => 'id,name,access_token,instagram_business_account{id,username}',
    ]);
    [$status, $data] = meta_http_get($url);
    if ($status !== 200) {
        throw new RuntimeException('Could not list your Facebook Pages: ' . ($data['error']['message'] ?? 'unknown error'));
    }
    return $data['data'] ?? [];
}

function meta_upsert_social_account(int $userId, string $platform, string $externalId, string $displayName, string $accessToken, ?string $expiresAt, string $scopes, ?string $metaJson = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO social_accounts (user_id, platform, external_id, display_name, access_token, expires_at, scopes, meta_json, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "active")
         ON DUPLICATE KEY UPDATE
           display_name = VALUES(display_name), access_token = VALUES(access_token),
           expires_at = VALUES(expires_at), scopes = VALUES(scopes),
           meta_json = VALUES(meta_json), status = "active"'
    );
    $stmt->execute([$userId, $platform, $externalId, $displayName, $accessToken, $expiresAt, $scopes, $metaJson]);
}
