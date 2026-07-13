<?php
// CSV parsing for Content Studio — same tolerant loading as
// includes/csv_parser.php (BOM/encoding handling, embedded-newline-safe
// fgetcsv), but reads the extra creative-content columns
// (Pillar/Type/Target Persona/CTA/Tag Page/Creative Content) the plain
// import wizard doesn't need, and only cares about Single Image /
// Carousel rows since Text Post/Poll have no image to render.

const CONTENT_STUDIO_FORMATS = ['Single Image', 'Carousel'];

function content_studio_build_preview(string $path): array
{
    $rows = csv_load_all_rows($path);
    $preview = [];
    $labels = [];

    foreach ($rows as $row) {
        $campaignId = csv_get_campaign_id($row);
        $format     = trim($row['Final_Format'] ?? '');
        $pageLabel  = csv_get_page_label($row);
        $date       = csv_parse_date($row['Date'] ?? '');

        $normalized = [
            'Campaign_ID'      => $campaignId,
            'Topic / Title'    => csv_get_title($row),
            'Pillar'           => trim($row['Pillar'] ?? ''),
            'Type'             => trim($row['Type'] ?? ''),
            'Target Persona'   => trim($row['Target Persona'] ?? ''),
            'CTA'              => trim($row['CTA'] ?? ''),
            'Tag Page'         => trim($row['Tag Page'] ?? ''),
            'Post Caption'     => trim($row['Post Caption'] ?? ''),
            'Creative Content' => trim($row['Creative Content'] ?? ''),
            'Final_Format'     => $format,
            // Short/Medium/Long — only matters for AI-generated rows
            // (blank Creative Content); resolve_length_preset()
            // (includes/ai_generate.php) falls back to Medium for
            // anything blank/unrecognized, so this column is optional.
            'Content Length'   => trim($row['Content Length'] ?? ''),
        ];

        $skip = false;
        $skipReason = null;
        if ($campaignId === '') {
            $skip = true;
            $skipReason = 'Missing Campaign ID';
        } elseif (!in_array($format, CONTENT_STUDIO_FORMATS, true)) {
            $skip = true;
            $skipReason = 'Final_Format must be Single Image or Carousel (Text Post/Poll have no image to render)';
        } elseif ($normalized['Creative Content'] === '' && $normalized['Topic / Title'] === '') {
            $skip = true;
            $skipReason = 'Needs either Creative Content (pre-written) or a Topic / Title (for AI generation)';
        }

        if ($pageLabel !== '') {
            $labels[$pageLabel] = true;
        }

        $preview[] = [
            'campaign_id' => $campaignId,
            'date'        => $date,
            'format'      => $format,
            'page_label'  => $pageLabel,
            'skip'        => $skip,
            'skip_reason' => $skipReason,
            'row'         => $normalized,
        ];
    }

    return ['rows' => $preview, 'page_labels' => array_keys($labels)];
}
