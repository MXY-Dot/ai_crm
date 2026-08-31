<script setup lang="ts">
import { computed } from 'vue';
import { useLocaleStore } from '../../../stores/locale';

export type BookingRow = {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    employee_id: number;
    customer: { name: string } | null;
    service: { name: string } | null;
};

const props = defineProps<{ date: string; employees: Array<{ id: number; name: string }>; bookings: BookingRow[] }>();
const emit = defineEmits<{ 'create-at': [employeeId: number, iso: string]; open: [bookingId: number] }>();
const locale = useLocaleStore();

const START_HOUR = 8;
const END_HOUR = 21;
const SLOT_MINUTES = 15;
const SLOT_COUNT = ((END_HOUR - START_HOUR) * 60) / SLOT_MINUTES;

const STATUS_COLORS: Record<string, string> = {
    temp_hold: 'bg-muted text-muted-foreground',
    awaiting_payment: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    payment_review: 'bg-orange-500/15 text-orange-700 dark:text-orange-400',
    confirmed: 'bg-blue-500/15 text-blue-700 dark:text-blue-400',
    client_arrived: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-400',
    in_progress: 'bg-purple-500/15 text-purple-700 dark:text-purple-400',
    completed: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    rescheduled: 'bg-muted text-muted-foreground',
    cancelled: 'bg-destructive/10 text-destructive line-through',
    no_show: 'bg-destructive/10 text-destructive',
};

function slotStart(index: number): Date {
    const d = new Date(props.date + 'T00:00:00');
    d.setHours(START_HOUR, 0, 0, 0);
    d.setMinutes(d.getMinutes() + index * SLOT_MINUTES);
    return d;
}

const slots = computed(() => Array.from({ length: SLOT_COUNT }, (_, i) => {
    const d = slotStart(i);
    return { row: i + 2, label: d.getMinutes() === 0 ? d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '' };
}));

function rowForIso(iso: string): number {
    const d = new Date(iso);
    const minutesFromStart = (d.getHours() - START_HOUR) * 60 + d.getMinutes();
    return Math.max(0, Math.round(minutesFromStart / SLOT_MINUTES)) + 2;
}

function employeeColumn(employeeId: number): number {
    return props.employees.findIndex((e) => e.id === employeeId) + 2;
}

function isoForSlot(index: number): string {
    return slotStart(index).toISOString();
}

const gridTemplateColumns = computed(() => `56px repeat(${Math.max(props.employees.length, 1)}, minmax(160px, 1fr))`);
</script>

<template>
    <div class="overflow-x-auto rounded-xl border border-border">
        <div class="grid text-xs" :style="{ gridTemplateColumns, gridTemplateRows: '36px', gridAutoRows: '18px' }">
            <div class="sticky top-0 z-10 border-b border-border bg-background" style="grid-row: 1; grid-column: 1"></div>
            <div v-for="(e, ei) in employees" :key="e.id" class="sticky top-0 z-10 truncate border-b border-l border-border bg-background px-2 py-2 text-sm font-medium ui-text" :style="{ gridRow: 1, gridColumn: ei + 2 }">
                {{ e.name }}
            </div>

            <template v-for="slot in slots" :key="slot.row">
                <div class="border-b border-border pr-2 text-right text-[10px] ui-subtle" :style="{ gridRow: slot.row, gridColumn: 1 }">{{ slot.label }}</div>
                <div
                    v-for="(e, ei) in employees"
                    :key="e.id + '-' + slot.row"
                    class="cursor-pointer border-b border-l border-border hover:bg-accent/40"
                    :style="{ gridRow: slot.row, gridColumn: ei + 2 }"
                    @click="emit('create-at', e.id, isoForSlot(slot.row - 2))"
                />
            </template>

            <button
                v-for="booking in bookings"
                :key="booking.id"
                type="button"
                class="m-px overflow-hidden rounded-md border px-1.5 py-1 text-left text-[11px] leading-tight shadow-sm"
                :class="STATUS_COLORS[booking.status] ?? 'bg-muted'"
                :style="{ gridRow: `${rowForIso(booking.starts_at)} / ${rowForIso(booking.ends_at)}`, gridColumn: employeeColumn(booking.employee_id) }"
                @click.stop="emit('open', booking.id)"
            >
                <span class="block truncate font-medium">{{ booking.customer?.name ?? '—' }}</span>
                <span class="block truncate">{{ booking.service?.name }}</span>
                <span class="block truncate">{{ locale.t('booking.statuses.' + booking.status) }}</span>
            </button>
        </div>
    </div>
</template>
