<?php
// Ported from the local Python prototype's content_loader.py — same
// column tolerance (Campaign_ID/Campign_ID typo), same date formats,
// same "Post Caption must be filled + Final_Format must be valid" skip
// rules, generalized here to feed the bulk CSV import wizard instead of
// a single day's row lookup.

const VALID_FORMATS = ['Single Image', 'Carousel', 'Text Post', 'Poll'];
const DATE_FORMATS = ['m/d/Y', 'd-M-Y', 'd-M-y', 'Y-m-d', 'm/d/y'];

function csv_read_text(string $path): string
{
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        throw new RuntimeException("Could not read CSV file: {$path}");
    }
    if (substr($bytes, 0, 3) === "\xEF\xBB\xBF") {
        return substr($bytes, 3);
    }
    if (mb_check_encoding($bytes, 'UTF-8')) {
        return $bytes;
    }
    $converted = @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    return $converted !== false ? $converted : $bytes;
}

function csv_load_all_rows(string $path): array
{
    // fgetcsv on a stream (rather than a naive line-split) is required
    // here because calendar captions routinely contain embedded newlines
    // inside quoted fields — splitting on "\n" first would break those
    // rows apart, same pitfall Python's csv.DictReader avoids natively.
    $text = csv_read_text($path);
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $text);
    rewind($stream);

    $header = null;
    $rows = [];
    while (($fields = fgetcsv($stream)) !== false) {
        if ($fields === [null]) {
            continue; // blank line
        }
        if ($header === null) {
            $header = array_map('trim', $fields);
            continue;
        }
        $row = [];
        foreach ($header as $i => $key) {
            $row[$key] = $fields[$i] ?? '';
        }
        $rows[] = $row;
    }
    fclose($stream);
    return $rows;
}

function csv_parse_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    foreach (DATE_FORMATS as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $raw);
        $errors = DateTime::getLastErrors();
        if ($d && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $d->format('Y-m-d');
        }
    }
    return null;
}

function csv_get_campaign_id(array $row): string
{
    return trim($row['Campaign_ID'] ?? $row['Campign_ID'] ?? '');
}

function csv_get_page_label(array $row): string
{
    return trim($row['LinkedIn Page'] ?? '');
}

function csv_get_title(array $row): string
{
    return trim($row['Topic / Title'] ?? $row['Topic/Title'] ?? '');
}

function csv_get_caption(array $row): string
{
    $cap   = trim($row['Post Caption'] ?? '');
    $title = csv_get_title($row);
    if ($title && $cap) {
        return $title . "\n\n" . $cap;
    }
    return $title ?: $cap;
}

// Parses a CSV file into the preview shape the import wizard needs:
// per-row data plus skip flags, and the distinct set of "LinkedIn Page"
// labels found (for the account-mapping step).
function csv_build_preview(string $path): array
{
    $rows = csv_load_all_rows($path);
    $preview = [];
    $labels = [];

    foreach ($rows as $row) {
        $campaignId = csv_get_campaign_id($row);
        $format     = trim($row['Final_Format'] ?? '');
        $rawCaption = trim($row['Post Caption'] ?? '');
        $pageLabel  = csv_get_page_label($row);
        $date       = csv_parse_date($row['Date'] ?? '');

        $skip = false;
        $skipReason = null;
        if ($campaignId === '') {
            $skip = true;
            $skipReason = 'Missing Campaign ID';
        } elseif ($rawCaption === '') {
            $skip = true;
            $skipReason = 'Post Caption is empty';
        } elseif (!in_array($format, VALID_FORMATS, true)) {
            $skip = true;
            $skipReason = 'Invalid Final_Format';
        }

        if ($pageLabel !== '') {
            $labels[$pageLabel] = true;
        }

        $preview[] = [
            'campaign_id' => $campaignId,
            'date'        => $date,
            'format'      => $format,
            'title'       => csv_get_title($row),
            'caption'     => csv_get_caption($row),
            'has_caption' => $rawCaption !== '',
            'page_label'  => $pageLabel,
            'skip'        => $skip,
            'skip_reason' => $skipReason,
        ];
    }

    return ['rows' => $preview, 'page_labels' => array_keys($labels)];
}
