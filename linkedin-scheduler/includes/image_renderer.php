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

// ── Layout primitives (direct ports of render.py) ───────────────────

function render_draw_bar($im, array $p): void
{
    imagefilledrectangle($im, 0, 0, RENDER_BAR, RENDER_SIZE, $p['bar']);
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

// Pure measurement, no drawing — mirrors render_numbered_card()'s height
// math exactly so render_fit_font_size() can pick a size before drawing.
function render_numbered_card_height(string $text, int $fontSize): float
{
    [, , $cw] = render_content_edges();
    $badge = 44; $px = 20; $py = 15;
    $lines = render_wrap($text, $fontSize, false, $cw - $badge - $px * 2 - 18);
    $ch = max($badge + $py * 2, count($lines) * render_lh($fontSize) + $py * 2);
    return $ch + 14; // + the gap render_numbered_card() adds below each card
}

// Pure measurement, no drawing — mirrors render_cta_banner()'s height math.
function render_cta_banner_height(string $text, int $fontSize): float
{
    [, , $cw] = render_content_edges();
    $aw = render_text_width('→  ', $fontSize, true);
    $lines = render_wrap($text, $fontSize, true, $cw - $aw - 40);
    return count($lines) * render_lh($fontSize) + 40 + 16; // + the gap below
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

function render_numbered_card($im, int $num, string $text, float $y, array $p, int $fontSize = 26): float
{
    [$cx, $rx, $cw] = render_content_edges();
    $fs = $fontSize; $badge = 44; $px = 20; $py = 15;
    $lines = render_wrap($text, $fs, false, $cw - $badge - $px * 2 - 18);
    $lineH = render_lh($fs);
    $textH = count($lines) * $lineH;
    $ch = max($badge + $py * 2, $textH + $py * 2);

    render_rrect($im, $cx, $y, $rx, $y + $ch, $p['accent'], 8);

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

function render_cta_banner($im, string $text, float $y, array $p, int $fontSize = 27): float
{
    [$cx, $rx, $cw] = render_content_edges();
    $fs = $fontSize;
    $lh = render_lh($fs);
    $aw = render_text_width('→  ', $fs, true);
    $lines = render_wrap($text, $fs, true, $cw - $aw - 40);
    $ph = count($lines) * $lh + 40;
    render_rrect($im, $cx, $y, $rx, $y + $ph, $p['cta_bg'], 10);
    $ty = $y + ($ph - count($lines) * $lh) / 2;
    render_text($im, $cx + 20, $ty, '→', $fs, true, $p['cta_text']);
    foreach ($lines as $i => $line) {
        render_text($im, $cx + 20 + $aw, $ty + $i * $lh, $line, $fs, true, $p['cta_text']);
    }
    return $y + $ph + 16;
}

// clamp(800, y+50, 944) per the design spec — content-length budgets
// (exactly 3 points, word-count limits, render_fit_headline_size() /
// render_fit_font_size() auto-shrink) are what keep content from ever
// reaching this ceiling; the footer itself just trusts that and holds
// its documented position.
function render_footer_simple($im, float $contentY, array $p, string $name): void
{
    [$cx, $rx] = render_content_edges();
    $fy = max(800, min($contentY + 50, RENDER_SIZE - RENDER_PAD - 56));
    imagefilledrectangle($im, (int) $cx, (int) $fy, (int) $rx, (int) $fy + 2, $p['divider']);
    render_text($im, $cx, $fy + 12, $name, 22, true, $p['name']);
}

function render_footer_with_photo($im, float $contentY, array $p, string $name, ?string $photoPath): void
{
    [$cx, $rx] = render_content_edges();
    $fy = max(720, min($contentY + 50, RENDER_SIZE - RENDER_PAD - 148));
    imagefilledrectangle($im, (int) $cx, (int) $fy, (int) $rx, (int) $fy + 2, $p['divider']);
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
        render_text($im, $nx, $ny, $name, 28, true, $p['headline']);
        render_text($im, $nx, $ny + $nfh, 'Follow for more insights', 20, false, $p['body']);
    } else {
        render_text($im, $cx, $py + 10, $name, 28, true, $p['headline']);
    }
}

// ── Slide renderers ──────────────────────────────────────────────────

function render_slide_hook($im, array $slide, int $total, array $p, string $name, string $seriesLabel = ''): void
{
    [$cx, , $cw] = render_content_edges();
    render_draw_bar($im, $p);
    render_draw_counter($im, 1, $total, $p);

    $y = RENDER_PAD + 12;
    if ($seriesLabel !== '') {
        render_text($im, $cx, $y, strtoupper($seriesLabel), 16, false, $p['counter']);
        $y += render_lh(16) + 8;
    }
    $hs = render_fit_headline_size($slide['headline'] ?? '', $cw, [78, 68, 58, 50, 44], 2);
    $lh = render_lh($hs);
    foreach (render_wrap($slide['headline'] ?? '', $hs, true, $cw, 'heading') as $line) {
        render_text($im, $cx, $y, $line, $hs, true, $p['headline'], 'heading');
        $y += $lh;
    }

    $y = render_draw_rule($im, $y, $p);

    $body = $slide['body'] ?? '';
    if ($body !== '') {
        $blh = render_lh(27);
        $lines = render_wrap($body, 27, false, $cw - 24);
        $ph = count($lines) * $blh + 28;
        render_rrect($im, $cx, $y, $cx + $cw, $y + $ph, $p['accent'], 10);
        $ty = $y + 14;
        foreach ($lines as $line) {
            render_text($im, $cx + 22, $ty, $line, 27, false, $p['accent_text']);
            $ty += $blh;
        }
        $y += $ph;
    }

    render_footer_simple($im, $y, $p, $name);
}

function render_slide_content($im, array $slide, int $total, array $p, string $name): void
{
    [$cx, , $cw] = render_content_edges();
    render_draw_bar($im, $p);
    render_draw_counter($im, (int) $slide['slide_number'], $total, $p);

    $y = RENDER_PAD + 12;
    $hs = render_fit_headline_size($slide['headline'] ?? '', $cw, [54, 48, 42, 38, 34], 2);
    $lh = render_lh($hs);
    foreach (render_wrap($slide['headline'] ?? '', $hs, true, $cw, 'heading') as $line) {
        render_text($im, $cx, $y, $line, $hs, true, $p['headline'], 'heading');
        $y += $lh;
    }

    $y = render_draw_rule($im, $y, $p);

    $body = $slide['body'] ?? '';
    if ($body !== '') {
        $bs = render_fit_headline_size($body, $cw, [26, 23, 20], 3, false);
        $blh = render_lh($bs);
        foreach (render_wrap($body, $bs, false, $cw) as $line) {
            render_text($im, $cx, $y, $line, $bs, false, $p['body']);
            $y += $blh;
        }
        $y += 22;
    }

    $points = $slide['points'] ?? [];
    $cardSize = render_fit_font_size($points, $y, 894, [26, 23, 20, 18], 'render_numbered_card_height');
    foreach ($points as $i => $point) {
        $y = render_numbered_card($im, $i + 1, $point, $y, $p, $cardSize);
    }

    render_footer_simple($im, $y, $p, $name);
}

function render_slide_cta($im, array $slide, int $total, array $p, string $name, ?string $photoPath): void
{
    [$cx, , $cw] = render_content_edges();
    render_draw_bar($im, $p);
    render_draw_counter($im, (int) $slide['slide_number'], $total, $p);

    $y = RENDER_PAD + 12;
    $hs = render_fit_headline_size($slide['headline'] ?? '', $cw, [52, 46, 40, 36, 32], 2);
    $lh = render_lh($hs);
    foreach (render_wrap($slide['headline'] ?? '', $hs, true, $cw, 'heading') as $line) {
        render_text($im, $cx, $y, $line, $hs, true, $p['headline'], 'heading');
        $y += $lh;
    }

    $y = render_draw_rule($im, $y, $p);

    $body = $slide['body'] ?? '';
    if ($body !== '') {
        $blh = render_lh(26);
        foreach (render_wrap($body, 26, false, $cw) as $line) {
            render_text($im, $cx, $y, $line, 26, false, $p['body']);
            $y += $blh;
        }
        $y += 22;
    }

    $points = $slide['points'] ?? [];
    $bannerSize = render_fit_font_size($points, $y, 802, [27, 24, 21, 19], 'render_cta_banner_height');
    foreach ($points as $point) {
        $y = render_cta_banner($im, $point, $y, $p, $bannerSize);
    }

    render_footer_with_photo($im, $y, $p, $name, $photoPath);
}

function render_slide_single($im, array $data, array $p, string $name): void
{
    [$cx, , $cw] = render_content_edges();
    render_draw_bar($im, $p);
    $slide = $data['slides'][0];

    $y = RENDER_PAD + 12;
    $hs = render_fit_headline_size($slide['headline'] ?? '', $cw, [68, 60, 52, 46, 40], 3);
    $lh = render_lh($hs);
    foreach (render_wrap($slide['headline'] ?? '', $hs, true, $cw, 'heading') as $line) {
        render_text($im, $cx, $y, $line, $hs, true, $p['headline'], 'heading');
        $y += $lh;
    }

    $y = render_draw_rule($im, $y, $p);

    // Boxed in the accent color, same treatment as render_slide_hook()'s
    // body — Single Image shares that spec row, not Content/CTA's
    // freestanding one.
    $body = $slide['body'] ?? '';
    if ($body !== '') {
        $blh = render_lh(27);
        $lines = render_wrap($body, 27, false, $cw - 24);
        $ph = count($lines) * $blh + 28;
        render_rrect($im, $cx, $y, $cx + $cw, $y + $ph, $p['accent'], 10);
        $ty = $y + 14;
        foreach ($lines as $line) {
            render_text($im, $cx + 22, $ty, $line, 27, false, $p['accent_text']);
            $ty += $blh;
        }
        $y += $ph + 22;
    }

    $points = $slide['points'] ?? [];
    $cardSize = render_fit_font_size($points, $y, 894, [26, 23, 20, 18], 'render_numbered_card_height');
    foreach ($points as $i => $point) {
        $y = render_numbered_card($im, $i + 1, $point, $y, $p, $cardSize);
    }

    render_footer_simple($im, $y, $p, $name);
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

    $paletteColors = render_resolve_palette_colors($data['template'] ?? null, $userId, $data['series_label'] ?? null);
    $slides = $data['slides'] ?? [];
    $total = count($slides);
    if ($total === 0) {
        throw new RuntimeException('No slides to render.');
    }
    $isSingle = ($data['format'] ?? '') === 'single';

    $result = [];
    if ($isSingle) {
        $im = imagecreatetruecolor(RENDER_SIZE, RENDER_SIZE);
        $p = render_allocate_palette_colors($im, $paletteColors);
        imagefilledrectangle($im, 0, 0, RENDER_SIZE, RENDER_SIZE, $p['bg']);
        render_slide_single($im, $data, $p, $footerName);
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
        $p = render_allocate_palette_colors($im, $paletteColors);
        imagefilledrectangle($im, 0, 0, RENDER_SIZE, RENDER_SIZE, $p['bg']);

        if ($n === 1) {
            render_slide_hook($im, $slide, $total, $p, $footerName, $data['series_label'] ?? '');
        } elseif ($n === $total) {
            render_slide_cta($im, $slide, $total, $p, $footerName, $photoPath);
        } else {
            render_slide_content($im, $slide, $total, $p, $footerName);
        }

        $filename = sprintf('slide_%02d.png', $n);
        $path = $outDir . '/' . $filename;
        imagepng($im, $path);
        imagedestroy($im);
        $result[] = ['filename' => $filename, 'filepath' => $path];
    }

    return $result;
}
