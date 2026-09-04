<?php
// Shared low-level Graph API client for Facebook + Instagram publishing
// (includes/facebook_api.php, includes/instagram_api.php) — OAuth/
// account-connection calls live in includes/meta_oauth.php instead.
require_once __DIR__ . '/../config.php';

function meta_graph_url(string $path): string
{
    return META_GRAPH_BASE . '/' . META_GRAPH_API_VERSION . '/' . ltrim($path, '/');
}

// SOCIAL_API_OVERRIDE (define in config.php, never committed) — same
// seam as LI_ENGAGEMENT_API_OVERRIDE / PDF_ENGINE_OVERRIDE elsewhere.
// Centralized here (rather than in each publish function) since every
// Facebook/Instagram publish call ultimately goes through one of these
// two functions. 'fake' returns a synthetic success payload with an
// 'id' field (every real Graph API write response has one); 'fake_fail'
// returns a synthetic error payload in the same shape meta_graph_error()
// reads from a real failure.
function meta_graph_override(): ?array
{
    if (!defined('SOCIAL_API_OVERRIDE')) {
        return null;
    }
    if (SOCIAL_API_OVERRIDE === 'fake_fail') {
        return [400, ['error' => ['message' => 'Simulated Graph API failure (SOCIAL_API_OVERRIDE=fake_fail)']]];
    }
    if (SOCIAL_API_OVERRIDE === 'fake') {
        return [200, ['id' => 'fake_' . bin2hex(random_bytes(6)), 'status_code' => 'FINISHED']];
    }
    return null;
}

function meta_graph_get(string $path, array $params, string $accessToken): array
{
    $override = meta_graph_override();
    if ($override !== null) {
        return $override;
    }
    $params['access_token'] = $accessToken;
    $ch = curl_init(meta_graph_url($path) . '?' . http_build_query($params));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($body ?: '', true) ?? []];
}

function meta_graph_post(string $path, array $fields, string $accessToken): array
{
    $override = meta_graph_override();
    if ($override !== null) {
        return $override;
    }
    $fields['access_token'] = $accessToken;
    $ch = curl_init(meta_graph_url($path));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($body ?: '', true) ?? []];
}

function meta_graph_error(array $data, string $fallback): string
{
    return $data['error']['message'] ?? $fallback;
}
