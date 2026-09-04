<?php
// Pinterest API v5 OAuth2 — standard authorization-code flow with HTTP
// Basic client authentication on the token endpoint (unlike LinkedIn/
// Meta, which pass client_id/client_secret as body params). Mirrors
// includes/linkedin_oauth.php's shape.
require_once __DIR__ . '/../config.php';

function pinterest_basic_auth_header(): string
{
    return 'Authorization: Basic ' . base64_encode(PINTEREST_CLIENT_ID . ':' . PINTEREST_CLIENT_SECRET);
}

function pinterest_build_auth_url(string $state): string
{
    $params = [
        'client_id'     => PINTEREST_CLIENT_ID,
        'redirect_uri'  => PINTEREST_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'boards:read,pins:read,pins:write',
        'state'         => $state,
    ];
    return PINTEREST_AUTH_URL . '?' . http_build_query($params);
}

function pinterest_exchange_code(string $code): array
{
    $ch = curl_init(PINTEREST_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [pinterest_basic_auth_header(), 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => PINTEREST_REDIRECT_URI,
        ]),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($body ?: '', true) ?? [];
    if ($status !== 200 || empty($data['access_token'])) {
        throw new RuntimeException('Pinterest token exchange failed: ' . ($data['message'] ?? $body));
    }
    return $data;
}

// The authenticated user's boards — the destination a Pin is posted to,
// same role LinkedIn's organization picker plays for Company Pages.
function pinterest_list_boards(string $accessToken): array
{
    $ch = curl_init(PINTEREST_API_BASE . '/boards');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($body ?: '', true) ?? [];
    if ($status !== 200) {
        throw new RuntimeException('Could not list your Pinterest boards: ' . ($data['message'] ?? "HTTP {$status}"));
    }
    return $data['items'] ?? [];
}

function pinterest_upsert_social_account(int $userId, string $boardId, string $boardName, string $accessToken, ?string $refreshToken, ?string $expiresAt, string $scopes): void
{
    $stmt = db()->prepare(
        "INSERT INTO social_accounts (user_id, platform, external_id, display_name, access_token, refresh_token, expires_at, scopes, status)
         VALUES (?, 'pinterest', ?, ?, ?, ?, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE
           display_name = VALUES(display_name), access_token = VALUES(access_token),
           refresh_token = VALUES(refresh_token), expires_at = VALUES(expires_at),
           scopes = VALUES(scopes), status = 'active'"
    );
    $stmt->execute([$userId, $boardId, $boardName, $accessToken, $refreshToken, $expiresAt, $scopes]);
}
