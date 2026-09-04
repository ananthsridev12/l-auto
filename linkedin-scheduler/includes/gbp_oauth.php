<?php
// Google Business Profile — standard Google OAuth2 (a Google Cloud
// Console project, not a "Business Profile" app of its own). Mirrors
// includes/linkedin_oauth.php's shape.
//
// Account/location discovery (gbp_list_locations() below) is the
// least certain part of this whole build: Google has restructured its
// Business Profile API surface several times (the old unified
// "My Business API" v4 was split into the Account Management,
// Business Information, and other narrower APIs), and the exact
// current endpoint names should be re-verified against Google's live
// docs once the account owner has a real GCP project + an approved
// Business Profile API request — this can't be confirmed from static
// code alone. What's below is the best-documented shape at
// implementation time.
require_once __DIR__ . '/../config.php';

define('GBP_ACCOUNT_MGMT_BASE', 'https://mybusinessaccountmanagement.googleapis.com/v1');
define('GBP_BUSINESS_INFO_BASE', 'https://mybusinessbusinessinformation.googleapis.com/v1');
// Local Posts lived under the older unified "My Business API" (v4) —
// flagged above for re-verification.
define('GBP_LOCAL_POSTS_BASE', 'https://mybusiness.googleapis.com/v4');

function gbp_http_get(string $url, string $accessToken): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($body ?: '', true) ?? []];
}

function gbp_build_auth_url(string $state): string
{
    $params = [
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'https://www.googleapis.com/auth/business.manage',
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => $state,
    ];
    return GOOGLE_AUTH_URL . '?' . http_build_query($params);
}

function gbp_exchange_code(string $code): array
{
    $ch = curl_init(GOOGLE_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
        ]),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($body ?: '', true) ?? [];
    if ($status !== 200 || empty($data['access_token'])) {
        throw new RuntimeException('Google token exchange failed: ' . ($data['error_description'] ?? $body));
    }
    return $data;
}

// Every location across every account the authenticated user manages,
// flattened into one list with the account name attached — a Business
// Profile can have many locations under one account, so this is the
// picker's candidate list (same role as LinkedIn's organization picker
// or Pinterest's board picker).
function gbp_list_locations(string $accessToken): array
{
    [$status, $data] = gbp_http_get(GBP_ACCOUNT_MGMT_BASE . '/accounts', $accessToken);
    if ($status !== 200) {
        throw new RuntimeException('Could not list your Google Business accounts: ' . ($data['error']['message'] ?? "HTTP {$status}"));
    }

    $locations = [];
    foreach ($data['accounts'] ?? [] as $account) {
        $accountName = $account['name'] ?? '';
        if ($accountName === '') {
            continue;
        }
        $url = GBP_BUSINESS_INFO_BASE . '/' . $accountName . '/locations?' . http_build_query(['readMask' => 'name,title']);
        [$locStatus, $locData] = gbp_http_get($url, $accessToken);
        if ($locStatus !== 200) {
            continue;
        }
        foreach ($locData['locations'] ?? [] as $location) {
            $locationName = $location['name'] ?? '';
            if ($locationName === '') {
                continue;
            }
            // Combined into the "accounts/{a}/locations/{l}" resource
            // path the older Local Posts API (v4) expects as its parent
            // — the Business Information API returns location names
            // un-prefixed ("locations/67890"), so this is built rather
            // than used as returned. See the re-verification note atop
            // this file if Local Posts moves to a newer API surface.
            $locations[] = [
                'id'    => $accountName . '/' . $locationName,
                'title' => $location['title'] ?? ($account['accountName'] ?? 'Location'),
            ];
        }
    }
    return $locations;
}

function gbp_upsert_social_account(int $userId, string $locationId, string $title, string $accessToken, ?string $refreshToken, ?string $expiresAt, string $scopes): void
{
    $stmt = db()->prepare(
        "INSERT INTO social_accounts (user_id, platform, external_id, display_name, access_token, refresh_token, expires_at, scopes, status)
         VALUES (?, 'google_business', ?, ?, ?, ?, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE
           display_name = VALUES(display_name), access_token = VALUES(access_token),
           refresh_token = VALUES(refresh_token), expires_at = VALUES(expires_at),
           scopes = VALUES(scopes), status = 'active'"
    );
    $stmt->execute([$userId, $locationId, $title, $accessToken, $refreshToken, $expiresAt, $scopes]);
}
