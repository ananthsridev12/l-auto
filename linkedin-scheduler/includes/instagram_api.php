<?php
require_once __DIR__ . '/meta_api.php';

// Instagram's Content Publishing API is a two-step "container then
// publish" flow, and always needs the image at a public URL (no direct
// file upload like LinkedIn/Facebook) — slide_public_url() already
// serves rendered slides that way. Carousel uses Instagram's real
// carousel mechanism: one child container per image, then a parent
// CAROUSEL container referencing them, then publish the parent.
// $account is a social_accounts row (platform='instagram'; external_id
// = IG Business Account id, access_token = the linked Page's token).

function ig_wait_until_finished(string $creationId, string $token, int $maxAttempts = 10): void
{
    for ($i = 0; $i < $maxAttempts; $i++) {
        [$status, $data] = meta_graph_get($creationId, ['fields' => 'status_code'], $token);
        if ($status === 200 && ($data['status_code'] ?? '') === 'FINISHED') {
            return;
        }
        if ($status === 200 && ($data['status_code'] ?? '') === 'ERROR') {
            throw new RuntimeException('Instagram media processing failed.');
        }
        sleep(2);
    }
    throw new RuntimeException('Instagram media took too long to process.');
}

function ig_publish_post(array $account, string $format, string $caption, array $slides): string
{
    if (empty($slides)) {
        throw new RuntimeException('Instagram requires at least one image — text-only posts are not supported.');
    }

    $igUserId = $account['external_id'];
    $token    = $account['access_token'];

    if ($format === 'Single Image' || count($slides) === 1) {
        $url = slide_public_url($slides[0]['filepath']);
        [$status, $data] = meta_graph_post("{$igUserId}/media", ['image_url' => $url, 'caption' => $caption], $token);
        if ($status !== 200 || empty($data['id'])) {
            throw new RuntimeException('Instagram media creation failed: ' . meta_graph_error($data, "HTTP {$status}"));
        }
        $creationId = $data['id'];
        ig_wait_until_finished($creationId, $token);

        [$status, $data] = meta_graph_post("{$igUserId}/media_publish", ['creation_id' => $creationId], $token);
        if ($status !== 200 || empty($data['id'])) {
            throw new RuntimeException('Instagram publish failed: ' . meta_graph_error($data, "HTTP {$status}"));
        }
        return $data['id'];
    }

    // Carousel — one child container per slide, no caption on children.
    $childIds = [];
    foreach ($slides as $slide) {
        $url = slide_public_url($slide['filepath']);
        [$status, $data] = meta_graph_post("{$igUserId}/media", [
            'image_url'        => $url,
            'is_carousel_item' => 'true',
        ], $token);
        if ($status !== 200 || empty($data['id'])) {
            throw new RuntimeException('Instagram carousel slide creation failed: ' . meta_graph_error($data, "HTTP {$status}"));
        }
        $childIds[] = $data['id'];
    }

    [$status, $data] = meta_graph_post("{$igUserId}/media", [
        'media_type' => 'CAROUSEL',
        'children'   => implode(',', $childIds),
        'caption'    => $caption,
    ], $token);
    if ($status !== 200 || empty($data['id'])) {
        throw new RuntimeException('Instagram carousel creation failed: ' . meta_graph_error($data, "HTTP {$status}"));
    }
    $creationId = $data['id'];
    ig_wait_until_finished($creationId, $token);

    [$status, $data] = meta_graph_post("{$igUserId}/media_publish", ['creation_id' => $creationId], $token);
    if ($status !== 200 || empty($data['id'])) {
        throw new RuntimeException('Instagram publish failed: ' . meta_graph_error($data, "HTTP {$status}"));
    }
    return $data['id'];
}
