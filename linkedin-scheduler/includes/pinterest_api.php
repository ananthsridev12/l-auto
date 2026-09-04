<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/social_common.php';

function pinterest_api_post(string $path, array $body, string $accessToken): array
{
    $override = social_api_override();
    if ($override !== null) {
        return $override;
    }
    $ch = curl_init(PINTEREST_API_BASE . $path);
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

function pinterest_api_error(array $data, string $fallback): string
{
    return $data['message'] ?? $fallback;
}

// Pins are always single-image — Pinterest's API has no true multi-
// image "carousel" post the way Facebook/Instagram do, so a Carousel
// post here just uses its first slide (documented to the user in the
// composer, not silently dropped). $account is a social_accounts row
// (platform='pinterest'; external_id = board id).
function pinterest_publish_pin(array $account, string $format, string $caption, string $title, array $slides): string
{
    if (empty($slides)) {
        throw new RuntimeException('Pinterest requires an image — text-only Pins are not supported.');
    }

    $boardId = $account['external_id'];
    $token   = $account['access_token'];
    $imageUrl = slide_public_url($slides[0]['filepath']);

    $body = [
        'board_id'     => $boardId,
        'media_source' => ['source_type' => 'image_url', 'url' => $imageUrl],
        'title'        => $title !== '' ? $title : mb_substr($caption, 0, 100),
        'description'  => $caption,
    ];

    [$status, $data] = pinterest_api_post('/pins', $body, $token);
    if (!in_array($status, [200, 201], true) || empty($data['id'])) {
        throw new RuntimeException('Pinterest Pin creation failed: ' . pinterest_api_error($data, "HTTP {$status}"));
    }
    return $data['id'];
}
