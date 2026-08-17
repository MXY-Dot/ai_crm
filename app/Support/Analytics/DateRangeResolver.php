<?php

namespace App\Support\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Shared day/week/month range + hour-or-day bucketing used by every analytics
 * controller (originally lived only inside SuperAdminAnalyticsController).
 */
class DateRangeResolver
{
    private const RANGES = ['day', 'week', 'month'];

    /** @return array{0: Carbon, 1: Carbon, 2: 'hour'|'day'} */
    public function resolve(Request $request): array
    {
        $range = in_array($request->query('range'), self::RANGES, true) ? $request->query('range') : 'month';
        $anchor = $request->query('date') ? Carbon::parse($request->query('date')) : now();

        return match ($range) {
            'day' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay(), 'hour'],
            'week' => [$anchor->copy()->startOfWeek(), $anchor->copy()->endOfWeek(), 'day'],
            default => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth(), 'day'],
        };
    }

    /**
     * Buckets a set of {date column value → count/sum} rows (keyed by hour-of-day
     * or Y-m-d, matching $bucket) into a complete, gap-free series across
     * [$start, $end] — hours 0-23 for 'hour', one point per calendar day for 'day'.
     *
     * @param array<int|string, float> $valuesByBucketKey
     * @return array{date: string, label: string, value: float}[]
     */
    public function fillSeries(Carbon $start, Carbon $end, string $bucket, array $valuesByBucketKey): array
    {
        if ($bucket === 'hour') {
            return collect(range(0, 23))->map(fn (int $hour) => [
                'date' => $start->copy()->setTime($hour, 0)->toIso8601String(),
                'label' => sprintf('%02d:00', $hour),
                'value' => (float) ($valuesByBucketKey[$hour] ?? 0),
            ])->values()->all();
        }

        $points = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $key = $cursor->format('Y-m-d');
            $points[] = ['date' => $key, 'label' => $cursor->format('d.m'), 'value' => (float) ($valuesByBucketKey[$key] ?? 0)];
        }

        return $points;
    }
}
