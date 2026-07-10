<?php

const ALL_POST_FORMATS = ['Single Image', 'Carousel', 'Text Post', 'Poll'];

// Poll is excluded from the default set — LinkedIn's Posts API (what
// this app uses to publish) has no endpoint for creating a real,
// votable poll, so "posting" a Poll-format row would only ever publish
// plain text under a misleading label. Users can still turn it on
// explicitly in Settings if they just want the text content out.
const DEFAULT_ENABLED_FORMATS = ['Single Image', 'Carousel', 'Text Post'];

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Accepts a bare numeric org ID ("12345"), a full URN
// ("urn:li:organization:12345"), or a LinkedIn company URL that uses
// the numeric ID form ("linkedin.com/company/12345/") — LinkedIn vanity
// names (e.g. "/company/microsoft/") can't be resolved to an ID without
// API access this app doesn't have, so those aren't accepted here.
// Returns null if no valid numeric ID could be extracted.
function normalize_organization_input(string $input): ?string
{
    $input = trim($input);
    if (preg_match('/^\d+$/', $input)) {
        return 'urn:li:organization:' . $input;
    }
    if (preg_match('/^urn:li:organization:(\d+)$/', $input, $m)) {
        return 'urn:li:organization:' . $m[1];
    }
    if (preg_match('#linkedin\.com/company/(\d+)#', $input, $m)) {
        return 'urn:li:organization:' . $m[1];
    }
    return null;
}

function get_enabled_formats(int $userId): array
{
    $stmt = db()->prepare('SELECT enabled_formats FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $raw = $stmt->fetchColumn();
    if (!$raw) {
        return DEFAULT_ENABLED_FORMATS;
    }
    $selected = array_map('trim', explode(',', $raw));
    return array_values(array_intersect(ALL_POST_FORMATS, $selected));
}

function set_enabled_formats(int $userId, array $formats): void
{
    $formats = array_values(array_intersect(ALL_POST_FORMATS, $formats));
    $stmt = db()->prepare('UPDATE users SET enabled_formats = ? WHERE id = ?');
    $stmt->execute([implode(',', $formats), $userId]);
}

function get_gemini_api_key(int $userId): ?string
{
    $stmt = db()->prepare('SELECT gemini_api_key FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $key = $stmt->fetchColumn();
    return $key ?: null;
}

function set_gemini_api_key(int $userId, ?string $key): void
{
    $key = trim((string) $key);
    $stmt = db()->prepare('UPDATE users SET gemini_api_key = ? WHERE id = ?');
    $stmt->execute([$key === '' ? null : $key, $userId]);
}

function redirect(string $path): void
{
    header('Location: ' . app_path($path));
    exit;
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function flash(string $key, ?string $message = null)
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $value;
}

// Builds a Mon–Sun month grid; $postsByDate is keyed by 'Y-m-d' with an
// ARRAY of posts per date (a day can have more than one scheduled post).
function build_calendar_grid(int $year, int $month, array $postsByDate): array
{
    $firstOfMonth = new DateTime("{$year}-{$month}-01");
    $daysInMonth  = (int) $firstOfMonth->format('t');
    $startWeekday = (int) $firstOfMonth->format('N'); // 1 (Mon) .. 7 (Sun)

    $grid = [];
    $week = array_fill(0, $startWeekday - 1, null);

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $week[] = ['day' => $day, 'date' => $date, 'posts' => $postsByDate[$date] ?? []];
        if (count($week) === 7) {
            $grid[] = $week;
            $week = [];
        }
    }
    if ($week) {
        while (count($week) < 7) {
            $week[] = null;
        }
        $grid[] = $week;
    }
    return $grid;
}
