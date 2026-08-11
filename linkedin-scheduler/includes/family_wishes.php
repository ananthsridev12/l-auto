<?php
// Backend for the "family app" integration (see api/family_wish.php):
// that app owns birthdays/anniversaries for its own users and calls in
// here whenever one is coming up, gets back a generated card image URL,
// and does its own sending (WhatsApp/email) — PostPilot never talks to
// the recipient directly. Deliberately has no PostPilot user/workspace
// of its own; it's a stateless image-generation service from this app's
// point of view, not a tenant — render_creative_to_slides() is called
// with $userId=0, which it already treats as "no brand context, use
// bundled defaults" (see its own doc comment).

const FAMILY_WISH_OCCASIONS = ['birthday', 'anniversary'];

// Constant-time-ish comparison via hash_equals — same reasoning as
// csrf_check() in includes/auth.php. FAMILY_APP_API_KEY must be set in
// config.php (see config.sample.php) — an empty/undefined key always
// fails closed rather than accepting every request.
function family_wish_api_key_valid(?string $key): bool
{
    $expected = defined('FAMILY_APP_API_KEY') ? FAMILY_APP_API_KEY : '';
    return $expected !== '' && is_string($key) && hash_equals($expected, $key);
}

function fetch_family_wish_by_ref(string $externalRef): ?array
{
    $stmt = db()->prepare('SELECT * FROM family_wish_requests WHERE external_ref = ?');
    $stmt->execute([$externalRef]);
    return $stmt->fetch() ?: null;
}

function record_family_wish(string $externalRef, string $occasion, string $name, ?string $relation, ?string $message, string $imagePath): void
{
    db()->prepare(
        'INSERT INTO family_wish_requests (external_ref, occasion, recipient_name, relation, message, image_path)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$externalRef, $occasion, $name, $relation, $message, $imagePath]);
}

// Builds the creative JSON render_creative_to_slides() (includes/
// image_renderer.php) expects — a single square card, no footer/CTA
// styling (this isn't a LinkedIn post), just a headline + occasion line
// + optional personal message on a festive palette. 'serif_spotlight'
// (see render_design_templates()) gives the celebratory bleed-circle
// look without needing a brand-new renderer template.
function build_family_wish_creative(string $occasion, string $name, ?string $relation, ?string $message): array
{
    $headline = $occasion === 'anniversary' ? "Happy Anniversary, {$name}!" : "Happy Birthday, {$name}!";
    $subheading = $relation ? ucfirst($relation) : '';
    $body = trim((string) $message) !== ''
        ? trim($message)
        : ($occasion === 'anniversary' ? 'Wishing you many more years of happiness together.' : 'Wishing you a wonderful day and a fantastic year ahead.');

    return [
        'format'   => 'single',
        'size'     => 'square',
        'template' => 'serif_spotlight',
        'layout'   => 'classic',
        'slides'   => [[
            'slide_number' => 1,
            'headline'     => $headline,
            'subheading'   => $subheading,
            'body'         => $body,
            'points'       => [],
        ]],
    ];
}
