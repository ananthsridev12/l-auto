<?php
// Server-to-server endpoint for the "family app" integration — see
// includes/family_wishes.php for the design/scope notes. Not session-
// authenticated (there's no logged-in PostPilot user on the other end
// of this call); a shared API key stands in for require_login()/
// csrf_check() here.
//
// Request:  POST, JSON body, header "X-Api-Key: <FAMILY_APP_API_KEY>"
//   { "external_ref": "...", "occasion": "birthday"|"anniversary",
//     "name": "...", "relation": "..." (optional),
//     "message": "..." (optional), "photo_url": "..." (optional, not
//     yet used — accepted now so the caller doesn't need a follow-up
//     contract change once photo support ships) }
// Response: { "success": true, "external_ref": "...", "image_url": "..." }

// includes/auth.php (not just db.php/config.php) is loaded even though
// this endpoint never calls require_login() — it just defines app_path()
// (needed by slide_public_url()) and starts the session; it does not by
// itself impose any session/login requirement.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/image_renderer.php';
require_once __DIR__ . '/../includes/family_wishes.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Invalid request'], 400);
}

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
$input = read_json_body();
if (!family_wish_api_key_valid($apiKey) && !family_wish_api_key_valid($input['api_key'] ?? null)) {
    json_response(['success' => false, 'error' => 'Invalid or missing API key'], 401);
}

$externalRef = trim((string) ($input['external_ref'] ?? ''));
$occasion = (string) ($input['occasion'] ?? '');
$name = trim((string) ($input['name'] ?? ''));
$relation = trim((string) ($input['relation'] ?? '')) ?: null;
$message = trim((string) ($input['message'] ?? '')) ?: null;

if ($externalRef === '' || strlen($externalRef) > 191) {
    json_response(['success' => false, 'error' => 'external_ref is required (max 191 chars).'], 422);
}
if (!in_array($occasion, FAMILY_WISH_OCCASIONS, true)) {
    json_response(['success' => false, 'error' => 'occasion must be one of: ' . implode(', ', FAMILY_WISH_OCCASIONS)], 422);
}
if ($name === '' || strlen($name) > 255) {
    json_response(['success' => false, 'error' => 'name is required (max 255 chars).'], 422);
}
if ($relation !== null && strlen($relation) > 100) {
    json_response(['success' => false, 'error' => 'relation must be at most 100 chars.'], 422);
}
if ($message !== null && strlen($message) > 2000) {
    json_response(['success' => false, 'error' => 'message must be at most 2000 chars.'], 422);
}

// Idempotent: a retried request with the same external_ref returns the
// image already generated for it instead of rendering a duplicate.
$existing = fetch_family_wish_by_ref($externalRef);
if ($existing) {
    json_response(['success' => true, 'external_ref' => $externalRef, 'image_url' => slide_public_url($existing['image_path'])]);
}

$creative = build_family_wish_creative($occasion, $name, $relation, $message);
// render_creative_to_slides() always names its output "slide_01.png"
// within whatever directory it's given (same convention posts use —
// UPLOAD_DIR/{userId}/{campaignId}/) — each wish needs its own
// directory or same-month requests would overwrite each other's image.
$safeRef = preg_replace('/[^A-Za-z0-9_-]/', '_', $externalRef);
$destDir = UPLOAD_DIR . '/family_wishes/' . date('Y/m') . '/' . $safeRef;

try {
    $slides = render_creative_to_slides($creative, $destDir, '', null, 0, null);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => 'Image rendering failed: ' . $e->getMessage()], 500);
}
if (!$slides) {
    json_response(['success' => false, 'error' => 'Image rendering produced no output.'], 500);
}

$imagePath = $slides[0]['filepath'];
record_family_wish($externalRef, $occasion, $name, $relation, $message, $imagePath);

json_response(['success' => true, 'external_ref' => $externalRef, 'image_url' => slide_public_url($imagePath)]);
