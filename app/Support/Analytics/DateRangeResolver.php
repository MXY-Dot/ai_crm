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

    /** ТЗ «Отчётность...» раздел 13 — доп. пресеты поверх старого range/date контракта (тот остаётся дефолтным путём для существующих вызывающих). */
    private const PRESETS = ['today', 'yesterday', '7d', '30d', 'this_week', 'last_week', 'this_month', 'last_month', 'custom'];

    /** @return array{0: Carbon, 1: Carbon, 2: 'hour'|'day'} */
    public function resolve(Request $request): array
    {
        if (in_array($request->query('preset'), self::PRESETS, true)) {
            return $this->resolvePreset($request);
        }

        $range = in_array($request->query('range'), self::RANGES, true) ? $request->query('range') : 'month';
        $anchor = $request->query('date') ? Carbon::parse($request->query('date')) : now();

        return match ($range) {
            'day' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay(), 'hour'],
            'week' => [$anchor->copy()->startOfWeek(), $anchor->copy()->endOfWeek(), 'day'],
            default => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth(), 'day'],
        };
    }

    /** @return array{0: Carbon, 1: Carbon, 2: 'hour'|'day'} */
    private function resolvePreset(Request $request): array
    {
        $now = now();

        return match ($request->query('preset')) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'hour'],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay(), 'hour'],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay(), 'day'],
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay(), 'day'],
            'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek(), 'day'],
            'last_week' => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek(), 'day'],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'day'],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth(), 'day'],
            'custom' => $this->resolveCustom($request),
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'day'],
        };
    }

    /** @return array{0: Carbon, 1: Carbon, 2: 'hour'|'day'} */
    private function resolveCustom(Request $request): array
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : now()->startOfMonth();
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : now()->endOfDay();

        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $bucket = $from->diffInHours($to) <= 48 ? 'hour' : 'day';

        return [$from, $to, $bucket];
    }

    /**
     * Same-length period immediately preceding [$start, $end] — ТЗ раздел 21
     * "Сравнить период" (this week vs last week, last 30 days vs previous 30, etc).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function previousPeriod(Carbon $start, Carbon $end): array
    {
        $lengthSeconds = $end->diffInSeconds($start);

        return [$start->copy()->subSeconds($lengthSeconds + 1), $start->copy()->subSecond()];
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
