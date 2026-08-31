<script setup lang="ts">
import { computed } from 'vue';
import type { BookingRow } from './BookingCalendarGrid.vue';

const props = defineProps<{ month: string; bookings: BookingRow[] }>();
const emit = defineEmits<{ 'select-day': [date: string] }>();

const STATUS_DOT: Record<string, string> = {
    temp_hold: 'bg-muted-foreground',
    awaiting_payment: 'bg-amber-500',
    payment_review: 'bg-orange-500',
    confirmed: 'bg-blue-500',
    client_arrived: 'bg-indigo-500',
    in_progress: 'bg-purple-500',
    completed: 'bg-emerald-500',
    rescheduled: 'bg-muted-foreground',
    cancelled: 'bg-destructive',
    no_show: 'bg-destructive',
};

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

function dayBookings(date: string): BookingRow[] {
    return props.bookings.filter((b) => toIso(new Date(b.starts_at)) === date);
}

function isToday(date: string): boolean {
    return date === toIso(new Date());
}
</script>

<template>
    <div class="overflow-x-auto rounded-xl border border-border">
        <div class="grid min-w-[640px] grid-cols-7 text-xs">
            <div v-for="w in weeks" :key="w[0].date" class="contents">
                <button
                    v-for="cell in w"
                    :key="cell.date"
                    type="button"
                    class="flex min-h-[5.5rem] flex-col gap-1 border-b border-r border-border p-1.5 text-left hover:bg-accent/40"
                    :class="[cell.inMonth ? '' : 'opacity-40', isToday(cell.date) ? 'bg-accent/30' : '']"
                    @click="emit('select-day', cell.date)"
                >
                    <span class="text-[11px] font-medium ui-text">{{ new Date(cell.date + 'T00:00:00').getDate() }}</span>
                    <span class="flex flex-wrap gap-0.5">
                        <span v-for="b in dayBookings(cell.date).slice(0, 8)" :key="b.id" class="size-1.5 rounded-full" :class="STATUS_DOT[b.status] ?? 'bg-muted-foreground'" />
                    </span>
                    <span v-if="dayBookings(cell.date).length" class="mt-auto text-[10px] ui-subtle">{{ dayBookings(cell.date).length }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
