<script setup lang="ts">
import { computed } from 'vue';
import { STATUS_DOTS } from '../../../lib/bookingStatus';
import type { BookingRow } from './BookingCalendarGrid.vue';

const props = defineProps<{ month: string; bookings: BookingRow[] }>();
const emit = defineEmits<{ 'select-day': [date: string] }>();

// Deliberately local-calendar extraction, not UTC string-slicing -- see
// BookingCalendarPage.vue's toLocalDateString() docblock: local midnight in a
// timezone ahead of UTC (e.g. Asia/Dushanbe, UTC+5) is still the previous day
// in UTC, so toISOString() silently shifts every date back by one.
function toIso(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// Monday-first grid, padded with the trailing days of the previous/next month
// so every visible row is a full 7-day week.
const gridStart = computed(() => {
    const first = new Date(props.month + '-01T00:00:00');
    const weekday = (first.getDay() + 6) % 7; // 0 = Monday
    first.setDate(first.getDate() - weekday);

    return first;
});

const weeks = computed(() => {
    const start = new Date(gridStart.value);
    const monthIndex = new Date(props.month + '-01T00:00:00').getMonth();
    const rows: { date: string; inMonth: boolean }[][] = [];

    for (let w = 0; w < 6; w++) {
        const row: { date: string; inMonth: boolean }[] = [];
        for (let d = 0; d < 7; d++) {
            const cell = new Date(start);
            cell.setDate(start.getDate() + w * 7 + d);
            row.push({ date: toIso(cell), inMonth: cell.getMonth() === monthIndex });
        }
        rows.push(row);
        // Stop after the row that still covers the target month, plus the row it started in.
        if (row.every((c) => ! c.inMonth) && w > 0) break;
    }

    return rows;
});

const weekdayLabels = computed(() => weeks.value[0].map((c) => new Date(c.date + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short' })));

function dayBookings(date: string): BookingRow[] {
    return props.bookings.filter((b) => toIso(new Date(b.starts_at)) === date);
}

function isToday(date: string): boolean {
    return date === toIso(new Date());
}

function dayNumber(date: string): number {
    return new Date(date + 'T00:00:00').getDate();
}
</script>

<template>
    <div class="overflow-hidden rounded-xl bg-card shadow-sm ring-1 ring-foreground/10">
        <div class="overflow-x-auto">
            <div class="grid min-w-[640px] grid-cols-7 text-xs">
                <div
                    v-for="label in weekdayLabels"
                    :key="label"
                    class="border-b border-border bg-background px-2 py-2 text-center text-[10.5px] font-semibold uppercase tracking-wide ui-subtle"
                >{{ label }}</div>

                <template v-for="w in weeks" :key="w[0].date">
                    <button
                        v-for="cell in w"
                        :key="cell.date"
                        type="button"
                        class="flex min-h-[6rem] flex-col gap-1.5 border-b border-r border-border p-2 text-left transition-colors hover:bg-accent/40"
                        :class="cell.inMonth ? '' : 'opacity-35'"
                        @click="emit('select-day', cell.date)"
                    >
                        <span
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold"
                            :class="isToday(cell.date) ? 'bg-primary text-primary-foreground' : 'ui-text'"
                        >{{ dayNumber(cell.date) }}</span>
                        <span class="flex flex-wrap gap-0.5">
                            <span v-for="b in dayBookings(cell.date).slice(0, 8)" :key="b.id" class="size-1.5 rounded-full" :class="STATUS_DOTS[b.status] ?? 'bg-muted-foreground'" />
                        </span>
                        <span v-if="dayBookings(cell.date).length" class="mt-auto text-[10px] font-medium ui-subtle">{{ dayBookings(cell.date).length }}</span>
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>
