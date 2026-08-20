<?php
// Stock photo search (Unsplash) — an alternative to the branded
// template renderer / manual upload for New Post's Single Image
// format, for posts that call for a real photo rather than a
// generated slide. Bring-your-own-key (Settings > Integrations),
// same pattern as includes/news_fetch.php's Reddit integration.
//
// Unsplash API terms require: (a) attributing the photographer, which
// unsplash_search()'s results carry for the UI to display, and (b)
// pinging the photo's download_location endpoint at the moment it's
// actually used in a document — unsplash_track_download() does that,
// called from api/stock_image_search.php's "use this photo" step, not
// at search time.

function unsplash_configured(?string $accessKey): bool
{
    return $accessKey !== null && trim($accessKey) !== '';
}

function unsplash_http_get(string $url, string $accessKey): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Client-ID ' . $accessKey, 'Accept-Version: v1'],
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LinkedInScheduler/1.0)',
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$status, $body, $err];
}

// Returns up to 12 results: ['id','thumb_url','full_url','alt','photographer','photographer_url','download_location'].
// photographer/photographer_url must stay visible next to any photo
// picked from this list (Unsplash's attribution requirement).
function unsplash_search(string $query, string $accessKey, int $page = 1): array
{
    $url = 'https://api.unsplash.com/search/photos?query=' . urlencode($query)
        . '&page=' . max(1, $page) . '&per_page=12&orientation=squarish';
    [$status, $body, $err] = unsplash_http_get($url, $accessKey);
    if ($body === false) {
        throw new RuntimeException("Unsplash search failed: {$err}");
    }
    if ($status !== 200) {
        throw new RuntimeException("Unsplash search failed (HTTP {$status}) — check the Access Key in Settings.");
    }
    $data = json_decode($body, true);
    $results = [];
    foreach ($data['results'] ?? [] as $photo) {
        $results[] = [
            'id'                => $photo['id'] ?? '',
            'thumb_url'         => $photo['urls']['small'] ?? ($photo['urls']['thumb'] ?? ''),
            'full_url'          => $photo['urls']['regular'] ?? ($photo['urls']['full'] ?? ''),
            'alt'               => $photo['alt_description'] ?? $query,
            'photographer'      => $photo['user']['name'] ?? 'Unsplash',
            'photographer_url'  => ($photo['user']['links']['html'] ?? null) ? $photo['user']['links']['html'] . '?utm_source=easi7_postpilot&utm_medium=referral' : null,
            'download_location' => $photo['links']['download_location'] ?? '',
        ];
    }
    return $results;
}

// Per Unsplash's API guidelines, this must be called once when a photo
// is actually used (not on every search result render) — a fire-and-
// forget GET, failures here shouldn't block the post from saving.
function unsplash_track_download(string $downloadLocation, string $accessKey): void
{
    if ($downloadLocation === '') {
        return;
    }
    try {
        unsplash_http_get($downloadLocation, $accessKey);
    } catch (Throwable $e) {
        // best-effort only
    }
}

// Downloads the actual image bytes for a chosen result's full_url.
// Returns ['bytes' => string, 'mime' => string]. Throws if the fetch
// fails or the response isn't a real image (mirrors the same
// zip_sniff_image_mime() validation manual uploads already go through).
function unsplash_fetch_image(string $fullUrl): array
{
    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LinkedInScheduler/1.0)',
    ]);
    $bytes = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($bytes === false) {
        throw new RuntimeException("Image download failed: {$err}");
    }
    if ($status !== 200) {
        throw new RuntimeException("Image download failed: HTTP {$status}");
    }
    $mime = zip_sniff_image_mime($bytes);
    if (!in_array($mime, ALLOWED_SLIDE_MIME, true)) {
        throw new RuntimeException('Downloaded file was not a valid PNG/JPEG image.');
    }
    return ['bytes' => $bytes, 'mime' => $mime];
}

// Resolves the Stock/AI Photo panel's two possible submissions (an
// Unsplash full_url, or a data: URL from AI generation) into raw bytes
// — shared by every consumer of that panel (New Post's raw-photo
// attach, Post edit's swap, and New Post's branded-background picker).
// Returns null if neither field was actually submitted (panel wasn't
// used this request). Throws on a malformed/invalid submission.
function resolve_stock_or_ai_submission(string $stockImageUrl, string $stockAiDataUrl): ?array
{
    if ($stockAiDataUrl !== '') {
        if (!preg_match('#^data:image/(?:png|jpeg);base64,(.+)$#', $stockAiDataUrl, $m)) {
            throw new RuntimeException('Invalid generated image data.');
        }
        $bytes = base64_decode($m[1]);
        $mime = zip_sniff_image_mime((string) $bytes);
        if (!in_array($mime, ALLOWED_SLIDE_MIME, true)) {
            throw new RuntimeException('That image could not be used — not a valid PNG/JPEG.');
        }
        return ['bytes' => $bytes, 'mime' => $mime];
    }
    if ($stockImageUrl !== '') {
        return unsplash_fetch_image($stockImageUrl);
    }
    return null;
}

// Downloads/decodes a Stock/AI Photo submission and saves it to
// $destDir/bg_source.{ext}, returning the saved path (null if the
// panel wasn't used this request). Tracks the Unsplash download ping
// when applicable. Shared by the real save path
// (pages/new_post.php's branded-background handling) and the image
// preview endpoint (api/new_post_preview_image.php), so "Generate
// Image Preview" actually reflects what gets saved instead of quietly
// falling back to the palette's own background photo.
function save_stock_or_ai_background(int $userId, string $destDir, string $stockImageUrl, string $stockAiDataUrl, string $downloadLocation = ''): ?string
{
    $submission = resolve_stock_or_ai_submission($stockImageUrl, $stockAiDataUrl);
    if ($submission === null) {
        return null;
    }
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $ext = $submission['mime'] === 'image/png' ? 'png' : 'jpg';
    $path = $destDir . '/bg_source.' . $ext;
    file_put_contents($path, $submission['bytes']);
    if ($downloadLocation !== '') {
        $unsplashKey = get_unsplash_access_key($userId);
        if ($unsplashKey) {
            unsplash_track_download($downloadLocation, $unsplashKey);
        }
    }
    return $path;
}
