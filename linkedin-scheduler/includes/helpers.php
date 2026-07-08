<?php

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
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
