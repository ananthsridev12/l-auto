<?php
require_once __DIR__ . '/meta_api.php';

// Publishes to a Facebook Page. $account is a social_accounts row
// (platform='facebook'; external_id = Page id, access_token = that
// Page's own long-lived token). $slides is post_slides rows in order
// (each needs 'filepath' for slide_public_url()). Mirrors
// li_publish_post()'s format branching in includes/linkedin_api.php.
//
// Carousel maps onto Facebook's real multi-photo-post mechanism: each
// photo is uploaded unpublished to get a media_fbid, then referenced
// via attached_media on one /feed post — this is Facebook's own
// supported way to post several photos as one post, not a workaround.
function fb_publish_post(array $account, string $format, string $caption, array $slides): string
{
    $pageId = $account['external_id'];
    $token  = $account['access_token'];

    if (empty($slides) || $format === 'Text Post' || $format === 'Poll') {
        [$status, $data] = meta_graph_post("{$pageId}/feed", ['message' => $caption], $token);
        if ($status !== 200 || empty($data['id'])) {
            throw new RuntimeException('Facebook post failed: ' . meta_graph_error($data, "HTTP {$status}"));
        }
        return $data['id'];
    }

    if ($format === 'Single Image' || count($slides) === 1) {
        $url = slide_public_url($slides[0]['filepath']);
        [$status, $data] = meta_graph_post("{$pageId}/photos", ['url' => $url, 'caption' => $caption], $token);
        if ($status !== 200 || empty($data['id'])) {
            throw new RuntimeException('Facebook photo post failed: ' . meta_graph_error($data, "HTTP {$status}"));
        }
        return $data['post_id'] ?? $data['id'];
    }

    // Carousel — upload each slide unpublished, then attach them all to
    // one feed post.
    $mediaFbids = [];
    foreach ($slides as $slide) {
        $url = slide_public_url($slide['filepath']);
        [$status, $data] = meta_graph_post("{$pageId}/photos", [
            'url'       => $url,
            'published' => 'false',
        ], $token);
        if ($status !== 200 || empty($data['id'])) {
            throw new RuntimeException('Facebook slide upload failed: ' . meta_graph_error($data, "HTTP {$status}"));
        }
        $mediaFbids[] = $data['id'];
    }

    $fields = ['message' => $caption];
    foreach ($mediaFbids as $i => $fbid) {
        $fields["attached_media[{$i}]"] = json_encode(['media_fbid' => $fbid]);
    }
    [$status, $data] = meta_graph_post("{$pageId}/feed", $fields, $token);
    if ($status !== 200 || empty($data['id'])) {
        throw new RuntimeException('Facebook carousel post failed: ' . meta_graph_error($data, "HTTP {$status}"));
    }
    return $data['id'];
}
