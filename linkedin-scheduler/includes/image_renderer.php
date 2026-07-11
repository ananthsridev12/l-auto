<?php
// GD port of the local Python prototype's render.py. Renders the JSON
// produced by includes/creative_builder.php or includes/ai_generate.php
// into 1080x1080 slide PNGs, one per campaign, matching the Python
// version's layout as closely as GD allows (see notes below on the two
// primitives GD has no direct equivalent for: rounded rectangles and a
// circular-cropped photo).
//
// GD's imagettftext() Y coordinate is the text BASELINE, not top-left
// like PIL's draw.text() — gd_text() below converts once via a per-
// font/size ascent measurement so every call site can keep thinking in
// top-left coordinates, same as the Python original.

if (!function_exists('imagettftext')) {
    throw new RuntimeException('The GD extension (with FreeType/TTF support) is required to render images and is not available on this PHP install.');
}

const RENDER_SIZE = 1080;
const RENDER_PAD  = 80;
const RENDER_BAR  = 8;

function render_content_edges(): array
{
    $cx = RENDER_BAR + RENDER_PAD;      // 88 — left content edge
    $rx = RENDER_SIZE - RENDER_PAD;     // 1000 — right content edge
    return [$cx, $rx, $rx - $cx];       // [CX, RX, CW]
}

// ── Palettes ──────────────────────────────────────────────────────────

function render_palettes(): array
{
    return [
        1 => ['bg' => [251, 245, 221], 'headline' => [13, 83, 14], 'body' => [48, 109, 41], 'accent' => [231, 225, 177], 'accent_text' => [13, 83, 14], 'badge_bg' => [13, 83, 14], 'badge_text' => [251, 245, 221], 'bar' => [13, 83, 14], 'counter' => [48, 109, 41], 'rule' => [48, 109, 41], 'divider' => [231, 225, 177], 'cta_bg' => [13, 83, 14], 'cta_text' => [251, 245, 221], 'name' => [48, 109, 41]],
        2 => ['bg' => [13, 83, 14], 'headline' => [251, 245, 221], 'body' => [231, 225, 177], 'accent' => [48, 109, 41], 'accent_text' => [231, 225, 177], 'badge_bg' => [231, 225, 177], 'badge_text' => [13, 83, 14], 'bar' => [231, 225, 177], 'counter' => [231, 225, 177], 'rule' => [231, 225, 177], 'divider' => [48, 109, 41], 'cta_bg' => [231, 225, 177], 'cta_text' => [13, 83, 14], 'name' => [231, 225, 177]],
        3 => ['bg' => [231, 225, 177], 'headline' => [13, 83, 14], 'body' => [48, 109, 41], 'accent' => [251, 245, 221], 'accent_text' => [13, 83, 14], 'badge_bg' => [13, 83, 14], 'badge_text' => [231, 225, 177], 'bar' => [13, 83, 14], 'counter' => [48, 109, 41], 'rule' => [13, 83, 14], 'divider' => [48, 109, 41], 'cta_bg' => [13, 83, 14], 'cta_text' => [231, 225, 177], 'name' => [48, 109, 41]],
        4 => ['bg' => [48, 109, 41], 'headline' => [251, 245, 221], 'body' => [231, 225, 177], 'accent' => [251, 245, 221], 'accent_text' => [13, 83, 14], 'badge_bg' => [13, 83, 14], 'badge_text' => [251, 245, 221], 'bar' => [231, 225, 177], 'counter' => [231, 225, 177], 'rule' => [231, 225, 177], 'divider' => [231, 225, 177], 'cta_bg' => [251, 245, 221], 'cta_text' => [13, 83, 14], 'name' => [231, 225, 177]],
    ];
}

// Auto-detection fallback when there's no explicit template and no
// custom default palette — unchanged from the original SolidPro-specific
// keyword matching, kept as the zero-config behavior for anyone who
// hasn't set up custom brand colors yet.
function render_get_palette_id_by_series_label(?string $seriesLabel): int
{
    $sl = strtolower($seriesLabel ?? '');
    foreach (['case study', 'proof engine', 'recap', 'product ip', 'results', 'metrics'] as $k) {
        if (str_contains($sl, $k)) {
            return 4;
        }
    }
    foreach (['framework', 'checklist'] as $k) {
        if (str_contains($sl, $k)) {
            return 3;
        }
    }
    foreach (['problem hook', 'thought leadership', 'hook post', 'lead magnet', 'opinion'] as $k) {
        if (str_contains($sl, $k)) {
            return 2;
        }
    }
    return 1;
}

// ── Color math (for custom brand palettes) ──────────────────────────────

function hex_to_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

// WCAG 2.x relative luminance / contrast ratio — used to always pick a
// readable text color against an arbitrary user-picked background,
// rather than trusting a fixed role mapping to generalize to any hue.
function relative_luminance(array $rgb): float
{
    $channel = function (int $c) {
        $c = $c / 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    [$r, $g, $b] = $rgb;
    return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
}

function contrast_ratio(array $rgb1, array $rgb2): float
{
    $l1 = relative_luminance($rgb1) + 0.05;
    $l2 = relative_luminance($rgb2) + 0.05;
    return $l1 > $l2 ? $l1 / $l2 : $l2 / $l1;
}

// Returns whichever of $candidates contrasts best against $against.
function best_contrast(array $candidates, array $against): array
{
    $best = $candidates[0];
    $bestRatio = contrast_ratio($candidates[0], $against);
    foreach (array_slice($candidates, 1) as $c) {
        $ratio = contrast_ratio($c, $against);
        if ($ratio > $bestRatio) {
            $best = $c;
            $bestRatio = $ratio;
        }
    }
    return $best;
}

function mix_colors(array $c1, array $c2, float $pct): array
{
    return [
        (int) round($c1[0] + ($c2[0] - $c1[0]) * $pct),
        (int) round($c1[1] + ($c2[1] - $c1[1]) * $pct),
        (int) round($c1[2] + ($c2[2] - $c1[2]) * $pct),
    ];
}

// Derives the same 14-role palette shape render_palettes() hardcodes,
// from just 2 required + 2 optional user-picked hex colors (see
// pages/settings.php Brand Palettes section). accent_text/cta_text/
// badge_text are always computed via contrast ratio against whatever
// they sit on, so no combination of user-picked colors can produce
// unreadable text.
function render_derive_palette_colors(string $bgHex, string $textHex, ?string $accentHex = null, ?string $ctaHex = null): array
{
    $bg     = hex_to_rgb($bgHex);
    $text   = hex_to_rgb($textHex);
    $accent = $accentHex ? hex_to_rgb($accentHex) : mix_colors($bg, $text, 0.08);
    $cta    = $ctaHex ? hex_to_rgb($ctaHex) : $text;
    $body   = mix_colors($text, $bg, 0.35);

    return [
        'bg'          => $bg,
        'headline'    => $text,
        'body'        => $body,
        'accent'      => $accent,
        'accent_text' => best_contrast([$bg, $text], $accent),
        'badge_bg'    => $text,
        'badge_text'  => best_contrast([$bg, $text], $text),
        'bar'         => $text,
        'counter'     => $body,
        'rule'        => $body,
        'divider'     => $accent,
        'cta_bg'      => $cta,
        'cta_text'    => best_contrast([$bg, $text], $cta),
        'name'        => $body,
    ];
}

// Resolves which 14-role RGB color map a slide should use: an explicit
// template on the creative JSON (int 1-4 = built-in preset, "custom:{id}"
// = a saved includes/post_helpers.php fetch_brand_palette() row), else
// the user's default custom palette if they have one, else the original
// series-label keyword matching against the 4 built-ins (unchanged
// zero-config behavior for anyone without custom brand colors).
function render_resolve_palette_colors($templateValue, int $userId, ?string $seriesLabel): array
{
    if (is_int($templateValue) && $templateValue >= 1 && $templateValue <= 4) {
        return render_palettes()[$templateValue];
    }
    if (is_string($templateValue) && str_starts_with($templateValue, 'custom:')) {
        $palette = fetch_brand_palette($userId, (int) substr($templateValue, 7));
        if ($palette) {
            return render_derive_palette_colors($palette['bg_color'], $palette['text_color'], $palette['accent_color'], $palette['cta_color']);
        }
    }

    $defaultPalette = fetch_default_brand_palette($userId);
    if ($defaultPalette) {
        return render_derive_palette_colors($defaultPalette['bg_color'], $defaultPalette['text_color'], $defaultPalette['accent_color'], $defaultPalette['cta_color']);
    }

    return render_palettes()[render_get_palette_id_by_series_label($seriesLabel)];
}

// Allocates a resolved 14-role RGB map against a specific GD image
// resource — color indices are per-image in GD, unlike PIL's plain RGB
// tuples.
function render_allocate_palette_colors($im, array $roleColors): array
{
    $out = [];
    foreach ($roleColors as $key => [$r, $g, $b]) {
        $out[$key] = imagecolorallocate($im, $r, $g, $b);
    }
    return $out;
}

// ── Fonts ─────────────────────────────────────────────────────────────

// Magic-byte check for a font upload (see pages/settings.php Brand Fonts
// section) — mirrors includes/zip_import.php zip_sniff_image_mime()'s
// approach. Returns the file extension to save it under, or null if the
// contents don't look like a real TTF/OTF file.
function sniff_font_ext(string $contents): ?string
{
    $sig = substr($contents, 0, 4);
    if ($sig === "\x00\x01\x00\x00" || $sig === 'true' || $sig === 'ttcf') {
        return 'ttf';
    }
    if ($sig === 'OTTO') {
        return 'otf';
    }
    return null;
}

// Site-wide fonts already sitting in assets/fonts/ (e.g. dropped in via
// cPanel file manager) — lets Settings offer "add to my fonts" instead
// of requiring a re-upload through the browser. Only recognizes a plain
// "Name-Regular.ttf" / "Name-Bold.ttf" (or "_" separator) naming
// convention; a family missing either weight, or named some other way
// (like Google Fonts' per-optical-size static export), won't show up
// here — it can still be renamed to match, or uploaded normally.
function scan_site_fonts(): array
{
    $dir = __DIR__ . '/../assets/fonts';
    if (!is_dir($dir)) {
        return [];
    }
    $families = [];
    foreach (scandir($dir) ?: [] as $file) {
        if (!preg_match('/^(.+?)[-_](Regular|Bold)\.(ttf|otf)$/i', $file, $m)) {
            continue;
        }
        $familyKey = strtolower($m[1]);
        $families[$familyKey]['name'] = $m[1];
        $families[$familyKey][strtolower($m[2])] = $dir . '/' . $file;
    }
    $result = [];
    foreach ($families as $data) {
        if (isset($data['regular'], $data['bold'])) {
            $result[] = ['name' => $data['name'], 'regular' => $data['regular'], 'bold' => $data['bold']];
        }
    }
    usort($result, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
    return $result;
}

// Set once per render_creative_to_slides() call (see below) from the
// user's includes/post_helpers.php fetch_heading_font()/fetch_body_font()
// role assignments, if set — render_font_path() checks the override for
// the role a given piece of text belongs to before falling back to the
// bundled Inter/DejaVu chain. Only actual headline text uses role
// 'heading'; everything else (body, numbered points, CTA banner,
// counter, footer, eyebrow) uses 'body' — see each render_slide_*()
// call site. A single render call only ever uses one pair of fonts per
// role throughout, so this doesn't need per-call-site font-path plumbing.
function render_font_override_role(string $role, ?array $paths = null, bool $set = false): ?array
{
    static $overrides = ['heading' => null, 'body' => null];
    if ($set) {
        $overrides[$role] = $paths;
    }
    return $overrides[$role] ?? null;
}

function render_font_path(bool $bold, string $role = 'body'): string
{
    $override = render_font_override_role($role);
    if ($override) {
        $path = $override[$bold ? 'bold' : 'regular'] ?? null;
        if ($path && is_file($path)) {
            return $path;
        }
    }

    $fontsDir = __DIR__ . '/../assets/fonts';
    // Accepts either a plain rename (Inter-Bold.ttf) or Google Fonts'
    // "static" export as-is, which ships one file per optical size
    // (Inter_18pt-Bold.ttf / Inter_24pt-Bold.ttf / Inter_28pt-Bold.ttf) —
    // 28pt is tried first since most of this renderer's text is large
    // display headlines (54-78px) that optical size was designed for.
    $names = $bold
        ? ['Inter-Bold.ttf', 'Inter_28pt-Bold.ttf', 'Inter_24pt-Bold.ttf', 'Inter_18pt-Bold.ttf']
        : ['Inter-Regular.ttf', 'Inter_28pt-Regular.ttf', 'Inter_24pt-Regular.ttf', 'Inter_18pt-Regular.ttf'];
    foreach ($names as $name) {
        $path = $fontsDir . '/' . $name;
        if (is_file($path)) {
            return $path;
        }
    }
    $fallback = $bold ? '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf' : '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    if (is_file($fallback)) {
        return $fallback;
    }
    throw new RuntimeException('No usable TTF font found — upload an Inter Bold/Regular .ttf file to assets/fonts/.');
}

function render_text_width(string $text, int $size, bool $bold, string $role = 'body'): float
{
    if ($text === '') {
        return 0.0;
    }
    $bbox = imagettfbbox($size, 0, render_font_path($bold, $role), $text);
    return abs($bbox[2] - $bbox[0]);
}

function render_ascent(int $size, bool $bold, string $role = 'body'): float
{
    static $cache = [];
    $key = $role . ($bold ? 'b' : 'r') . $size;
    if (!isset($cache[$key])) {
        $bbox = imagettfbbox($size, 0, render_font_path($bold, $role), 'Ágy');
        $cache[$key] = -$bbox[7];
    }
    return $cache[$key];
}

// Draws text with $topY as the TOP of the text (like PIL's draw.text),
// converting to GD's baseline-relative imagettftext() internally.
function render_text($im, float $x, float $topY, string $text, int $size, bool $bold, int $color, string $role = 'body'): void
{
    if ($text === '') {
        return;
    }
    imagettftext($im, $size, 0, (int) round($x), (int) round($topY + render_ascent($size, $bold, $role)), $color, render_font_path($bold, $role), $text);
}

// ── Text utilities ───────────────────────────────────────────────────

function render_wrap(string $text, int $size, bool $bold, float $maxPx, string $role = 'body'): array
{
    $words = preg_split('/\s+/', trim((string) $text));
    $words = array_values(array_filter($words, fn ($w) => $w !== ''));
    if (!$words) {
        return [''];
    }
    $lines = [];
    $current = [];
    foreach ($words as $word) {
        $test = implode(' ', array_merge($current, [$word]));
        if ($current && render_text_width($test, $size, $bold, $role) > $maxPx) {
            $lines[] = implode(' ', $current);
            $current = [$word];
        } else {
            $current[] = $word;
        }
    }
    if ($current) {
        $lines[] = implode(' ', $current);
    }
    return $lines ?: [''];
}

function render_lh(int $fontSize): int
{
    return max((int) ($fontSize * 1.5), $fontSize + 12);
}

// ── Shape primitives ─────────────────────────────────────────────────

// GD has no rounded-rectangle fill primitive (unlike PIL's
// rounded_rectangle) — built from a filled cross plus 4 corner circles,
// the standard GD technique for this.
function render_rrect($im, float $x1, float $y1, float $x2, float $y2, int $color, int $radius = 8): void
{
    $x1 = (int) round($x1); $y1 = (int) round($y1);
    $x2 = (int) round($x2); $y2 = (int) round($y2);
    $radius = (int) min($radius, ($x2 - $x1) / 2, ($y2 - $y1) / 2);
    if ($radius <= 0) {
        imagefilledrectangle($im, $x1, $y1, $x2, $y2, $color);
        return;
    }
    imagefilledrectangle($im, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($im, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagefilledellipse($im, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($im, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($im, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($im, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

// Center-crops $photoPath to a square then masks it into a circle —
// GD's equivalent of PIL's ImageOps.fit() + an ellipse alpha mask.
// Returns null (caller falls back to text-only footer) if the photo is
// missing or unreadable, same as render.py's PHOTO_PATH.exists() check.
function render_circular_photo(string $photoPath, int $sz)
{
    if (!is_file($photoPath)) {
        return null;
    }
    $info = @getimagesize($photoPath);
    if (!$info) {
        return null;
    }
    $src = match ($info['mime']) {
        'image/png'  => @imagecreatefrompng($photoPath),
        'image/jpeg' => @imagecreatefromjpeg($photoPath),
        default      => null,
    };
    if (!$src) {
        return null;
    }

    $sw = imagesx($src);
    $sh = imagesy($src);
    $side = min($sw, $sh);
    $srcX = intdiv($sw - $side, 2);
    $srcY = intdiv($sh - $side, 2);

    $square = imagecreatetruecolor($sz, $sz);
    imagecopyresampled($square, $src, 0, 0, $srcX, $srcY, $sz, $sz, $side, $side);
    imagedestroy($src);

    $circle = imagecreatetruecolor($sz, $sz);
    imagealphablending($circle, false);
    imagesavealpha($circle, true);
    $transparent = imagecolorallocatealpha($circle, 0, 0, 0, 127);
    imagefill($circle, 0, 0, $transparent);

    $r = $sz / 2;
    for ($y = 0; $y < $sz; $y++) {
        for ($x = 0; $x < $sz; $x++) {
            $dx = $x - $r + 0.5;
            $dy = $y - $r + 0.5;
            if ($dx * $dx + $dy * $dy <= $r * $r) {
                imagesetpixel($circle, $x, $y, imagecolorat($square, $x, $y));
            }
        }
    }
    imagedestroy($square);
    return $circle;
}

// Top-left brand mark, drawn on every slide of every layout — unlike
// render_circular_photo() this preserves aspect ratio (scaled to a fixed
// max height) rather than cropping to a circle, since a rectangular
// wordmark logo would be mangled by a circle crop, and preserves alpha so
// a transparent-background PNG composites cleanly over any palette/
// background. Returns the Y position content should resume at — $y
// unchanged if no logo (zero layout shift for users who haven't uploaded
// one), or $y advanced past the logo + a gap if one was drawn.
function render_draw_logo($im, ?string $logoPath, float $cx, float $y): float
{
    if ($logoPath === null || !is_file($logoPath)) {
        return $y;
    }
    $info = @getimagesize($logoPath);
    if (!$info) {
        return $y;
    }
    $src = match ($info['mime']) {
        'image/png'  => @imagecreatefrompng($logoPath),
        'image/jpeg' => @imagecreatefromjpeg($logoPath),
        default      => null,
    };
    if (!$src) {
        return $y;
    }

    $sw = imagesx($src);
    $sh = imagesy($src);
    $dh = 36;
    $dw = (int) round($sw * ($dh / $sh));

    imagealphablending($im, true);
    imagesavealpha($src, true);
    imagecopyresampled($im, $src, (int) $cx, (int) $y, 0, 0, $dw, $dh, $sw, $sh);
    imagedestroy($src);

    return $y + $dh + 20;
}

// Fills the full canvas — flat single color (today's behavior) or a
// subtle top-to-bottom brand-tinted gradient. Must run on the raw RGB
// $paletteColors map, before render_allocate_palette_colors() turns it
// into per-image GD color indices, since it allocates its own row colors
// directly. Uses mix_colors() (below) rather than a full color shift so
// contrast ratios tuned against a flat bg stay safe with 'gradient' too.
function render_draw_background($im, array $paletteColors, string $bgStyle): void
{
    $top = $paletteColors['bg'];
    if ($bgStyle !== 'gradient') {
        [$r, $g, $b] = $top;
        imagefilledrectangle($im, 0, 0, RENDER_SIZE, RENDER_SIZE, imagecolorallocate($im, $r, $g, $b));
        return;
    }
    // Mixing toward accent (as opposed to headline/text) was too subtle
    // to read as a gradient at all on palettes where accent is a close,
    // low-contrast tint of bg (e.g. Cream) — headline is guaranteed high
    // contrast against bg by design (it's the primary text color), so
    // mixing toward it at 32% reliably reads as an intentional gradient
    // on every palette while staying well short of headline's own
    // darkness/lightness, so text drawn over it keeps its contrast margin.
    $bottom = mix_colors($top, $paletteColors['headline'], 0.32);
    for ($y = 0; $y < RENDER_SIZE; $y++) {
        [$r, $g, $b] = mix_colors($top, $bottom, $y / (RENDER_SIZE - 1));
        imagefilledrectangle($im, 0, $y, RENDER_SIZE, $y, imagecolorallocate($im, $r, $g, $b));
    }
}

// ── Layout primitives (direct ports of render.py) ───────────────────

// $layout is one of 'classic' (default), 'minimal', 'bold' — see
// pages/*.php "Design Template" pickers and render_creative_to_slides().
// 'minimal' drops the bar entirely; 'bold' widens it for more visual
// weight; content positioning (render_content_edges()) stays fixed
// across all three so this never has to move where text starts.
function render_draw_bar($im, array $p, string $layout = 'classic'): void
{
    if ($layout === 'minimal') {
        return;
    }
    $width = $layout === 'bold' ? 14 : RENDER_BAR;
    imagefilledrectangle($im, 0, 0, $width, RENDER_SIZE, $p['bar']);
}

function render_draw_counter($im, int $n, int $total, array $p): void
{
    [, $rx] = render_content_edges();
    $text = sprintf('%02d / %02d', $n, $total);
    $w = render_text_width($text, 24, false);
    render_text($im, $rx - $w, RENDER_PAD - 4, $text, 24, false, $p['counter']);
}

function render_draw_rule($im, float $y, array $p): float
{
    [$cx] = render_content_edges();
    $gapAbove = 14; $thick = 4; $gapBelow = 22;
    imagefilledrectangle($im, (int) $cx, (int) ($y + $gapAbove), (int) ($cx + 100), (int) ($y + $gapAbove + $thick), $p['rule']);
    return $y + $gapAbove + $thick + $gapBelow;
}

// The divider between headline and body — 'classic' is render_draw_rule()
// unchanged, 'minimal' is just whitespace (no line), 'bold' is a thicker
// solid block for more visual weight.
function render_headline_rule($im, float $y, array $p, string $layout = 'classic'): float
{
    if ($layout === 'minimal') {
        return $y + 14 + 22;
    }
    if ($layout === 'bold') {
        [$cx] = render_content_edges();
        $gapAbove = 14; $thick = 8; $gapBelow = 22;
        imagefilledrectangle($im, (int) $cx, (int) ($y + $gapAbove), (int) ($cx + 100), (int) ($y + $gapAbove + $thick), $p['rule']);
        return $y + $gapAbove + $thick + $gapBelow;
    }
    return render_draw_rule($im, $y, $p);
}

// Pure measurement, no drawing — mirrors render_numbered_card()'s height
// math exactly so render_fit_font_size() can pick a size before drawing.
// 'bold' reuses this unchanged (its watermark is purely decorative, adds
// no real height); 'minimal' has its own, smaller formula below.
function render_numbered_card_height(string $text, int $fontSize, string $layout = 'classic'): float
{
    if ($layout === 'minimal') {
        return render_minimal_point_height($text, $fontSize);
    }
    [, , $cw] = render_content_edges();
    $badge = 44; $px = 20; $py = 15;
    $lines = render_wrap_clamped($text, $fontSize, false, $cw - $badge - $px * 2 - 18, 2);
    $ch = max($badge + $py * 2, count($lines) * render_lh($fontSize) + $py * 2);
    return $ch + 14; // + the gap render_numbered_card() adds below each card
}

// Minimal layout's point style has no badge, so its floor is text height
// alone rather than classic/bold's 44px-badge-driven minimum.
function render_minimal_point_height(string $text, int $fontSize): float
{
    [, , $cw] = render_content_edges();
    $barW = 4; $gap = 16;
    $numW = render_text_width('00  ', $fontSize, true);
    $lines = render_wrap_clamped($text, $fontSize, false, $cw - $barW - $gap - $numW, 2);
    return count($lines) * render_lh($fontSize) + 6 + 20; // top pad + gap below
}

// Pure measurement, no drawing — mirrors render_cta_banner()'s height math.
function render_cta_banner_height(string $text, int $fontSize, string $layout = 'classic'): float
{
    [, , $cw] = render_content_edges();
    $aw = render_text_width('→  ', $fontSize, true);
    if ($layout === 'minimal') {
        $lines = render_wrap_clamped($text, $fontSize, true, $cw - $aw, 2);
        return count($lines) * render_lh($fontSize) + 16; // no box, just the gap below
    }
    $pad = $layout === 'bold' ? 28 : 20;
    $lines = render_wrap_clamped($text, $fontSize, true, $cw - $aw - $pad * 2, 2);
    return count($lines) * render_lh($fontSize) + $pad * 2 + 16; // + the gap below
}

// Picks the largest candidate font size (assumed descending) whose
// projected total height for every item stays at/below $ceiling, so the
// footer never has to clamp onto still-drawing content. Falls back to the
// smallest candidate (best effort) if nothing fits.
function render_fit_font_size(array $items, float $startY, float $ceiling, array $candidateSizes, callable $itemHeightFn): int
{
    foreach ($candidateSizes as $size) {
        $y = $startY;
        foreach ($items as $item) {
            $y += $itemHeightFn($item, $size);
        }
        if ($y <= $ceiling) {
            return $size;
        }
    }
    return end($candidateSizes);
}

// A word-count limit on the headline/body (see includes/ai_generate.php
// prompt rules) doesn't bound how many lines it wraps to at a fixed font
// size — a handful of long words can still wrap to several lines and eat
// the vertical space everything below (points, footer) needs. Picks the
// largest candidate size (assumed descending) that keeps the text at or
// under $maxLines, falling back to the smallest candidate otherwise.
function render_fit_headline_size(string $text, float $maxPx, array $candidateSizes, int $maxLines, bool $bold = true, string $role = 'heading'): int
{
    foreach ($candidateSizes as $size) {
        if (count(render_wrap($text, $size, $bold, $maxPx, $role)) <= $maxLines) {
            return $size;
        }
    }
    return end($candidateSizes);
}

// render_fit_headline_size()'s smallest-candidate fallback is "best
// effort" — a wide production font (real Inter, not this box's DejaVu
// fallback) or a caption the AI over-length can still wrap past
// $maxLines at every candidate size. Every drawing loop that iterates a
// render_wrap() result and feeds the same text into a height-measurement
// twin (card height, banner height, ...) must call this instead so the
// two never disagree about how tall the text actually is — that
// agreement is what keeps the footer clamp further down honest.
function render_wrap_clamped(string $text, int $size, bool $bold, float $maxPx, int $maxLines, string $role = 'body'): array
{
    $lines = render_wrap($text, $size, $bold, $maxPx, $role);
    if (count($lines) <= $maxLines) {
        return $lines;
    }
    $lines = array_slice($lines, 0, $maxLines);
    $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1]) . '…';
    return $lines;
}

// Big, faint number watermark in a card's right side — 'bold' layout's
// signature decorative touch. Drawn before the badge/text so it sits
// behind them. By this point in the pipeline $p holds already-allocated
// GD color indices (not RGB triplets — see render_allocate_palette_colors()),
// so the tint is made on the fly from badge_bg's own RGB rather than via
// a new palette role; using badge_bg (not accent, the card's own fill)
// keeps enough contrast against the card background to actually read.
function render_point_watermark($im, int $num, float $cx, float $y, float $rx, float $ch, array $p): void
{
    $rgb = imagecolorsforindex($im, $p['badge_bg']);
    $tint = imagecolorallocatealpha($im, $rgb['red'], $rgb['green'], $rgb['blue'], 100);
    $size = (int) min(90, $ch * 0.9);
    $lbl = sprintf('%02d', $num);
    $w = render_text_width($lbl, $size, true);
    render_text($im, $rx - $w - 16, $y + ($ch - $size) / 2 - $size * 0.15, $lbl, $size, true, $tint);
}

// Minimal layout's point style: a slim accent-colored bar instead of a
// filled card, the number as plain bold text instead of a pill badge —
// see render_minimal_point_height() for the matching measurement.
function render_minimal_point($im, int $num, string $text, float $y, array $p, int $fontSize): float
{
    [$cx, , $cw] = render_content_edges();
    $barW = 4; $gap = 16; $py = 6;
    $numLbl = sprintf('%02d', $num) . '  ';
    $numW = render_text_width($numLbl, $fontSize, true);
    $lines = render_wrap_clamped($text, $fontSize, false, $cw - $barW - $gap - $numW, 2);
    $lineH = render_lh($fontSize);
    $textH = count($lines) * $lineH;

    imagefilledrectangle($im, (int) $cx, (int) ($y + $py), (int) ($cx + $barW), (int) ($y + $py + $textH), $p['accent']);

    // body, not accent, for the number itself — accent is only ever a
    // guaranteed-safe fill/tint color, not guaranteed readable as text
    // directly on bg (see the same fix in render_cta_banner()'s minimal
    // branch). The bar above can stay accent since it's a decorative
    // marker, not something anyone has to read.
    $tx = $cx + $barW + $gap;
    render_text($im, $tx, $y + $py, $numLbl, $fontSize, true, $p['body']);
    foreach ($lines as $i => $line) {
        render_text($im, $tx + $numW, $y + $py + $i * $lineH, $line, $fontSize, false, $p['body']);
    }

    return $y + $py + $textH + 20;
}

function render_numbered_card($im, int $num, string $text, float $y, array $p, int $fontSize = 26, string $layout = 'classic'): float
{
    if ($layout === 'minimal') {
        return render_minimal_point($im, $num, $text, $y, $p, $fontSize);
    }
    [$cx, $rx, $cw] = render_content_edges();
    $fs = $fontSize; $badge = 44; $px = 20; $py = 15;
    $lines = render_wrap_clamped($text, $fs, false, $cw - $badge - $px * 2 - 18, 2);
    $lineH = render_lh($fs);
    $textH = count($lines) * $lineH;
    $ch = max($badge + $py * 2, $textH + $py * 2);

    render_rrect($im, $cx, $y, $rx, $y + $ch, $p['accent'], 8);

    if ($layout === 'bold') {
        render_point_watermark($im, $num, $cx, $y, $rx, $ch, $p);
    }

    $bx = $cx + $px;
    $by = $y + ($ch - $badge) / 2;
    render_rrect($im, $bx, $by, $bx + $badge, $by + $badge, $p['badge_bg'], 6);
    $lbl = sprintf('%02d', $num);
    $lw = render_text_width($lbl, 19, true);
    render_text($im, $bx + ($badge - $lw) / 2, $by + ($badge - 19) / 2, $lbl, 19, true, $p['badge_text']);

    $tx = $bx + $badge + 16;
    $ty = $y + ($ch - $textH) / 2;
    foreach ($lines as $i => $line) {
        render_text($im, $tx, $ty + $i * $lineH, $line, $fs, false, $p['accent_text']);
    }

    return $y + $ch + 14;
}

function render_cta_banner($im, string $text, float $y, array $p, int $fontSize = 27, string $layout = 'classic'): float
{
    [$cx, $rx, $cw] = render_content_edges();
    $fs = $fontSize;
    $lh = render_lh($fs);
    $aw = render_text_width('→  ', $fs, true);

    if ($layout === 'minimal') {
        // headline, not accent — this is the primary CTA text sitting
        // directly on bg with no fill behind it, so it needs the same
        // guaranteed-readable role headline text uses, not a decorative
        // tint that's only ever meant to sit under accent_text.
        $lines = render_wrap_clamped($text, $fs, true, $cw - $aw, 2);
        render_text($im, $cx, $y, '→', $fs, true, $p['headline']);
        foreach ($lines as $i => $line) {
            render_text($im, $cx + $aw, $y + $i * $lh, $line, $fs, true, $p['headline']);
        }
        return $y + count($lines) * $lh + 16;
    }

    $pad = $layout === 'bold' ? 28 : 20;
    $lines = render_wrap_clamped($text, $fs, true, $cw - $aw - $pad * 2, 2);
    $ph = count($lines) * $lh + $pad * 2;
    render_rrect($im, $cx, $y, $rx, $y + $ph, $p['cta_bg'], 10);
    $ty = $y + ($ph - count($lines) * $lh) / 2;
    render_text($im, $cx + $pad, $ty, '→', $fs, true, $p['cta_text']);
    foreach ($lines as $i => $line) {
        render_text($im, $cx + $pad + $aw, $ty + $i * $lh, $line, $fs, true, $p['cta_text']);
    }
    return $y + $ph + 16;
}

// clamp(800, y+50, 944) per the design spec — content-length budgets
// (exactly 3 points, word-count limits, render_fit_headline_size() /
// render_fit_font_size() auto-shrink) are what keep content from ever
// reaching this ceiling; the footer itself just trusts that and holds
// its documented position. $fontRole ('heading' or 'body') is the
// per-user Settings toggle for which typeface the footer *name* uses —
// see includes/helpers.php get_footer_font_role().
function render_footer_simple($im, float $contentY, array $p, string $name, string $layout = 'classic', string $fontRole = 'body'): void
{
    [$cx, $rx] = render_content_edges();
    $fy = max(800, min($contentY + 50, RENDER_SIZE - RENDER_PAD - 56));

    if ($layout === 'bold') {
        $w = render_text_width($name, 22, true, $fontRole);
        $padX = 16; $padY = 10;
        render_rrect($im, $cx, $fy, $cx + $w + $padX * 2, $fy + 22 + $padY * 2, $p['accent'], 8);
        render_text($im, $cx + $padX, $fy + $padY, $name, 22, true, $p['accent_text'], $fontRole);
        return;
    }
    if ($layout !== 'minimal') {
        imagefilledrectangle($im, (int) $cx, (int) $fy, (int) $rx, (int) $fy + 2, $p['divider']);
    }
    render_text($im, $cx, $fy + 12, $name, 22, true, $p['name'], $fontRole);
}

function render_footer_with_photo($im, float $contentY, array $p, string $name, ?string $photoPath, string $layout = 'classic', string $fontRole = 'body'): void
{
    [$cx, $rx] = render_content_edges();
    $fy = max(720, min($contentY + 50, RENDER_SIZE - RENDER_PAD - 148));
    if ($layout === 'minimal') {
        // no divider
    } elseif ($layout === 'bold') {
        imagefilledrectangle($im, (int) $cx, (int) $fy, (int) $rx, (int) $fy + 4, $p['bar']);
    } else {
        imagefilledrectangle($im, (int) $cx, (int) $fy, (int) $rx, (int) $fy + 2, $p['divider']);
    }
    $py = $fy + 14;

    $circle = $photoPath ? render_circular_photo($photoPath, 108) : null;
    if ($circle) {
        imagealphablending($im, true);
        imagecopy($im, $circle, (int) $cx, (int) $py, 0, 0, 108, 108);
        imagedestroy($circle);
        $nx = $cx + 108 + 18;
        $nfh = render_lh(28);
        $blockH = $nfh + render_lh(20);
        $ny = $py + (108 - $blockH) / 2;
        render_text($im, $nx, $ny, $name, 28, true, $p['headline'], $fontRole);
        render_text($im, $nx, $ny + $nfh, 'Follow for more insights', 20, false, $p['body']);
    } else {
        render_text($im, $cx, $py + 10, $name, 28, true, $p['headline'], $fontRole);
    }
}

// ── Body treatment (shared across slide types) ──────────────────────

// Boxed in the accent color — Hook/Single always use this in 'classic'
// (per the design spec), every slide type uses it in 'bold' (that
// layout's signature always-boxed look).
function render_body_boxed($im, string $body, float $y, array $p, float $cx, float $cw): float
{
    if ($body === '') {
        return $y;
    }
    $blh = render_lh(27);
    $lines = render_wrap_clamped($body, 27, false, $cw - 24, 5);
    $ph = count($lines) * $blh + 28;
    render_rrect($im, $cx, $y, $cx + $cw, $y + $ph, $p['accent'], 10);
    $ty = $y + 14;
    foreach ($lines as $line) {
        render_text($im, $cx + 22, $ty, $line, 27, false, $p['accent_text']);
        $ty += $blh;
    }
    return $y + $ph;
}

// Freestanding text, auto-shrinking to stay within a few lines like the
// headline does — Content/CTA use this in 'classic', every slide type
// uses it in 'minimal' (that layout's signature never-boxed look).
function render_body_freestanding($im, string $body, float $y, array $p, float $cx, float $cw): float
{
    if ($body === '') {
        return $y;
    }
    $bs = render_fit_headline_size($body, $cw, [26, 23, 20], 3, false, 'body');
    $blh = render_lh($bs);
    foreach (render_wrap_clamped($body, $bs, false, $cw, 3, 'body') as $line) {
        render_text($im, $cx, $y, $line, $bs, false, $p['body'], 'body');
        $y += $blh;
    }
    return $y;
}

// ── Slide renderers ──────────────────────────────────────────────────
// $layout is 'classic' (default), 'minimal', or 'bold' — see the "Design
// Template" pickers in Content Studio / New Post / Calendar and
// render_creative_to_slides() below, which resolves it from the
// creative JSON's "layout" field (a separate axis from "template", the
// color palette selector).

function render_slide_hook($im, array $slide, int $total, array $p, string $name, string $seriesLabel = '', string $layout = 'classic', string $footerFontRole = 'body', ?string $logoPath = null): void
{
    [$cx, , $cw] = render_content_edges();
    render_draw_bar($im, $p, $layout);
    render_draw_counter($im, 1, $total, $p);

    $y = render_draw_logo($im, $logoPath, $cx, RENDER_PAD + 12);
    if ($seriesLabel !== '') {
        render_text($im, $cx, $y, strtoupper($seriesLabel), 16, false, $p['counter']);
        $y += render_lh(16) + 8;
    }
    $hs = render_fit_headline_size($slide['headline'] ?? '', $cw, [78, 68, 58, 50, 44], 2);
    $lh = render_lh($hs);
    foreach (render_wrap_clamped($slide['headline'] ?? '', $hs, true, $cw, 2, 'heading') as $line) {
        render_text($im, $cx, $y, $line, $hs, true, $p['headline'], 'heading');
        $y += $lh;
    }

    $y = render_headline_rule($im, $y, $p, $layout);

    $body = $slide['body'] ?? '';
    $y = $layout === 'minimal'
        ? render_body_freestanding($im, $body, $y, $p, $cx, $cw)
        : render_body_boxed($im, $body, $y, $p, $cx, $cw);

    render_footer_simple($im, $y, $p, $name, $layout, $footerFontRole);
}

function render_slide_content($im, array $slide, int $total, array $p, string $name, string $layout = 'classic', string $footerFontRole = 'body', ?string $logoPath = null): void
{
    [$cx, , $cw] = render_content_edges();
    render_draw_bar($im, $p, $layout);
    render_draw_counter($im, (int) $slide['slide_number'], $total, $p);

    $y = render_draw_logo($im, $logoPath, $cx, RENDER_PAD + 12);
    $hs = render_fit_headline_size($slide['headline'] ?? '', $cw, [54, 48, 42, 38, 34], 2);
    $lh = render_lh($hs);
    foreach (render_wrap_clamped($slide['headline'] ?? '', $hs, true, $cw, 2, 'heading') as $line) {
        render_text($im, $cx, $y, $line, $hs, true, $p['headline'], 'heading');
        $y += $lh;
    }

    $y = render_headline_rule($im, $y, $p, $layout);

    $body = $slide['body'] ?? '';
    $y = $layout === 'bold'
        ? render_body_boxed($im, $body, $y, $p, $cx, $cw)
        : render_body_freestanding($im, $body, $y, $p, $cx, $cw);
    if ($body !== '') {
        $y += 22;
    }

    // Defensively cap at exactly 3 regardless of what the AI/user
    // supplied — the prompt already asks for exactly 3, but nothing
    // downstream should trust an LLM (or a manual edit) to actually
    // honor that; this is what keeps the fit-size ceiling below honest.
    $points = array_slice($slide['points'] ?? [], 0, 3);
    $cardSize = render_fit_font_size($points, $y, 894, [26, 23, 20, 18], fn ($item, $size) => render_numbered_card_height($item, $size, $layout));
    foreach ($points as $i => $point) {
        $y = render_numbered_card($im, $i + 1, $point, $y, $p, $cardSize, $layout);
    }

    render_footer_simple($im, $y, $p, $name, $layout, $footerFontRole);
}

function render_slide_cta($im, array $slide, int $total, array $p, string $name, ?string $photoPath, string $layout = 'classic', string $footerFontRole = 'body', ?string $logoPath = null): void
{
    [$cx, , $cw] = render_content_edges();
    render_draw_bar($im, $p, $layout);
    render_draw_counter($im, (int) $slide['slide_number'], $total, $p);

    $y = render_draw_logo($im, $logoPath, $cx, RENDER_PAD + 12);
    $hs = render_fit_headline_size($slide['headline'] ?? '', $cw, [52, 46, 40, 36, 32], 2);
    $lh = render_lh($hs);
    foreach (render_wrap_clamped($slide['headline'] ?? '', $hs, true, $cw, 2, 'heading') as $line) {
        render_text($im, $cx, $y, $line, $hs, true, $p['headline'], 'heading');
        $y += $lh;
    }

    $y = render_headline_rule($im, $y, $p, $layout);

    $body = $slide['body'] ?? '';
    $y = $layout === 'bold'
        ? render_body_boxed($im, $body, $y, $p, $cx, $cw)
        : render_body_freestanding($im, $body, $y, $p, $cx, $cw);
    if ($body !== '') {
        $y += 22;
    }

    // CTA is spec'd as a single "Exact CTA line" — cap defensively to 1
    // for the same reason Content/Single cap points to 3.
    $points = array_slice($slide['points'] ?? [], 0, 1);
    $bannerSize = render_fit_font_size($points, $y, 802, [27, 24, 21, 19], fn ($item, $size) => render_cta_banner_height($item, $size, $layout));
    foreach ($points as $point) {
        $y = render_cta_banner($im, $point, $y, $p, $bannerSize, $layout);
    }

    render_footer_with_photo($im, $y, $p, $name, $photoPath, $layout, $footerFontRole);
}

function render_slide_single($im, array $data, array $p, string $name, string $layout = 'classic', string $footerFontRole = 'body', ?string $logoPath = null): void
{
    [$cx, , $cw] = render_content_edges();
    render_draw_bar($im, $p, $layout);
    $slide = $data['slides'][0];

    $y = render_draw_logo($im, $logoPath, $cx, RENDER_PAD + 12);
    $hs = render_fit_headline_size($slide['headline'] ?? '', $cw, [68, 60, 52, 46, 40], 3);
    $lh = render_lh($hs);
    foreach (render_wrap_clamped($slide['headline'] ?? '', $hs, true, $cw, 3, 'heading') as $line) {
        render_text($im, $cx, $y, $line, $hs, true, $p['headline'], 'heading');
        $y += $lh;
    }

    $y = render_headline_rule($im, $y, $p, $layout);

    // Boxed in classic (Single shares Hook's spec row) and bold (always
    // boxed); freestanding only in minimal.
    $body = $slide['body'] ?? '';
    $y = $layout === 'minimal'
        ? render_body_freestanding($im, $body, $y, $p, $cx, $cw)
        : render_body_boxed($im, $body, $y, $p, $cx, $cw);
    if ($body !== '') {
        $y += 22;
    }

    $points = array_slice($slide['points'] ?? [], 0, 3);
    $cardSize = render_fit_font_size($points, $y, 894, [26, 23, 20, 18], fn ($item, $size) => render_numbered_card_height($item, $size, $layout));
    foreach ($points as $i => $point) {
        $y = render_numbered_card($im, $i + 1, $point, $y, $p, $cardSize, $layout);
    }

    render_footer_simple($im, $y, $p, $name, $layout, $footerFontRole);
}

// ── Main entry point ─────────────────────────────────────────────────

// Renders $data (the JSON shape from creative_builder.php / ai_generate.php)
// into $outDir/slide_01.png, slide_02.png, ... and returns the slide list
// in the same ['filename' => ..., 'filepath' => ...] shape
// includes/zip_import.php produces, so callers can insert post_slides
// rows identically either way.
function render_creative_to_slides(array $data, string $outDir, string $footerName, ?string $photoPath = null, int $userId = 0): array
{
    if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
        throw new RuntimeException("Could not create output directory: {$outDir}");
    }

    $headingFont = $userId ? fetch_heading_font($userId) : null;
    $bodyFont = $userId ? fetch_body_font($userId) : null;
    render_font_override_role('heading', $headingFont ? ['regular' => $headingFont['regular_path'], 'bold' => $headingFont['bold_path']] : null, true);
    render_font_override_role('body', $bodyFont ? ['regular' => $bodyFont['regular_path'], 'bold' => $bodyFont['bold_path']] : null, true);
    $footerFontRole = $userId ? get_footer_font_role($userId) : 'body';

    $paletteColors = render_resolve_palette_colors($data['template'] ?? null, $userId, $data['series_label'] ?? null);
    $layout = in_array($data['layout'] ?? '', ['minimal', 'bold'], true) ? $data['layout'] : 'classic';
    $bgStyle = ($data['background'] ?? '') === 'gradient' ? 'gradient' : 'flat';
    $logoPath = $userId ? resolve_brand_logo($userId) : null;
    $slides = $data['slides'] ?? [];
    $total = count($slides);
    if ($total === 0) {
        throw new RuntimeException('No slides to render.');
    }
    $isSingle = ($data['format'] ?? '') === 'single';

    $result = [];
    if ($isSingle) {
        $im = imagecreatetruecolor(RENDER_SIZE, RENDER_SIZE);
        render_draw_background($im, $paletteColors, $bgStyle);
        $p = render_allocate_palette_colors($im, $paletteColors);
        render_slide_single($im, $data, $p, $footerName, $layout, $footerFontRole, $logoPath);
        $filename = 'slide_01.png';
        $path = $outDir . '/' . $filename;
        imagepng($im, $path);
        imagedestroy($im);
        $result[] = ['filename' => $filename, 'filepath' => $path];
        return $result;
    }

    foreach ($slides as $slide) {
        $n = (int) $slide['slide_number'];
        $im = imagecreatetruecolor(RENDER_SIZE, RENDER_SIZE);
        render_draw_background($im, $paletteColors, $bgStyle);
        $p = render_allocate_palette_colors($im, $paletteColors);

        if ($n === 1) {
            render_slide_hook($im, $slide, $total, $p, $footerName, $data['series_label'] ?? '', $layout, $footerFontRole, $logoPath);
        } elseif ($n === $total) {
            render_slide_cta($im, $slide, $total, $p, $footerName, $photoPath, $layout, $footerFontRole, $logoPath);
        } else {
            render_slide_content($im, $slide, $total, $p, $footerName, $layout, $footerFontRole, $logoPath);
        }

        $filename = sprintf('slide_%02d.png', $n);
        $path = $outDir . '/' . $filename;
        imagepng($im, $path);
        imagedestroy($im);
        $result[] = ['filename' => $filename, 'filepath' => $path];
    }

    return $result;
}
