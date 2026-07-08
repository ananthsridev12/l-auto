<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/pdf_builder.php';

function li_json_headers(string $accessToken): array
{
    return [
        'Authorization: Bearer ' . $accessToken,
        'LinkedIn-Version: ' . LI_VERSION,
        'X-Restli-Protocol-Version: 2.0.0',
        'Content-Type: application/json',
    ];
}

function li_upload_image(string $accessToken, string $actingUrn, string $imagePath): string
{
    $ch = curl_init(LI_API_BASE . '/rest/images?action=initializeUpload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => li_json_headers($accessToken),
        CURLOPT_POSTFIELDS     => json_encode(['initializeUploadRequest' => ['owner' => $actingUrn]]),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($body ?: '', true) ?? [];
    if ($status < 200 || $status >= 300 || empty($data['value']['uploadUrl'])) {
        throw new RuntimeException("Image init failed {$status}: {$body}");
    }
    $value = $data['value'];

    $ch = curl_init($value['uploadUrl']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => file_get_contents($imagePath),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/octet-stream'],
    ]);
    curl_exec($ch);
    $putStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($putStatus < 200 || $putStatus >= 300) {
        throw new RuntimeException("Image upload PUT failed with status {$putStatus}");
    }

    return $value['image'];
}

function li_upload_document(string $accessToken, string $actingUrn, string $pdfPath): string
{
    $ch = curl_init(LI_API_BASE . '/rest/documents?action=initializeUpload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => li_json_headers($accessToken),
        CURLOPT_POSTFIELDS     => json_encode(['initializeUploadRequest' => ['owner' => $actingUrn]]),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($body ?: '', true) ?? [];
    if ($status < 200 || $status >= 300 || empty($data['value']['uploadUrl'])) {
        throw new RuntimeException("Document init failed {$status}: {$body}");
    }
    $value = $data['value'];

    $ch = curl_init($value['uploadUrl']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => file_get_contents($pdfPath),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/octet-stream'],
    ]);
    curl_exec($ch);
    $putStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($putStatus < 200 || $putStatus >= 300) {
        throw new RuntimeException("Document upload PUT failed with status {$putStatus}");
    }

    return $value['document'];
}

function li_create_post(string $accessToken, string $actingUrn, string $commentary, ?array $content = null): string
{
    $body = [
        'author'         => $actingUrn,
        'commentary'     => $commentary,
        'visibility'     => 'PUBLIC',
        'distribution'   => ['feedDistribution' => 'MAIN_FEED'],
        'lifecycleState' => 'PUBLISHED',
    ];
    if ($content) {
        $body['content'] = $content;
    }

    $ch = curl_init(LI_API_BASE . '/rest/posts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => li_json_headers($accessToken),
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HEADER         => true,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Post failed {$status}: " . substr($response, $headerSize));
    }

    $headerText = substr($response, 0, $headerSize);
    if (preg_match('/^x-restli-id:\s*(.+)$/mi', $headerText, $m)) {
        return trim($m[1]);
    }
    return 'unknown';
}

// Orchestrates the image/document/text branching used by both the
// "Post Now" endpoint and the scheduled cron sweep, so the two never
// drift out of sync on posting behavior.
//
// $title is what LinkedIn actually displays as the media title — most
// visibly, the caption shown directly under a carousel's swipeable PDF
// in the feed — so it should be the post's human-readable topic/title,
// not the internal campaign ID. Falls back to $campaignId when blank.
function li_publish_post(string $accessToken, string $actingUrn, string $format, string $caption, string $campaignId, array $slidePaths, string $title = ''): string
{
    $mediaTitle = $title !== '' ? $title : $campaignId;

    if (in_array($format, ['Text Post', 'Poll'], true) || empty($slidePaths)) {
        return li_create_post($accessToken, $actingUrn, $caption);
    }

    if ($format === 'Single Image' || count($slidePaths) === 1) {
        $imageUrn = li_upload_image($accessToken, $actingUrn, $slidePaths[0]);
        return li_create_post($accessToken, $actingUrn, $caption, [
            'media' => ['title' => $mediaTitle, 'id' => $imageUrn],
        ]);
    }

    // Carousel: combine slides into a single PDF, upload as a document.
    // The temp filename itself is never shown anywhere (LinkedIn only
    // displays the "title" field set on the post below) but it's still
    // named from the title for clarity in server-side debugging/logs.
    $safeName = preg_replace('/[^A-Za-z0-9 _-]/', '', $mediaTitle);
    $safeName = trim($safeName) !== '' ? trim($safeName) : $campaignId;
    $pdfPath = sys_get_temp_dir() . '/' . $safeName . '_' . bin2hex(random_bytes(4)) . '.pdf';
    build_carousel_pdf($slidePaths, $pdfPath);
    try {
        $docUrn = li_upload_document($accessToken, $actingUrn, $pdfPath);
        sleep(3); // LinkedIn needs a moment to finish processing the uploaded document.
        return li_create_post($accessToken, $actingUrn, $caption, [
            'media' => ['title' => $mediaTitle, 'id' => $docUrn],
        ]);
    } finally {
        @unlink($pdfPath);
    }
}
