<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/social_common.php';
require_once __DIR__ . '/gbp_oauth.php';

function gbp_api_post(string $url, array $body, string $accessToken): array
{
    $override = social_api_override();
    if ($override !== null) {
        return $override;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body),
    ]);
    $respBody = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($respBody ?: '', true) ?? []];
}

function gbp_api_error(array $data, string $fallback): string
{
    return $data['error']['message'] ?? $fallback;
}

// Google Business Profile "local posts" support one image plus text.
// Always uses just the first slide (a "What's New" update post has no
// real multi-image carousel concept). $account is a social_accounts
// row (platform='google_business'; external_id =
// "accounts/{a}/locations/{l}"). See includes/gbp_oauth.php for the
// note on this endpoint needing re-verification against Google's
// current API surface.
function gbp_publish_local_post(array $account, string $caption, array $slides): string
{
    if (empty($slides)) {
        throw new RuntimeException('Google Business Profile posts require an image.');
    }

    $parent = $account['external_id'];
    $token  = $account['access_token'];
    $imageUrl = slide_public_url($slides[0]['filepath']);

    $body = [
        'languageCode' => 'en-US',
        'summary'      => $caption,
        'media'        => [['mediaFormat' => 'PHOTO', 'sourceUrl' => $imageUrl]],
    ];

    [$status, $data] = gbp_api_post(GBP_LOCAL_POSTS_BASE . '/' . $parent . '/localPosts', $body, $token);
    if (!in_array($status, [200, 201], true) || empty($data['name'])) {
        throw new RuntimeException('Google Business Profile post failed: ' . gbp_api_error($data, "HTTP {$status}"));
    }
    return $data['name'];
}
