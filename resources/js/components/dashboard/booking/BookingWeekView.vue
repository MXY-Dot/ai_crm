<script setup lang="ts">
import { computed } from 'vue';
import { STATUS_DOTS } from '../../../lib/bookingStatus';
import { useLocaleStore } from '../../../stores/locale';
import type { BookingRow } from './BookingCalendarGrid.vue';

const props = defineProps<{ weekStart: string; employees: Array<{ id: number; name: string }>; bookings: BookingRow[] }>();
const emit = defineEmits<{ 'select-day': [date: string]; open: [bookingId: number] }>();
const locale = useLocaleStore();

function employeeName(id: number): string {
    return props.employees.find((e) => e.id === id)?.name ?? '';
}

// Deliberately local-calendar extraction, not UTC string-slicing -- see
// BookingCalendarPage.vue's toLocalDateString() docblock for why: a booking
// stored/serialized in UTC can fall on the previous UTC day even though it's
// still "today" for a customer/staff member in a timezone ahead of UTC (e.g.
// Asia/Dushanbe, UTC+5) -- slicing the raw string would put it on the wrong
// day in this week grid.
function toLocalDateString(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const days = computed(() => Array.from({ length: 7 }, (_, i) => {
    const d = new Date(props.weekStart + 'T00:00:00');
    d.setDate(d.getDate() + i);
    return toLocalDateString(d);
}));

function dayBookings(date: string): BookingRow[] {
    return props.bookings
        .filter((b) => toLocalDateString(new Date(b.starts_at)) === date)
        .sort((a, b) => a.starts_at.localeCompare(b.starts_at));
}

function time(iso: string): string {
    return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

function weekdayLabel(date: string): string {
    return new Date(date + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short' });
}

function monthLabel(date: string): string {
    return new Date(date + 'T00:00:00').toLocaleDateString(undefined, { month: 'short' });
}

function dayNumber(date: string): number {
    return new Date(date + 'T00:00:00').getDate();
}

function isToday(date: string): boolean {
    return date === toLocalDateString(new Date());
}
</script>

<template>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-7">
        <div
            v-for="date in days"
            :key="date"
            class="flex min-h-[11rem] flex-col gap-1.5 overflow-hidden rounded-xl bg-card p-2.5 shadow-sm ring-1 transition-shadow hover:shadow-md"
            :class="isToday(date) ? 'ring-primary/40' : 'ring-foreground/10'"
        >
            <button type="button" class="flex items-center justify-between gap-1 text-left hover:opacity-80" @click="emit('select-day', date)">
                <span class="text-[10.5px] font-medium uppercase tracking-wide ui-subtle">{{ weekdayLabel(date) }} · {{ monthLabel(date) }}</span>
                <span
                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                    :class="isToday(date) ? 'bg-primary text-primary-foreground' : 'ui-text'"
                >{{ dayNumber(date) }}</span>
            </button>

            <div class="flex flex-1 flex-col gap-1 overflow-y-auto">
                <button
                    v-for="booking in dayBookings(date)"
                    :key="booking.id"
                    type="button"
                    class="flex items-start gap-1.5 rounded-lg border border-border/60 bg-background/60 px-1.5 py-1 text-left text-[11px] leading-tight transition-colors hover:border-border hover:bg-accent/50"
                    @click="emit('open', booking.id)"
                >
                    <span class="mt-1 size-1.5 shrink-0 rounded-full" :class="STATUS_DOTS[booking.status] ?? 'bg-muted-foreground'" />
                    <span class="min-w-0">
                        <span class="block truncate font-medium ui-text">{{ time(booking.starts_at) }} · {{ booking.customer?.name ?? '—' }}</span>
                        <span class="block truncate ui-subtle">{{ booking.service?.name }} · {{ employeeName(booking.employee_id) }}</span>
                    </span>
                </button>
                <p v-if="! dayBookings(date).length" class="text-[11px] ui-subtle">{{ locale.t('booking.noBookingsDay') }}</p>
            </div>
        </div>
    </div>
</template>
