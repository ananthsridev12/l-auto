<?php
// Ported from the local Python prototype's csv_to_json.py — mechanically
// turns an already-written "Creative Content" cell into the same slide
// JSON shape includes/image_renderer.php expects. No AI call here; see
// includes/ai_generate.php for the fallback when Creative Content is
// blank.
//
// Creative Content syntax:
//   Single Image : Body | Point 1 | Point 2 | Point 3 | Point 4
//   Carousel     : Headline | Body ;; Headline | Body | P1 | P2 | P3 ;; ...

function creative_extract_hashtags(string $caption): array
{
    preg_match_all('/#\w+/u', $caption, $m);
    return $m[0];
}

function creative_series_label(array $row): string
{
    $pillar = trim($row['Pillar'] ?? '');
    $type   = trim($row['Type'] ?? '');
    if ($pillar === '' && $type === '') {
        return '';
    }
    return trim($pillar . '  ·  ' . $type, ' ·');
}

function build_creative_single(array $row, string $caption): array
{
    $creative = trim($row['Creative Content'] ?? '');
    $parts    = array_map('trim', explode('|', $creative));
    $body     = $parts[0] ?? '';
    $points   = array_values(array_filter(array_slice($parts, 1), fn ($p) => $p !== ''));
    $title    = trim($row['Topic / Title'] ?? $row['Topic/Title'] ?? '');

    return [
        'format'       => 'single',
        'title'        => $title,
        'series_label' => creative_series_label($row),
        'caption'      => $caption,
        'hashtags'     => creative_extract_hashtags($caption),
        'slides'       => [[
            'slide_number' => 1,
            'headline'     => $title,
            'body'         => $body,
            'points'       => $points,
        ]],
    ];
}

function build_creative_carousel(array $row, string $caption): array
{
    $creative  = trim($row['Creative Content'] ?? '');
    $slidesRaw = array_values(array_filter(array_map('trim', explode(';;', $creative)), fn ($s) => $s !== ''));
    $slides    = [];

    foreach ($slidesRaw as $i => $raw) {
        $parts    = array_map('trim', explode('|', $raw));
        $headline = $parts[0] ?? '';
        $body     = $parts[1] ?? '';
        $points   = array_values(array_filter(array_slice($parts, 2), fn ($p) => $p !== ''));
        $slides[] = [
            'slide_number' => $i + 1,
            'headline'     => $headline,
            'body'         => $body,
            'points'       => $points,
        ];
    }

    return [
        'format'       => 'carousel',
        'title'        => trim($row['Topic / Title'] ?? $row['Topic/Title'] ?? ''),
        'series_label' => creative_series_label($row),
        'caption'      => $caption,
        'hashtags'     => creative_extract_hashtags($caption),
        'slides'       => $slides,
    ];
}

// Returns null if Creative Content is blank — caller should fall back to
// includes/ai_generate.php in that case.
function build_creative_from_row(array $row): ?array
{
    $creative = trim($row['Creative Content'] ?? '');
    $caption  = trim($row['Post Caption'] ?? '');
    $format   = trim($row['Final_Format'] ?? '');

    if ($creative === '' || $caption === '') {
        return null;
    }

    return $format === 'Single Image'
        ? build_creative_single($row, $caption)
        : build_creative_carousel($row, $caption);
}
