<script setup lang="ts">
import { computed } from 'vue';
import { useLocaleStore } from '../../../stores/locale';
import type { BookingRow } from './BookingCalendarGrid.vue';

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

function isToday(date: string): boolean {
    return date === toLocalDateString(new Date());
}
</script>

<template>
    <div class="grid grid-cols-1 gap-2 rounded-xl border border-border p-2 sm:grid-cols-7">
        <div v-for="date in days" :key="date" class="flex min-h-[10rem] flex-col gap-1.5 rounded-lg p-2" :class="isToday(date) ? 'bg-accent/40' : ''">
            <button type="button" class="text-left text-xs font-medium ui-text hover:underline" @click="emit('select-day', date)">
                {{ new Date(date + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }) }}
            </button>
            <button
                v-for="booking in dayBookings(date)"
                :key="booking.id"
                type="button"
                class="flex items-start gap-1.5 rounded-md border border-border px-1.5 py-1 text-left text-[11px] leading-tight hover:bg-accent/40"
                @click="emit('open', booking.id)"
            >
                <span class="mt-1 size-1.5 shrink-0 rounded-full" :class="STATUS_DOT[booking.status] ?? 'bg-muted-foreground'" />
                <span class="min-w-0">
                    <span class="block truncate font-medium ui-text">{{ time(booking.starts_at) }} · {{ booking.customer?.name ?? '—' }}</span>
                    <span class="block truncate ui-subtle">{{ booking.service?.name }} · {{ employeeName(booking.employee_id) }}</span>
                </span>
            </button>
            <p v-if="! dayBookings(date).length" class="text-[11px] ui-subtle">{{ locale.t('booking.noBookingsDay') }}</p>
        </div>
    </div>
</template>
