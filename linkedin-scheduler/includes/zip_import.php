<?php
// Extracts a bulk media ZIP whose top-level folders are
// {campaign_id}/slide_01.png, slide_02.png, ... (matching the local
// output/{campaign_id}/ convention already used by the Python renderer)
// and returns slide paths grouped by campaign_id.

const ALLOWED_SLIDE_MIME = ['image/png', 'image/jpeg'];
const MAX_SLIDES_PER_CAMPAIGN = 20;

function zip_extract_campaign_slides(string $zipPath, string $destDir): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Could not open the uploaded ZIP file.');
    }

    $byCampaign = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (str_ends_with($name, '/') || str_starts_with(basename($name), '.')) {
            continue; // directory entry or dotfile (e.g. __MACOSX/, .DS_Store)
        }
        if (str_contains($name, '__MACOSX/')) {
            continue;
        }
        $parts = explode('/', trim($name, '/'));
        if (count($parts) < 2) {
            continue; // not inside a campaign subfolder, ignore
        }
        $campaignId = $parts[0];
        $filename = end($parts);
        if (!preg_match('/\.(png|jpe?g)$/i', $filename)) {
            continue;
        }
        $byCampaign[$campaignId][] = $i;
    }

    $result = [];
    foreach ($byCampaign as $campaignId => $indices) {
        if (count($indices) > MAX_SLIDES_PER_CAMPAIGN) {
            $zip->close();
            throw new RuntimeException("Campaign {$campaignId} has more than " . MAX_SLIDES_PER_CAMPAIGN . " slide files.");
        }
        $campaignDir = $destDir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $campaignId);
        if (!is_dir($campaignDir)) {
            mkdir($campaignDir, 0755, true);
        }

        $saved = [];
        foreach ($indices as $i) {
            $name = $zip->getNameIndex($i);
            $filename = basename($name);
            $contents = $zip->getFromIndex($i);
            if ($contents === false) {
                continue;
            }
            $mime = zip_sniff_image_mime($contents);
            if (!in_array($mime, ALLOWED_SLIDE_MIME, true)) {
                continue;
            }
            $safeName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename);
            $path = $campaignDir . '/' . $safeName;
            file_put_contents($path, $contents);
            $saved[] = ['filename' => $safeName, 'filepath' => $path];
        }
        natsort_slides($saved);
        if ($saved) {
            $result[$campaignId] = $saved;
        }
    }

    $zip->close();
    return $result;
}

function zip_sniff_image_mime(string $contents): ?string
{
    if (substr($contents, 0, 8) === "\x89PNG\r\n\x1a\n") {
        return 'image/png';
    }
    if (substr($contents, 0, 3) === "\xFF\xD8\xFF") {
        return 'image/jpeg';
    }
    return null;
}

function natsort_slides(array &$slides): void
{
    usort($slides, fn ($a, $b) => strnatcasecmp($a['filename'], $b['filename']));
}
