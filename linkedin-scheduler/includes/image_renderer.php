<?php
// GD port of the local Python prototype's render.py. Renders the JSON
// produced by includes/creative_builder.php or includes/ai_generate.php
// into square slide PNGs (RENDER_SIZE x RENDER_SIZE, see below), one per
// campaign, matching the Python version's layout as closely as GD
// allows (see notes below on the two primitives GD has no direct
// equivalent for: rounded rectangles and a circular-cropped photo).
//
// GD's imagettftext() Y coordinate is the text BASELINE, not top-left
// like PIL's draw.text() — gd_text() below converts once via a per-
// font/size ascent measurement so every call site can keep thinking in
// top-left coordinates, same as the Python original.

if (!function_exists('imagettftext')) {
    throw new RuntimeException('The GD extension (with FreeType/TTF support) is required to render images and is not available on this PHP install.');
}

// 1350 (was 1080) — a 1.25x proportional scale-up for sharper output
// (LinkedIn's own recommended square-image size is 1200x1200). Every
// other size in this file — fonts, padding, badges, gaps, decorations —
// is expressed as rs($px) relative to the original 1080 design so they
// scale together; changing RENDER_SIZE alone would just add empty
// margin, not sharpen anything (see rs() below).
const RENDER_SIZE = 1350;
const RENDER_SCALE = RENDER_SIZE / 1080.0;
const RENDER_PAD  = 100; // rs(80)
const RENDER_BAR  = 10;  // rs(8)

// Scales a pixel value originally designed against a 1080 canvas to the
// current RENDER_SIZE. Used for every size literal in this file (font
// sizes, gaps, badge/shape dimensions, footer clamp Y positions) so the
// whole render scales together — the only way enlarging RENDER_SIZE
// actually sharpens output instead of just adding empty margin.
function rs(float $px): int
{
    return (int) round($px * RENDER_SCALE);
}

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
// from just 2 required + 3 optional user-picked hex colors (see
// pages/settings.php Brand Palettes section). accent_text/cta_text/
// badge_text are always computed via contrast ratio against whatever
// they sit on, so no combination of user-picked colors can produce
// unreadable text. $signatureHex is an optional manual override for the
// footer name specifically — unlike the other roles it has no computed
// fallback baked into this map; when unset, callers keep using the
// existing auto-derived 'name'/'accent_text'/'headline' roles for the
// footer (see render_creative_to_slides()), so it's only present in the
// returned array's 'signature' key when the user actually set one.
function render_derive_palette_colors(string $bgHex, string $textHex, ?string $accentHex = null, ?string $ctaHex = null, ?string $signatureHex = null): array
{
    $bg     = hex_to_rgb($bgHex);
    $text   = hex_to_rgb($textHex);
    $accent = $accentHex ? hex_to_rgb($accentHex) : mix_colors($bg, $text, 0.08);
    $cta    = $ctaHex ? hex_to_rgb($ctaHex) : $text;
    $body   = mix_colors($text, $bg, 0.35);

    $colors = [
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
    if ($signatureHex) {
        $colors['signature'] = hex_to_rgb($signatureHex);
    }
    return $colors;
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
            return render_derive_palette_colors($palette['bg_color'], $palette['text_color'], $palette['accent_color'], $palette['cta_color'], $palette['signature_color'] ?? null);
        }
    }

    $defaultPalette = fetch_default_brand_palette($userId);
    if ($defaultPalette) {
        return render_derive_palette_colors($defaultPalette['bg_color'], $defaultPalette['text_color'], $defaultPalette['accent_color'], $defaultPalette['cta_color'], $defaultPalette['signature_color'] ?? null);
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
    // Bundled, not overridable — a serif-styled design template should
    // always look serif regardless of what the user set as their brand
    // Heading/Body font, so templates stay visually predictable.
    if ($role === 'serif') {
        $path = __DIR__ . '/../assets/fonts/LiberationSerif-' . ($bold ? 'Bold' : 'Regular') . '.ttf';
        if (is_file($path)) {
            return $path;
        }
    }

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
    return max((int) ($fontSize * 1.5), $fontSize + rs(12));
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

// Stroke-only rectangle for the 'outline' CTA style — deliberately
// sharp-cornered rather than fully rounded (matching Savvy Finance's
// bordered "CLAIM THE OFFER" button): a true rounded *stroke* needs arc
// geometry GD doesn't offer directly, while a sharp border via
// imagerectangle() preserves whatever's underneath (works over a
// gradient background) with no risk of a mismatched fill color.
function render_rect_outline($im, float $x1, float $y1, float $x2, float $y2, int $color, int $thickness = 2): void
{
    imagesetthickness($im, $thickness);
    imagerectangle($im, (int) round($x1), (int) round($y1), (int) round($x2), (int) round($y2), $color);
    imagesetthickness($im, 1);
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
    // 56 (scaled), not the original 36 — a wordmark with any fine detail
    // (thin strokes, small text) turned soft at 36px after resampling;
    // this is still a small top-left mark, just no longer aggressively
    // shrunk.
    $dh = rs(56);
    $dw = (int) round($sw * ($dh / $sh));

    imagealphablending($im, true);
    imagesavealpha($src, true);
    imagecopyresampled($im, $src, (int) $cx, (int) $y, 0, 0, $dw, $dh, $sw, $sh);
    imagedestroy($src);

    return $y + $dh + rs(20);
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
    $width = $layout === 'bold' ? rs(14) : RENDER_BAR;
    imagefilledrectangle($im, 0, 0, $width, RENDER_SIZE, $p['bar']);
}

function render_draw_counter($im, int $n, int $total, array $p): void
{
    [, $rx] = render_content_edges();
    $text = sprintf('%02d / %02d', $n, $total);
    $size = rs(24);
    $w = render_text_width($text, $size, false);
    render_text($im, $rx - $w, RENDER_PAD - rs(4), $text, $size, false, $p['counter']);
}

function render_draw_rule($im, float $y, array $p): float
{
    [$cx] = render_content_edges();
    $gapAbove = rs(14); $thick = rs(4); $gapBelow = rs(22);
    imagefilledrectangle($im, (int) $cx, (int) ($y + $gapAbove), (int) ($cx + rs(100)), (int) ($y + $gapAbove + $thick), $p['rule']);
    return $y + $gapAbove + $thick + $gapBelow;
}

// The divider between headline and body — 'classic' is render_draw_rule()
// unchanged, 'minimal' is just whitespace (no line), 'bold' is a thicker
// solid block for more visual weight.
function render_headline_rule($im, float $y, array $p, string $layout = 'classic'): float
{
    if ($layout === 'minimal') {
        return $y + rs(14) + rs(22);
    }
    if ($layout === 'bold') {
        [$cx] = render_content_edges();
        $gapAbove = rs(14); $thick = rs(8); $gapBelow = rs(22);
        imagefilledrectangle($im, (int) $cx, (int) ($y + $gapAbove), (int) ($cx + rs(100)), (int) ($y + $gapAbove + $thick), $p['rule']);
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
    if ($layout === 'divider') {
        return render_divider_point_height($text, $fontSize);
    }
    [, , $cw] = render_content_edges();
    $badge = rs(44); $px = rs(20); $py = rs(15);
    $lines = render_wrap_clamped($text, $fontSize, false, $cw - $badge - $px * 2 - rs(18), 2);
    $ch = max($badge + $py * 2, count($lines) * render_lh($fontSize) + $py * 2);
    return $ch + rs(14); // + the gap render_numbered_card() adds below each card
}

// Minimal layout's point style has no badge, so its floor is text height
// alone rather than classic/bold's 44px-badge-driven minimum.
function render_minimal_point_height(string $text, int $fontSize): float
{
    [, , $cw] = render_content_edges();
    $barW = rs(4); $gap = rs(16);
    $numW = render_text_width('00  ', $fontSize, true);
    $lines = render_wrap_clamped($text, $fontSize, false, $cw - $barW - $gap - $numW, 2);
    return count($lines) * render_lh($fontSize) + rs(6) + rs(20); // top pad + gap below
}

// Divider-style point (Borcelle/Cloudlet reference): arrow + text with a
// thin rule below, no card fill — the icon-plus-hairline list style.
function render_divider_point_height(string $text, int $fontSize): float
{
    [, , $cw] = render_content_edges();
    $aw = render_text_width('↘  ', $fontSize, true);
    $lines = render_wrap_clamped($text, $fontSize, false, $cw - $aw, 2);
    return count($lines) * render_lh($fontSize) + rs(28); // text height + rule + gap below
}

// Pure measurement, no drawing — mirrors render_cta_banner()'s height math.
function render_cta_banner_height(string $text, int $fontSize, string $layout = 'classic'): float
{
    [, , $cw] = render_content_edges();
    $aw = render_text_width('→  ', $fontSize, true);
    if ($layout === 'minimal') {
        $lines = render_wrap_clamped($text, $fontSize, true, $cw - $aw, 2);
        return count($lines) * render_lh($fontSize) + rs(16); // no box, just the gap below
    }
    if ($layout === 'pill' || $layout === 'outline') {
        // Single line, kept compact rather than stretched full-width —
        // a real pill/outline button, not a banner (see render_cta_banner()).
        $pad = rs(24);
        return render_lh($fontSize) + $pad * 2 + rs(16);
    }
    $pad = $layout === 'bold' ? rs(28) : rs(20);
    $lines = render_wrap_clamped($text, $fontSize, true, $cw - $aw - $pad * 2, 2);
    return count($lines) * render_lh($fontSize) + $pad * 2 + rs(16); // + the gap below
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
    // Strip **emphasis** markers before measuring — even on a template
    // that won't color them, drawing always strips them too (see
    // render_draw_headline()), so leaving them in here would measure
    // width the actual output never has.
    $text = render_strip_emphasis_markers($text);
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

// ── Headline color emphasis ──────────────────────────────────────────
// A handful of reference designs (Arbor, Savvy Finance, "Creative
// Business") color specific words within one headline to highlight them.
// Reuses a familiar **word** marker syntax right inside the headline
// string — no new JSON field, works everywhere headline text already
// flows (AI generation, manual typing, review-card edits). Only design
// templates with emphasis=true in render_design_templates() actually
// color the marked runs; every other template strips markers via
// render_strip_emphasis_markers() and draws plain text, so typing ** on
// a non-emphasis template degrades safely instead of showing literal
// asterisks.

function render_strip_emphasis_markers(string $text): string
{
    return str_replace('**', '', $text);
}

// Splits headline text on **marker** spans into a flat word list tagged
// with whether each word should be drawn in the accent color.
function render_tokenize_emphasis(string $text): array
{
    $parts = preg_split('/\*\*(.+?)\*\*/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $words = [];
    foreach ($parts as $i => $part) {
        $emphasized = $i % 2 === 1; // odd indices are the captured **...** groups
        foreach (preg_split('/\s+/', trim($part)) as $word) {
            if ($word !== '') {
                $words[] = ['text' => $word, 'emphasized' => $emphasized];
            }
        }
    }
    return $words;
}

// Emphasis-aware counterpart to render_wrap() — same greedy word-wrap
// algorithm, but each line is an array of word structs (not a plain
// string) so render_text_emphasized() knows which words to color.
function render_wrap_emphasized(string $text, int $size, bool $bold, float $maxPx, string $role = 'body'): array
{
    $words = render_tokenize_emphasis($text);
    if (!$words) {
        return [[]];
    }
    $lines = [];
    $current = [];
    foreach ($words as $word) {
        $test = implode(' ', array_map(fn ($w) => $w['text'], array_merge($current, [$word])));
        if ($current && render_text_width($test, $size, $bold, $role) > $maxPx) {
            $lines[] = $current;
            $current = [$word];
        } else {
            $current[] = $word;
        }
    }
    if ($current) {
        $lines[] = $current;
    }
    return $lines;
}

// Hard line-count cap, mirroring render_wrap_clamped()'s guarantee for
// the plain-text path.
function render_wrap_emphasized_clamped(string $text, int $size, bool $bold, float $maxPx, int $maxLines, string $role = 'body'): array
{
    $lines = render_wrap_emphasized($text, $size, $bold, $maxPx, $role);
    if (count($lines) <= $maxLines) {
        return $lines;
    }
    $lines = array_slice($lines, 0, $maxLines);
    $last = $lines[$maxLines - 1];
    if ($last) {
        $last[count($last) - 1]['text'] = rtrim($last[count($last) - 1]['text']) . '…';
    }
    $lines[$maxLines - 1] = $last;
    return $lines;
}

// Draws one render_wrap_emphasized() line, switching color per word.
function render_text_emphasized($im, float $x, float $topY, array $line, int $size, bool $bold, int $normalColor, int $accentColor, string $role = 'body'): void
{
    $cx = $x;
    $spaceW = render_text_width(' ', $size, $bold, $role);
    foreach ($line as $word) {
        render_text($im, $cx, $topY, $word['text'], $size, $bold, $word['emphasized'] ? $accentColor : $normalColor, $role);
        $cx += render_text_width($word['text'], $size, $bold, $role) + $spaceW;
    }
}

// Resolves font size + wrapped lines for a headline, branching on the
// active design template's font role (sans/serif) and whether it colors
// emphasized **word** spans. $lines is either an array of plain strings
// (no emphasis) or render_wrap_emphasized_clamped()'s word-struct lines
// (emphasis) — render_draw_headline_line() below knows how to draw both.
// Shared by render_draw_headline() (top-anchored) and
// render_draw_headline_centered() (the "Title Only" treatment) so both
// stay in exact agreement about sizing/wrapping.
function render_resolve_headline_lines(string $headline, float $cw, array $candidateSizes, int $maxLines, array $preset): array
{
    $fontRole = $preset['font'] === 'serif' ? 'serif' : 'heading';
    if ($preset['emphasis']) {
        $hs = render_fit_headline_size($headline, $cw, $candidateSizes, $maxLines, true, $fontRole);
        $lines = render_wrap_emphasized_clamped($headline, $hs, true, $cw, $maxLines, $fontRole);
    } else {
        $plain = render_strip_emphasis_markers($headline);
        $hs = render_fit_headline_size($plain, $cw, $candidateSizes, $maxLines, true, $fontRole);
        $lines = render_wrap_clamped($plain, $hs, true, $cw, $maxLines, $fontRole);
    }
    return [$hs, render_lh($hs), $lines, $fontRole];
}

// Draws one line from render_resolve_headline_lines() — a plain string
// or an emphasized word-struct line, depending on $preset['emphasis'].
// body, not accent, for the emphasized color — same reasoning as every
// other legibility fix this session: accent is only ever a
// guaranteed-safe fill/tint, not guaranteed readable as text (it's
// deliberately close to bg on some palettes, e.g. Cream).
function render_draw_headline_line($im, $line, float $x, float $y, int $hs, array $p, array $preset, string $fontRole): void
{
    if ($preset['emphasis']) {
        render_text_emphasized($im, $x, $y, $line, $hs, true, $p['headline'], $p['body'], $fontRole);
    } else {
        render_text($im, $x, $y, $line, $hs, true, $p['headline'], $fontRole);
    }
}

// Shared by all 4 slide types — top-anchored headline draw (the normal
// case). Centralizing this here (rather than repeating the branch in
// every render_slide_*()) is what keeps adding more templates cheap.
function render_draw_headline($im, string $headline, float $cx, float $cw, float $y, array $candidateSizes, int $maxLines, array $p, array $preset): float
{
    [$hs, $lh, $lines, $fontRole] = render_resolve_headline_lines($headline, $cw, $candidateSizes, $maxLines, $preset);
    foreach ($lines as $line) {
        render_draw_headline_line($im, $line, $cx, $y, $hs, $p, $preset, $fontRole);
        $y += $lh;
    }
    return $y;
}

// "Title Only" treatment: a large, vertically-centered headline filling
// the frame, used when a slide has no body and no points (see
// render_is_title_only()) instead of leaving a mostly-empty gap under a
// normally-sized top-anchored headline. Centers within [$topY, $bottomY]
// — callers keep $bottomY comfortably above the footer's own clamp floor
// (800/720) so there's no need to compute an exact content-bottom for
// the footer call afterward; any $bottomY-bounded block is already safe.
function render_draw_headline_centered($im, string $headline, float $cx, float $cw, float $topY, float $bottomY, array $candidateSizes, int $maxLines, array $p, array $preset): void
{
    [$hs, $lh, $lines, $fontRole] = render_resolve_headline_lines($headline, $cw, $candidateSizes, $maxLines, $preset);
    $blockH = count($lines) * $lh;
    $y = max($topY, $topY + ($bottomY - $topY - $blockH) / 2);
    foreach ($lines as $line) {
        render_draw_headline_line($im, $line, $cx, $y, $hs, $p, $preset, $fontRole);
        $y += $lh;
    }
}

// A slide is "Title Only" when it has no body and no points to show —
// rather than a separate format/toggle, leaving those fields blank in
// the review step is itself the signal: the headline gets the large,
// centered "impact statement" treatment instead of sitting top-anchored
// over a mostly-empty frame. Works the same way in Single Image and any
// individual carousel slide (Hook/Content/CTA).
function render_is_title_only(array $slide): bool
{
    return trim($slide['body'] ?? '') === '' && empty($slide['points']);
}

// ── Corner decorations ───────────────────────────────────────────────
// Solid/dotted shapes anchored to a corner, approximating the recurring
// "decorative shape bleeding off the edge" pattern in the reference
// designs (Arbor's halftone blob, Borcelle's corner texture, the bleed
// circles behind photos/headlines) without needing true blob/bezier
// geometry — all 3 are deliberately confined to a corner zone that stays
// clear of where headline text and the footer normally sit, called once
// near the top of render_slide_*() (after the background, before text)
// so text always draws on top if anything overlaps.

function render_decoration_bleed_circle($im, array $p): void
{
    $r = rs(480);
    imagefilledellipse($im, RENDER_SIZE - rs(60), -rs(60), $r, $r, $p['accent']);
}

function render_decoration_halftone($im, array $p): void
{
    $rgb = imagecolorsforindex($im, $p['accent']);
    $tint = imagecolorallocatealpha($im, $rgb['red'], $rgb['green'], $rgb['blue'], 55);
    $cols = 9; $rows = 7; $spacing = rs(24); $dotR = rs(7);
    $x0 = RENDER_SIZE - rs(30); $y0 = -rs(10);
    for ($row = 0; $row < $rows; $row++) {
        for ($col = 0; $col < $cols; $col++) {
            // Diagonal falloff so the grid reads as a blob tapering
            // toward the bottom-left, not a hard rectangle.
            if ($row + $col > ($rows + $cols) * 0.6) {
                continue;
            }
            $x = $x0 - $col * $spacing;
            $y = $y0 + $row * $spacing + ($col % 2 === 0 ? 0 : $spacing / 2);
            imagefilledellipse($im, (int) $x, (int) $y, $dotR, $dotR, $tint);
        }
    }
}

function render_decoration_triangles($im, array $p): void
{
    $rgb = imagecolorsforindex($im, $p['accent']);
    $tint = imagecolorallocatealpha($im, $rgb['red'], $rgb['green'], $rgb['blue'], 70);
    $size = rs(20); $rows = 5;
    $x0 = 0; $y0 = RENDER_SIZE;
    for ($row = 0; $row < $rows; $row++) {
        for ($col = 0; $col < $rows - $row; $col++) {
            $x = $x0 + $col * $size;
            $y = $y0 - $row * $size;
            imagefilledpolygon($im, [$x, $y, (int) ($x + $size), $y, $x, (int) ($y - $size)], 3, $tint);
        }
    }
}

function render_draw_decoration($im, array $p, ?string $decoration): void
{
    match ($decoration) {
        'bleed_circle' => render_decoration_bleed_circle($im, $p),
        'halftone'     => render_decoration_halftone($im, $p),
        'triangles'    => render_decoration_triangles($im, $p),
        default        => null,
    };
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
    $size = (int) min(rs(90), $ch * 0.9);
    $lbl = sprintf('%02d', $num);
    $w = render_text_width($lbl, $size, true);
    render_text($im, $rx - $w - rs(16), $y + ($ch - $size) / 2 - $size * 0.15, $lbl, $size, true, $tint);
}

// Minimal layout's point style: a slim accent-colored bar instead of a
// filled card, the number as plain bold text instead of a pill badge —
// see render_minimal_point_height() for the matching measurement.
function render_minimal_point($im, int $num, string $text, float $y, array $p, int $fontSize): float
{
    [$cx, , $cw] = render_content_edges();
    $barW = rs(4); $gap = rs(16); $py = rs(6);
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

    return $y + $py + $textH + rs(20);
}

// Divider-style point: arrow + text + a thin rule below, no card fill —
// matches render_divider_point_height()'s measurement exactly.
function render_divider_point($im, int $num, string $text, float $y, array $p, int $fontSize): float
{
    [$cx, $rx, $cw] = render_content_edges();
    $aw = render_text_width('↘  ', $fontSize, true);
    $lines = render_wrap_clamped($text, $fontSize, false, $cw - $aw, 2);
    $lh = render_lh($fontSize);

    // headline, not accent — same reasoning as render_minimal_point()'s
    // number label: this arrow sits directly on bg, so it needs
    // guaranteed contrast, not a decorative tint.
    render_text($im, $cx, $y, '↘', $fontSize, true, $p['headline']);
    foreach ($lines as $i => $line) {
        render_text($im, $cx + $aw, $y + $i * $lh, $line, $fontSize, false, $p['body']);
    }
    $textH = count($lines) * $lh;
    $ruleY = $y + $textH + rs(14);
    imagefilledrectangle($im, (int) $cx, (int) $ruleY, (int) $rx, (int) $ruleY + 1, $p['divider']);
    return $ruleY + rs(14);
}

function render_numbered_card($im, int $num, string $text, float $y, array $p, int $fontSize = 26, string $layout = 'classic'): float
{
    if ($layout === 'minimal') {
        return render_minimal_point($im, $num, $text, $y, $p, $fontSize);
    }
    if ($layout === 'divider') {
        return render_divider_point($im, $num, $text, $y, $p, $fontSize);
    }
    [$cx, $rx, $cw] = render_content_edges();
    $fs = $fontSize; $badge = rs(44); $px = rs(20); $py = rs(15);
    $lines = render_wrap_clamped($text, $fs, false, $cw - $badge - $px * 2 - rs(18), 2);
    $lineH = render_lh($fs);
    $textH = count($lines) * $lineH;
    $ch = max($badge + $py * 2, $textH + $py * 2);

    render_rrect($im, $cx, $y, $rx, $y + $ch, $p['accent'], rs(8));

    if ($layout === 'bold') {
        render_point_watermark($im, $num, $cx, $y, $rx, $ch, $p);
    }

    $bx = $cx + $px;
    $by = $y + ($ch - $badge) / 2;
    render_rrect($im, $bx, $by, $bx + $badge, $by + $badge, $p['badge_bg'], rs(6));
    $lbl = sprintf('%02d', $num);
    $lblSize = rs(19);
    $lw = render_text_width($lbl, $lblSize, true);
    render_text($im, $bx + ($badge - $lw) / 2, $by + ($badge - $lblSize) / 2, $lbl, $lblSize, true, $p['badge_text']);

    $tx = $bx + $badge + rs(16);
    $ty = $y + ($ch - $textH) / 2;
    foreach ($lines as $i => $line) {
        render_text($im, $tx, $ty + $i * $lineH, $line, $fs, false, $p['accent_text']);
    }

    return $y + $ch + rs(14);
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
        return $y + count($lines) * $lh + rs(16);
    }

    if ($layout === 'pill' || $layout === 'outline') {
        // Single line, sized to its own text rather than stretched full
        // width — a compact button (Wildlife Watch's "Download now",
        // Savvy Finance's "CLAIM THE OFFER"), not a full-width banner.
        $pad = rs(24);
        $lines = render_wrap_clamped($text, $fs, true, $cw - $aw - $pad * 2, 1);
        $lineW = render_text_width($lines[0], $fs, true);
        $ph = $lh + $pad * 2;
        $pw = (int) min($cw, $aw + $lineW + $pad * 2);
        $radius = (int) ($ph / 2);
        if ($layout === 'pill') {
            render_rrect($im, $cx, $y, $cx + $pw, $y + $ph, $p['cta_bg'], $radius);
            render_text($im, $cx + $pad, $y + $pad, '→', $fs, true, $p['cta_text']);
            render_text($im, $cx + $pad + $aw, $y + $pad, $lines[0], $fs, true, $p['cta_text']);
        } else {
            render_rect_outline($im, $cx, $y, $cx + $pw, $y + $ph, $p['headline'], rs(2));
            render_text($im, $cx + $pad, $y + $pad, '→', $fs, true, $p['headline']);
            render_text($im, $cx + $pad + $aw, $y + $pad, $lines[0], $fs, true, $p['headline']);
        }
        return $y + $ph + rs(16);
    }

    $pad = $layout === 'bold' ? rs(28) : rs(20);
    $lines = render_wrap_clamped($text, $fs, true, $cw - $aw - $pad * 2, 2);
    $ph = count($lines) * $lh + $pad * 2;
    render_rrect($im, $cx, $y, $rx, $y + $ph, $p['cta_bg'], rs(10));
    $ty = $y + ($ph - count($lines) * $lh) / 2;
    render_text($im, $cx + $pad, $ty, '→', $fs, true, $p['cta_text']);
    foreach ($lines as $i => $line) {
        render_text($im, $cx + $pad + $aw, $ty + $i * $lh, $line, $fs, true, $p['cta_text']);
    }
    return $y + $ph + rs(16);
}

// The footer name is never wrapped (a signature reads as one line), so
// an unusually long profile/page display name could otherwise run past
// the canvas edge — shrinks in 2px steps until it fits $maxPx, the same
// "auto-shrink rather than overflow" precedent every other
// variable-length text in this file follows (render_fit_headline_size(),
// render_fit_font_size()). The floor is a fixed low value, not
// proportional to $preferredSize — $preferredSize can be pushed well
// past its built-in default by a manual Settings size override
// (get_footer_name_size()), and a floor scaled off that inflated value
// stops shrinking long before the text actually fits. Best-effort like
// render_fit_headline_size()'s smallest-candidate fallback: an
// absurdly long name at this floor can still slightly overflow.
function render_fit_footer_name_size(string $name, float $maxPx, int $preferredSize, string $fontRole): int
{
    $floor = 14;
    for ($size = $preferredSize; $size > $floor; $size -= 2) {
        if (render_text_width($name, $size, true, $fontRole) <= $maxPx) {
            return $size;
        }
    }
    return $floor;
}

// clamp(800, y+50, 944) per the design spec — content-length budgets
// (exactly 3 points, word-count limits, render_fit_headline_size() /
// render_fit_font_size() auto-shrink) are what keep content from ever
// reaching this ceiling; the footer itself just trusts that and holds
// its documented position. $fontRole ('heading', 'body', or 'footer' if
// the user assigned a dedicated Signature font) is the per-user Settings
// choice for which typeface the footer *name* uses — see
// includes/helpers.php get_footer_font_role() and
// includes/post_helpers.php fetch_footer_font(). $nameColorRgb is the
// resolved palette's optional per-palette signature color override (see
// render_derive_palette_colors()'s 'signature' key); $nameSizeOverride
// is the manual Settings size override (see includes/helpers.php
// get_footer_name_size()) — null for either keeps today's auto-derived
// palette color / auto size, so existing users see no change.
function render_footer_simple($im, float $contentY, array $p, string $name, string $layout = 'classic', string $fontRole = 'body', ?array $nameColorRgb = null, ?int $nameSizeOverride = null): void
{
    [$cx, $rx] = render_content_edges();
    $fy = max(rs(800), min($contentY + rs(50), RENDER_SIZE - RENDER_PAD - rs(56)));
    $nameColor = $nameColorRgb ? imagecolorallocate($im, $nameColorRgb[0], $nameColorRgb[1], $nameColorRgb[2]) : null;

    // 26 (was 22) — the footer signature is the smallest bold text in the
    // whole composition, so GD's anti-aliasing artifacts on small bold
    // curves are proportionally most visible here; a few extra px makes
    // those edges a smaller fraction of each letter and reads noticeably
    // cleaner without changing the overall footer layout. $nameSizeOverride
    // is a literal rendered pixel size (not run through rs() again) —
    // see schema.sql's comment on users.footer_name_size.
    $preferred = $nameSizeOverride ?? rs(26);
    if ($layout === 'bold') {
        $padX = rs(16); $padY = rs(10);
        $nameSize = render_fit_footer_name_size($name, ($rx - $cx) - $padX * 2, $preferred, $fontRole);
        $w = render_text_width($name, $nameSize, true, $fontRole);
        render_rrect($im, $cx, $fy, $cx + $w + $padX * 2, $fy + $nameSize + $padY * 2, $p['accent'], rs(8));
        render_text($im, $cx + $padX, $fy + $padY, $name, $nameSize, true, $nameColor ?? $p['accent_text'], $fontRole);
        return;
    }
    if ($layout !== 'minimal') {
        imagefilledrectangle($im, (int) $cx, (int) $fy, (int) $rx, (int) $fy + rs(2), $p['divider']);
    }
    $nameSize = render_fit_footer_name_size($name, $rx - $cx, $preferred, $fontRole);
    render_text($im, $cx, $fy + rs(12), $name, $nameSize, true, $nameColor ?? $p['name'], $fontRole);
}

function render_footer_with_photo($im, float $contentY, array $p, string $name, ?string $photoPath, string $layout = 'classic', string $fontRole = 'body', ?array $nameColorRgb = null, ?int $nameSizeOverride = null): void
{
    [$cx, $rx] = render_content_edges();
    $fy = max(rs(720), min($contentY + rs(50), RENDER_SIZE - RENDER_PAD - rs(148)));
    if ($layout === 'minimal') {
        // no divider
    } elseif ($layout === 'bold') {
        imagefilledrectangle($im, (int) $cx, (int) $fy, (int) $rx, (int) $fy + rs(4), $p['bar']);
    } else {
        imagefilledrectangle($im, (int) $cx, (int) $fy, (int) $rx, (int) $fy + rs(2), $p['divider']);
    }
    $py = $fy + rs(14);
    $nameColor = $nameColorRgb ? imagecolorallocate($im, $nameColorRgb[0], $nameColorRgb[1], $nameColorRgb[2]) : null;
    // This slide's signature always renders larger than render_footer_simple()'s
    // (32 vs 26 design px) as an intentional sign-off emphasis — a manual
    // size override keeps that same ratio rather than flattening it, so
    // the CTA slide's signature stays proportionally bigger either way.
    $preferred = $nameSizeOverride ? (int) round($nameSizeOverride * (rs(32) / rs(26))) : rs(32);

    $photoSize = rs(108);
    $circle = $photoPath ? render_circular_photo($photoPath, $photoSize) : null;
    if ($circle) {
        imagealphablending($im, true);
        imagecopy($im, $circle, (int) $cx, (int) $py, 0, 0, $photoSize, $photoSize);
        imagedestroy($circle);
        // 32/22 (was 28/20) — same small-bold-text anti-aliasing fix as
        // render_footer_simple() above; blockH (103px at these sizes)
        // still comfortably fits inside the 108px photo circle it's
        // vertically centered against. Name width is fit-checked against
        // the space right of the photo — see render_fit_footer_name_size().
        $nx = $cx + $photoSize + rs(18);
        $nameSize = render_fit_footer_name_size($name, $rx - $nx, $preferred, $fontRole);
        $nfh = render_lh($nameSize);
        $blockH = $nfh + render_lh(rs(22));
        $ny = $py + ($photoSize - $blockH) / 2;
        render_text($im, $nx, $ny, $name, $nameSize, true, $nameColor ?? $p['headline'], $fontRole);
        render_text($im, $nx, $ny + $nfh, 'Follow for more insights', rs(22), false, $p['body']);
    } else {
        $nameSize = render_fit_footer_name_size($name, $rx - $cx, $preferred, $fontRole);
        render_text($im, $cx, $py + rs(10), $name, $nameSize, true, $nameColor ?? $p['headline'], $fontRole);
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
    $fs = rs(27);
    $blh = render_lh($fs);
    $lines = render_wrap_clamped($body, $fs, false, $cw - rs(24), 5);
    $ph = count($lines) * $blh + rs(28);
    render_rrect($im, $cx, $y, $cx + $cw, $y + $ph, $p['accent'], rs(10));
    $ty = $y + rs(14);
    foreach ($lines as $line) {
        render_text($im, $cx + rs(22), $ty, $line, $fs, false, $p['accent_text']);
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
    $bs = render_fit_headline_size($body, $cw, [rs(26), rs(23), rs(20)], 3, false, 'body');
    $blh = render_lh($bs);
    foreach (render_wrap_clamped($body, $bs, false, $cw, 3, 'body') as $line) {
        render_text($im, $cx, $y, $line, $bs, false, $p['body'], 'body');
        $y += $blh;
    }
    return $y;
}

// ── Design template gallery ──────────────────────────────────────────
// $layout selects one of these presets — see the "Design Template"
// pickers in Content Studio / New Post / Calendar and
// render_creative_to_slides() below, which resolves it from the
// creative JSON's "layout" field (a separate axis from "template", the
// color palette selector). Reaching 10-20 distinct-looking templates
// without writing 10-20x the rendering code means composing them from a
// small set of building blocks rather than bespoke code per template:
// - legacyBase: which of the 3 original layouts (classic/minimal/bold)
//   this preset inherits its bar weight, headline-rule style, body
//   boxing, and footer treatment from — those 4 things already have
//   exactly 3 well-tested shapes, so new templates just pick one rather
//   than re-deriving a 4th/5th/6th variant of each.
// - font/emphasis/decoration/listOverride/ctaOverride: the newer,
//   independent axes (serif vs sans, colored **word** headline spans,
//   a corner decoration, and optional list/CTA style swaps) that give
//   each preset its distinct identity on top of its legacyBase.
// classic/minimal/bold keep the exact field values that reproduce their
// pre-gallery behavior byte-for-byte.
function render_design_templates(): array
{
    return [
        'classic' => ['name' => 'Classic', 'legacyBase' => 'classic', 'font' => 'sans', 'emphasis' => false, 'decoration' => null, 'listOverride' => null, 'ctaOverride' => null],
        'minimal' => ['name' => 'Minimal', 'legacyBase' => 'minimal', 'font' => 'sans', 'emphasis' => false, 'decoration' => null, 'listOverride' => null, 'ctaOverride' => null],
        'bold'    => ['name' => 'Bold Blocks', 'legacyBase' => 'bold', 'font' => 'sans', 'emphasis' => false, 'decoration' => null, 'listOverride' => null, 'ctaOverride' => null],

        'editorial_serif'    => ['name' => 'Editorial Serif', 'legacyBase' => 'minimal', 'font' => 'serif', 'emphasis' => true, 'decoration' => null, 'listOverride' => 'divider', 'ctaOverride' => null],
        'serif_spotlight'    => ['name' => 'Serif Spotlight', 'legacyBase' => 'classic', 'font' => 'serif', 'emphasis' => true, 'decoration' => 'bleed_circle', 'listOverride' => null, 'ctaOverride' => null],
        'halftone_pop'       => ['name' => 'Halftone Pop', 'legacyBase' => 'classic', 'font' => 'sans', 'emphasis' => true, 'decoration' => 'halftone', 'listOverride' => 'divider', 'ctaOverride' => 'pill'],
        'corner_accent'      => ['name' => 'Corner Accent', 'legacyBase' => 'classic', 'font' => 'sans', 'emphasis' => false, 'decoration' => 'triangles', 'listOverride' => 'divider', 'ctaOverride' => 'outline'],
        'bold_serif'         => ['name' => 'Bold Serif', 'legacyBase' => 'bold', 'font' => 'serif', 'emphasis' => true, 'decoration' => null, 'listOverride' => null, 'ctaOverride' => null],
        'pill_editorial'     => ['name' => 'Pill Editorial', 'legacyBase' => 'minimal', 'font' => 'serif', 'emphasis' => false, 'decoration' => null, 'listOverride' => null, 'ctaOverride' => 'pill'],
        'spotlight_bold'     => ['name' => 'Spotlight Bold', 'legacyBase' => 'bold', 'font' => 'sans', 'emphasis' => false, 'decoration' => 'bleed_circle', 'listOverride' => null, 'ctaOverride' => null],
        'clean_divider'      => ['name' => 'Clean Divider', 'legacyBase' => 'classic', 'font' => 'sans', 'emphasis' => false, 'decoration' => null, 'listOverride' => 'divider', 'ctaOverride' => 'outline'],
        'outline_frame'      => ['name' => 'Outline Frame', 'legacyBase' => 'classic', 'font' => 'sans', 'emphasis' => false, 'decoration' => 'triangles', 'listOverride' => null, 'ctaOverride' => 'outline'],
        'halftone_editorial' => ['name' => 'Halftone Editorial', 'legacyBase' => 'minimal', 'font' => 'serif', 'emphasis' => true, 'decoration' => 'halftone', 'listOverride' => null, 'ctaOverride' => null],
        'dotted_bold'        => ['name' => 'Dotted Bold', 'legacyBase' => 'bold', 'font' => 'sans', 'emphasis' => true, 'decoration' => 'halftone', 'listOverride' => null, 'ctaOverride' => null],
    ];
}

// Resolves a preset id into its full computed form — barStyle/listStyle/
// ctaStyle/footerStyle are what actually get passed to the existing
// render_draw_bar()/render_headline_rule()/render_numbered_card()/
// render_cta_banner()/render_footer_*() functions, which is what lets
// this whole gallery reuse those functions unchanged (they only ever
// see one of the handful of style-key strings they already understood
// before this gallery existed, plus 'divider'/'pill'/'outline').
function render_resolve_design_preset(string $id): array
{
    $templates = render_design_templates();
    $preset = $templates[$id] ?? $templates['classic'];
    $preset['barStyle'] = $preset['legacyBase'];
    $preset['listStyle'] = $preset['listOverride'] ?? $preset['legacyBase'];
    $preset['ctaStyle'] = $preset['ctaOverride'] ?? $preset['legacyBase'];
    return $preset;
}

// ── Slide renderers ──────────────────────────────────────────────────

function render_slide_hook($im, array $slide, int $total, array $p, string $name, string $seriesLabel = '', string $layout = 'classic', string $footerFontRole = 'body', ?string $logoPath = null, ?array $footerNameColorRgb = null, ?int $footerNameSizeOverride = null): void
{
    $preset = render_resolve_design_preset($layout);
    [$cx, , $cw] = render_content_edges();
    render_draw_decoration($im, $p, $preset['decoration']);
    render_draw_bar($im, $p, $preset['barStyle']);
    render_draw_counter($im, 1, $total, $p);

    $y = render_draw_logo($im, $logoPath, $cx, RENDER_PAD + rs(12));
    if ($seriesLabel !== '') {
        render_text($im, $cx, $y, strtoupper($seriesLabel), rs(16), false, $p['counter']);
        $y += render_lh(rs(16)) + rs(8);
    }

    if (render_is_title_only($slide)) {
        render_draw_headline_centered($im, $slide['headline'] ?? '', $cx, $cw, $y, rs(650), [rs(110), rs(96), rs(84), rs(72), rs(60)], 3, $p, $preset);
        render_footer_simple($im, rs(650), $p, $name, $preset['barStyle'], $footerFontRole, $footerNameColorRgb, $footerNameSizeOverride);
        return;
    }

    $y = render_draw_headline($im, $slide['headline'] ?? '', $cx, $cw, $y, [rs(78), rs(68), rs(58), rs(50), rs(44)], 2, $p, $preset);

    $y = render_headline_rule($im, $y, $p, $preset['barStyle']);

    $body = $slide['body'] ?? '';
    $y = $preset['barStyle'] === 'minimal'
        ? render_body_freestanding($im, $body, $y, $p, $cx, $cw)
        : render_body_boxed($im, $body, $y, $p, $cx, $cw);

    render_footer_simple($im, $y, $p, $name, $preset['barStyle'], $footerFontRole, $footerNameColorRgb, $footerNameSizeOverride);
}

function render_slide_content($im, array $slide, int $total, array $p, string $name, string $layout = 'classic', string $footerFontRole = 'body', ?string $logoPath = null, ?array $footerNameColorRgb = null, ?int $footerNameSizeOverride = null): void
{
    $preset = render_resolve_design_preset($layout);
    [$cx, , $cw] = render_content_edges();
    render_draw_decoration($im, $p, $preset['decoration']);
    render_draw_bar($im, $p, $preset['barStyle']);
    render_draw_counter($im, (int) $slide['slide_number'], $total, $p);

    $y = render_draw_logo($im, $logoPath, $cx, RENDER_PAD + rs(12));

    if (render_is_title_only($slide)) {
        render_draw_headline_centered($im, $slide['headline'] ?? '', $cx, $cw, $y, rs(650), [rs(110), rs(96), rs(84), rs(72), rs(60)], 3, $p, $preset);
        render_footer_simple($im, rs(650), $p, $name, $preset['barStyle'], $footerFontRole, $footerNameColorRgb, $footerNameSizeOverride);
        return;
    }

    $y = render_draw_headline($im, $slide['headline'] ?? '', $cx, $cw, $y, [rs(54), rs(48), rs(42), rs(38), rs(34)], 2, $p, $preset);

    $y = render_headline_rule($im, $y, $p, $preset['barStyle']);

    $body = $slide['body'] ?? '';
    $y = $preset['barStyle'] === 'bold'
        ? render_body_boxed($im, $body, $y, $p, $cx, $cw)
        : render_body_freestanding($im, $body, $y, $p, $cx, $cw);
    if ($body !== '') {
        $y += rs(22);
    }

    // Defensively cap at exactly 3 regardless of what the AI/user
    // supplied — the prompt already asks for exactly 3, but nothing
    // downstream should trust an LLM (or a manual edit) to actually
    // honor that; this is what keeps the fit-size ceiling below honest.
    $points = array_slice($slide['points'] ?? [], 0, 3);
    $cardSize = render_fit_font_size($points, $y, rs(894), [rs(26), rs(23), rs(20), rs(18)], fn ($item, $size) => render_numbered_card_height($item, $size, $preset['listStyle']));
    foreach ($points as $i => $point) {
        $y = render_numbered_card($im, $i + 1, $point, $y, $p, $cardSize, $preset['listStyle']);
    }

    render_footer_simple($im, $y, $p, $name, $preset['barStyle'], $footerFontRole, $footerNameColorRgb, $footerNameSizeOverride);
}

function render_slide_cta($im, array $slide, int $total, array $p, string $name, ?string $photoPath, string $layout = 'classic', string $footerFontRole = 'body', ?string $logoPath = null, ?array $footerNameColorRgb = null, ?int $footerNameSizeOverride = null): void
{
    $preset = render_resolve_design_preset($layout);
    [$cx, , $cw] = render_content_edges();
    render_draw_decoration($im, $p, $preset['decoration']);
    render_draw_bar($im, $p, $preset['barStyle']);
    render_draw_counter($im, (int) $slide['slide_number'], $total, $p);

    $y = render_draw_logo($im, $logoPath, $cx, RENDER_PAD + rs(12));

    if (render_is_title_only($slide)) {
        render_draw_headline_centered($im, $slide['headline'] ?? '', $cx, $cw, $y, rs(650), [rs(110), rs(96), rs(84), rs(72), rs(60)], 3, $p, $preset);
        render_footer_with_photo($im, rs(650), $p, $name, $photoPath, $preset['barStyle'], $footerFontRole, $footerNameColorRgb, $footerNameSizeOverride);
        return;
    }

    $y = render_draw_headline($im, $slide['headline'] ?? '', $cx, $cw, $y, [rs(52), rs(46), rs(40), rs(36), rs(32)], 2, $p, $preset);

    $y = render_headline_rule($im, $y, $p, $preset['barStyle']);

    $body = $slide['body'] ?? '';
    $y = $preset['barStyle'] === 'bold'
        ? render_body_boxed($im, $body, $y, $p, $cx, $cw)
        : render_body_freestanding($im, $body, $y, $p, $cx, $cw);
    if ($body !== '') {
        $y += rs(22);
    }

    // CTA is spec'd as a single "Exact CTA line" — cap defensively to 1
    // for the same reason Content/Single cap points to 3.
    $points = array_slice($slide['points'] ?? [], 0, 1);
    $bannerSize = render_fit_font_size($points, $y, rs(802), [rs(27), rs(24), rs(21), rs(19)], fn ($item, $size) => render_cta_banner_height($item, $size, $preset['ctaStyle']));
    foreach ($points as $point) {
        $y = render_cta_banner($im, $point, $y, $p, $bannerSize, $preset['ctaStyle']);
    }

    render_footer_with_photo($im, $y, $p, $name, $photoPath, $preset['barStyle'], $footerFontRole, $footerNameColorRgb, $footerNameSizeOverride);
}

function render_slide_single($im, array $data, array $p, string $name, string $layout = 'classic', string $footerFontRole = 'body', ?string $logoPath = null, ?array $footerNameColorRgb = null, ?int $footerNameSizeOverride = null): void
{
    $preset = render_resolve_design_preset($layout);
    [$cx, , $cw] = render_content_edges();
    render_draw_decoration($im, $p, $preset['decoration']);
    render_draw_bar($im, $p, $preset['barStyle']);
    $slide = $data['slides'][0];

    $y = render_draw_logo($im, $logoPath, $cx, RENDER_PAD + rs(12));

    if (render_is_title_only($slide)) {
        render_draw_headline_centered($im, $slide['headline'] ?? '', $cx, $cw, $y, rs(650), [rs(110), rs(96), rs(84), rs(72), rs(60)], 3, $p, $preset);
        render_footer_simple($im, rs(650), $p, $name, $preset['barStyle'], $footerFontRole, $footerNameColorRgb, $footerNameSizeOverride);
        return;
    }

    $y = render_draw_headline($im, $slide['headline'] ?? '', $cx, $cw, $y, [rs(68), rs(60), rs(52), rs(46), rs(40)], 3, $p, $preset);

    $y = render_headline_rule($im, $y, $p, $preset['barStyle']);

    // Boxed in classic (Single shares Hook's spec row) and bold (always
    // boxed); freestanding only in minimal.
    $body = $slide['body'] ?? '';
    $y = $preset['barStyle'] === 'minimal'
        ? render_body_freestanding($im, $body, $y, $p, $cx, $cw)
        : render_body_boxed($im, $body, $y, $p, $cx, $cw);
    if ($body !== '') {
        $y += rs(22);
    }

    $points = array_slice($slide['points'] ?? [], 0, 3);
    $cardSize = render_fit_font_size($points, $y, rs(894), [rs(26), rs(23), rs(20), rs(18)], fn ($item, $size) => render_numbered_card_height($item, $size, $preset['listStyle']));
    foreach ($points as $i => $point) {
        $y = render_numbered_card($im, $i + 1, $point, $y, $p, $cardSize, $preset['listStyle']);
    }

    render_footer_simple($im, $y, $p, $name, $preset['barStyle'], $footerFontRole, $footerNameColorRgb, $footerNameSizeOverride);
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

    // An independent "Signature" font (fetch_footer_font()) takes priority
    // over the Heading/Body toggle (get_footer_font_role()) when the user
    // has assigned one — see includes/post_helpers.php fetch_footer_font().
    $footerFontRole = $userId ? get_footer_font_role($userId) : 'body';
    $footerFont = $userId ? fetch_footer_font($userId) : null;
    if ($footerFont) {
        render_font_override_role('footer', ['regular' => $footerFont['regular_path'], 'bold' => $footerFont['bold_path']], true);
        $footerFontRole = 'footer';
    }
    $footerNameSizeOverride = $userId ? get_footer_name_size($userId) : null;

    $paletteColors = render_resolve_palette_colors($data['template'] ?? null, $userId, $data['series_label'] ?? null);
    // Signature color is a per-palette override (see pages/settings.php
    // Brand Palettes' Signature field), not a global one — it only comes
    // through here if the resolved palette actually set it, so it
    // switches along with whatever palette a post uses instead of
    // clashing with palettes that don't specify one.
    $footerNameColorRgb = $paletteColors['signature'] ?? null;
    $layout = array_key_exists($data['layout'] ?? '', render_design_templates()) ? $data['layout'] : 'classic';
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
        render_slide_single($im, $data, $p, $footerName, $layout, $footerFontRole, $logoPath, $footerNameColorRgb, $footerNameSizeOverride);
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
            render_slide_hook($im, $slide, $total, $p, $footerName, $data['series_label'] ?? '', $layout, $footerFontRole, $logoPath, $footerNameColorRgb, $footerNameSizeOverride);
        } elseif ($n === $total) {
            render_slide_cta($im, $slide, $total, $p, $footerName, $photoPath, $layout, $footerFontRole, $logoPath, $footerNameColorRgb, $footerNameSizeOverride);
        } else {
            render_slide_content($im, $slide, $total, $p, $footerName, $layout, $footerFontRole, $logoPath, $footerNameColorRgb, $footerNameSizeOverride);
        }

        $filename = sprintf('slide_%02d.png', $n);
        $path = $outDir . '/' . $filename;
        imagepng($im, $path);
        imagedestroy($im);
        $result[] = ['filename' => $filename, 'filepath' => $path];
    }

    return $result;
}
