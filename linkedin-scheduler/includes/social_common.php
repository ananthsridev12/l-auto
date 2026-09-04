<?php
// SOCIAL_API_OVERRIDE (define in config.php, never committed) — same
// seam as LI_ENGAGEMENT_API_OVERRIDE / PDF_ENGINE_OVERRIDE elsewhere.
// Shared by every new platform's low-level HTTP client (meta_api.php,
// pinterest_api.php, gbp_api.php) so local/test runs can exercise the
// whole publish pipeline without ever calling a real platform API.
// 'fake' returns a synthetic success payload with an 'id' field (every
// real create-post response has one) and 'status_code' => 'FINISHED'
// (for Instagram's container-processing poll); 'fake_fail' returns a
// synthetic error payload.
function social_api_override(): ?array
{
    if (!defined('SOCIAL_API_OVERRIDE')) {
        return null;
    }
    if (SOCIAL_API_OVERRIDE === 'fake_fail') {
        return [400, ['error' => ['message' => 'Simulated API failure (SOCIAL_API_OVERRIDE=fake_fail)'], 'message' => 'Simulated API failure (SOCIAL_API_OVERRIDE=fake_fail)']];
    }
    if (SOCIAL_API_OVERRIDE === 'fake') {
        // 'id' covers Meta/Pinterest response shapes, 'name' covers
        // Google's resource-name-as-identifier convention (used by
        // includes/gbp_api.php) — both point at the same fake value so
        // every platform's own field-name check finds what it expects.
        $fakeId = 'fake_' . bin2hex(random_bytes(6));
        return [200, ['id' => $fakeId, 'name' => $fakeId, 'status_code' => 'FINISHED']];
    }
    return null;
}
