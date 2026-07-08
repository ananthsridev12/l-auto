<?php
require_once __DIR__ . '/../config.php';

function li_oauth_headers(string $accessToken): array
{
    return [
        'Authorization: Bearer ' . $accessToken,
        'LinkedIn-Version: ' . LI_VERSION,
        'X-Restli-Protocol-Version: 2.0.0',
    ];
}

function li_http_get(string $url, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($body ?: '', true) ?? []];
}

function li_build_auth_url(string $state): string
{
    $params = [
        'response_type' => 'code',
        'client_id'     => LI_CLIENT_ID,
        'redirect_uri'  => LI_REDIRECT_URI,
        'scope'         => LI_SCOPES,
        'state'         => $state,
    ];
    return LI_AUTH_URL . '?' . http_build_query($params);
}

function li_exchange_code(string $code): array
{
    $ch = curl_init(LI_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => LI_REDIRECT_URI,
            'client_id'     => LI_CLIENT_ID,
            'client_secret' => LI_CLIENT_SECRET,
        ]),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($body ?: '', true) ?? [];
    if ($status !== 200 || empty($data['access_token'])) {
        throw new RuntimeException('LinkedIn token exchange failed: ' . ($data['error_description'] ?? $body));
    }
    return $data;
}

function li_get_userinfo(string $accessToken): array
{
    [$status, $data] = li_http_get(LI_API_BASE . '/v2/userinfo', ['Authorization: Bearer ' . $accessToken]);
    if ($status !== 200 || empty($data['sub'])) {
        throw new RuntimeException('LinkedIn userinfo lookup failed.');
    }
    return $data;
}

// Organizations the authenticated member administers, per LinkedIn's
// organizationAcls "roleAssignee" finder. Requires w_organization_social.
function li_list_admin_organizations(string $accessToken): array
{
    $url = LI_API_BASE . '/rest/organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED';
    [$status, $data] = li_http_get($url, li_oauth_headers($accessToken));
    if ($status !== 200) {
        throw new RuntimeException('Could not list LinkedIn Company Pages you administer.');
    }
    $orgs = [];
    foreach ($data['elements'] ?? [] as $el) {
        if (!empty($el['organization'])) {
            $orgs[] = $el['organization']; // e.g. "urn:li:organization:12345"
        }
    }
    return $orgs;
}

function li_get_organization_name(string $accessToken, string $orgUrn): string
{
    $id = substr(strrchr($orgUrn, ':'), 1);
    [$status, $data] = li_http_get(LI_API_BASE . '/rest/organizations/' . $id, li_oauth_headers($accessToken));
    if ($status === 200) {
        return $data['localizedName'] ?? ($data['name']['localized']['en_US'] ?? $orgUrn);
    }
    return $orgUrn;
}

function li_upsert_account(int $userId, string $accountType, string $targetUrn, string $displayName, string $linkedinName, string $accessToken, ?string $expiresAt, string $scopes): void
{
    $stmt = db()->prepare(
        'INSERT INTO linkedin_accounts (user_id, account_type, target_urn, display_name, linkedin_name, access_token, expires_at, scopes, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "active")
         ON DUPLICATE KEY UPDATE
           display_name = VALUES(display_name), linkedin_name = VALUES(linkedin_name),
           access_token = VALUES(access_token), expires_at = VALUES(expires_at),
           scopes = VALUES(scopes), status = "active"'
    );
    $stmt->execute([$userId, $accountType, $targetUrn, $displayName, $linkedinName, $accessToken, $expiresAt, $scopes]);
}
