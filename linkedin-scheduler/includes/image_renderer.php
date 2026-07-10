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

function render_get_palette_id(array $data): int
{
    $t = $data['template'] ?? null;
    if (is_int($t) && $t >= 1 && $t <= 4) {
        return $t;
    }
    $sl = strtolower($data['series_label'] ?? '');
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

// Allocates this palette's colors against a specific GD image resource —
// color indices are per-image in GD, unlike PIL's plain RGB tuples.
function render_allocate_palette($im, int $paletteId): array
{
    $palette = render_palettes()[$paletteId];
    $out = [];
    foreach ($palette as $key => [$r, $g, $b]) {
        $out[$key] = imagecolorallocate($im, $r, $g, $b);
    }
    $out['_id'] = $paletteId;
    return $out;
}

// ── Fonts ─────────────────────────────────────────────────────────────

function render_font_path(bool $bold): string
{
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

function render_text_width(string $text, int $size, bool $bold): float
{
    if ($text === '') {
        return 0.0;
    }
    $bbox = imagettfbbox($size, 0, render_font_path($bold), $text);
    return abs($bbox[2] - $bbox[0]);
}

function render_ascent(int $size, bool $bold): float
{
    static $cache = [];
    $key = ($bold ? 'b' : 'r') . $size;
    if (!isset($cache[$key])) {
        $bbox = imagettfbbox($size, 0, render_font_path($bold), 'Ágy');
        $cache[$key] = -$bbox[7];
    }
    return $cache[$key];
}

// Draws text with $topY as the TOP of the text (like PIL's draw.text),
// converting to GD's baseline-relative imagettftext() internally.
function render_text($im, float $x, float $topY, string $text, int $size, bool $bold, int $color): void
{
    if ($text === '') {
        return;
    }
    imagettftext($im, $size, 0, (int) round($x), (int) round($topY + render_ascent($size, $bold)), $color, render_font_path($bold), $text);
}

// ── Text utilities ───────────────────────────────────────────────────

function render_wrap(string $text, int $size, bool $bold, float $maxPx): array
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
        if ($current && render_text_width($test, $size, $bold) > $maxPx) {
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

function render_numbered_card($im, int $num, string $text, float $y, array $p): float
{
    [$cx, $rx, $cw] = render_content_edges();
    $fs = 26; $badge = 44; $px = 20; $py = 15;
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

function render_cta_banner($im, string $text, float $y, array $p): float
{
    [$cx, $rx, $cw] = render_content_edges();
    $fs = 27;
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

function render_slide_hook($im, array $slide, int $total, array $p, string $name): void
{
    [$cx, , $cw] = render_content_edges();
    render_draw_bar($im, $p);
    render_draw_counter($im, 1, $total, $p);

    $y = RENDER_PAD + 12;
    $lh = render_lh(78);
    foreach (render_wrap($slide['headline'] ?? '', 78, true, $cw) as $line) {
        render_text($im, $cx, $y, $line, 78, true, $p['headline']);
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
    $lh = render_lh(54);
    foreach (render_wrap($slide['headline'] ?? '', 54, true, $cw) as $line) {
        render_text($im, $cx, $y, $line, 54, true, $p['headline']);
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

    foreach (($slide['points'] ?? []) as $i => $point) {
        $y = render_numbered_card($im, $i + 1, $point, $y, $p);
    }

    render_footer_simple($im, $y, $p, $name);
}

function render_slide_cta($im, array $slide, int $total, array $p, string $name, ?string $photoPath): void
{
    [$cx, , $cw] = render_content_edges();
    render_draw_bar($im, $p);
    render_draw_counter($im, (int) $slide['slide_number'], $total, $p);

    $y = RENDER_PAD + 12;
    $lh = render_lh(52);
    foreach (render_wrap($slide['headline'] ?? '', 52, true, $cw) as $line) {
        render_text($im, $cx, $y, $line, 52, true, $p['headline']);
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

    foreach (($slide['points'] ?? []) as $point) {
        $y = render_cta_banner($im, $point, $y, $p);
    }

    render_footer_with_photo($im, $y, $p, $name, $photoPath);
}

function render_slide_single($im, array $data, array $p, string $name): void
{
    [$cx, , $cw] = render_content_edges();
    render_draw_bar($im, $p);
    $slide = $data['slides'][0];

    $y = RENDER_PAD + 12;
    $lh = render_lh(68);
    foreach (render_wrap($slide['headline'] ?? '', 68, true, $cw) as $line) {
        render_text($im, $cx, $y, $line, 68, true, $p['headline']);
        $y += $lh;
    }

    $y = render_draw_rule($im, $y, $p);

    $body = $slide['body'] ?? '';
    if ($body !== '') {
        $blh = render_lh(27);
        foreach (render_wrap($body, 27, false, $cw) as $line) {
            render_text($im, $cx, $y, $line, 27, false, $p['body']);
            $y += $blh;
        }
        $y += 22;
    }

    foreach (($slide['points'] ?? []) as $i => $point) {
        $y = render_numbered_card($im, $i + 1, $point, $y, $p);
    }

    render_footer_simple($im, $y, $p, $name);
}

// ── Main entry point ─────────────────────────────────────────────────

// Renders $data (the JSON shape from creative_builder.php / ai_generate.php)
// into $outDir/slide_01.png, slide_02.png, ... and returns the slide list
// in the same ['filename' => ..., 'filepath' => ...] shape
// includes/zip_import.php produces, so callers can insert post_slides
// rows identically either way.
function render_creative_to_slides(array $data, string $outDir, string $footerName, ?string $photoPath = null): array
{
    if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
        throw new RuntimeException("Could not create output directory: {$outDir}");
    }

    $paletteId = render_get_palette_id($data);
    $slides = $data['slides'] ?? [];
    $total = count($slides);
    if ($total === 0) {
        throw new RuntimeException('No slides to render.');
    }
    $isSingle = ($data['format'] ?? '') === 'single';

    $result = [];
    if ($isSingle) {
        $im = imagecreatetruecolor(RENDER_SIZE, RENDER_SIZE);
        $p = render_allocate_palette($im, $paletteId);
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
        $p = render_allocate_palette($im, $paletteId);
        imagefilledrectangle($im, 0, 0, RENDER_SIZE, RENDER_SIZE, $p['bg']);

        if ($n === 1) {
            render_slide_hook($im, $slide, $total, $p, $footerName);
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
