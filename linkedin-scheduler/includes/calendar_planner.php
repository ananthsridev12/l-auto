<?php
// Pure planning logic for the Content Calendar Generator — no DB writes,
// no AI calls, so this is fully unit-testable on its own. Callers
// (api/calendar_plan.php) take the returned plan and create the actual
// `posts` rows; content generation happens in a separate step
// (api/calendar_generate_one.php) so one slow/failed AI call can't block
// the whole plan.

// Largest-remainder (Hamilton) apportionment: turns percentage weights
// into exact integer counts that sum to $total, with no rounding drift.
// Works even if the weights don't sum to exactly 100 (normalizes first).
function apportion(array $weights, int $total): array
{
    $sum = array_sum($weights);
    if ($sum <= 0 || $total <= 0) {
        return array_fill_keys(array_keys($weights), 0);
    }

    $floors = [];
    $remainders = [];
    foreach ($weights as $key => $w) {
        $quota = ($w / $sum) * $total;
        $floors[$key] = (int) floor($quota);
        $remainders[$key] = $quota - $floors[$key];
    }

    $remaining = $total - array_sum($floors);
    arsort($remainders);
    $keys = array_keys($remainders);
    for ($i = 0; $i < $remaining && $i < count($keys); $i++) {
        $floors[$keys[$i]]++;
    }

    return $floors;
}

// Spreads $totalPosts dates as evenly as possible across $periodDays
// starting from $startDate (default: tomorrow). Generalizes the
// even-spacing idea pages/bulk_schedule.php's "spread" mode uses for
// always-one-per-day into N-posts-over-M-days.
function spread_dates(int $totalPosts, int $periodDays, ?string $startDate = null): array
{
    $startDate = $startDate ?: date('Y-m-d', strtotime('+1 day'));
    if ($totalPosts <= 0) {
        return [];
    }
    if ($totalPosts >= $periodDays) {
        $dates = [];
        for ($i = 0; $i < $totalPosts; $i++) {
            $dates[] = date('Y-m-d', strtotime($startDate . ' +' . ($i % $periodDays) . ' days'));
        }
        sort($dates);
        return $dates;
    }
    $dates = [];
    for ($i = 0; $i < $totalPosts; $i++) {
        $dayOffset = (int) floor($i * $periodDays / $totalPosts);
        $dates[] = date('Y-m-d', strtotime($startDate . " +{$dayOffset} days"));
    }
    return $dates;
}

// Equal-weight defaults for pre-filling the generator form.
function default_pillar_weights(array $pillars): array
{
    if (!$pillars) {
        return [];
    }
    $each = (int) floor(100 / count($pillars));
    $weights = [];
    foreach ($pillars as $p) {
        $weights[$p['id']] = $each;
    }
    // give the remainder to the first pillar so it still sums to 100
    $keys = array_keys($weights);
    $weights[$keys[0]] += 100 - array_sum($weights);
    return $weights;
}

function default_format_weights(array $enabledFormats): array
{
    if (!$enabledFormats) {
        return [];
    }
    $each = (int) floor(100 / count($enabledFormats));
    $weights = [];
    foreach ($enabledFormats as $fmt) {
        $weights[$fmt] = $each;
    }
    $keys = array_keys($weights);
    $weights[$keys[0]] += 100 - array_sum($weights);
    return $weights;
}

// $mixConfig: period_days (7/14/30), posts_per_week,
// pillar_weights ([pillar_id => percent]), format_weights ([format => percent]).
// Returns a flat list of planned slots: ['date','pillar_id','persona_id','format'],
// sorted by date. $pillars/$personas are the caller's already-fetched
// fetch_content_pillars()/fetch_personas() results (avoids re-querying).
function generate_calendar_plan(array $mixConfig, array $pillars, array $personas): array
{
    $periodDays = (int) $mixConfig['period_days'];
    $postsPerWeek = max(1, (int) $mixConfig['posts_per_week']);
    $pillarWeights = $mixConfig['pillar_weights'];
    $formatWeights = $mixConfig['format_weights'];

    $totalPosts = max(1, (int) round($postsPerWeek * $periodDays / 7));

    $pillarCounts = apportion($pillarWeights, $totalPosts);
    $formatCounts = apportion($formatWeights, $totalPosts);

    $pillarSlots = [];
    foreach ($pillarCounts as $pillarId => $n) {
        for ($i = 0; $i < $n; $i++) {
            $pillarSlots[] = $pillarId;
        }
    }
    shuffle($pillarSlots);

    $formatSlots = [];
    foreach ($formatCounts as $format => $n) {
        for ($i = 0; $i < $n; $i++) {
            $formatSlots[] = $format;
        }
    }
    shuffle($formatSlots);

    $dates = spread_dates($totalPosts, $periodDays);

    $pillarsById = array_column($pillars, null, 'id');
    $companyPersonaCursor = 0;

    $plan = [];
    for ($i = 0; $i < $totalPosts; $i++) {
        $pillarId = $pillarSlots[$i] ?? null;
        $pillar = $pillarId !== null ? ($pillarsById[$pillarId] ?? null) : null;
        $format = $formatSlots[$i] ?? array_key_first($formatWeights);

        $personaId = null;
        if ($pillar && ($pillar['category'] ?? 'company') === 'company' && $personas) {
            $personaId = $personas[$companyPersonaCursor % count($personas)]['id'];
            $companyPersonaCursor++;
        }

        $plan[] = [
            'date'       => $dates[$i],
            'pillar_id'  => $pillarId,
            'persona_id' => $personaId,
            'format'     => $format,
        ];
    }

    usort($plan, fn ($a, $b) => strcmp($a['date'], $b['date']));

    return $plan;
}
