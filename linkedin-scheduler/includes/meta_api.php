<?php
// Shared low-level Graph API client for Facebook + Instagram publishing
// (includes/facebook_api.php, includes/instagram_api.php) — OAuth/
// account-connection calls live in includes/meta_oauth.php instead.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/social_common.php';

function meta_graph_url(string $path): string
{
    return META_GRAPH_BASE . '/' . META_GRAPH_API_VERSION . '/' . ltrim($path, '/');
}

function meta_graph_get(string $path, array $params, string $accessToken): array
{
    $override = social_api_override();
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
    $override = social_api_override();
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
