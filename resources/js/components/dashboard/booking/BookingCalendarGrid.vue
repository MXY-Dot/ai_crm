<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { STATUS_DOTS } from '../../../lib/bookingStatus';
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
const HEADER_HEIGHT = 40;
const ROW_HEIGHT = 22;
const TIME_GUTTER = 64;

const STATUS_COLORS: Record<string, string> = {
    temp_hold: 'bg-muted text-muted-foreground',
    awaiting_payment: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    payment_review: 'bg-orange-500/15 text-orange-700 dark:text-orange-400',
    confirmed: 'bg-blue-500/15 text-blue-700 dark:text-blue-400',
    client_arrived: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-400',
    in_progress: 'bg-purple-500/15 text-purple-700 dark:text-purple-400',
    completed: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    rescheduled: 'bg-muted text-muted-foreground',
    cancelled: 'bg-muted text-muted-foreground line-through opacity-70',
    no_show: 'bg-destructive/10 text-destructive',
};


const EMPLOYEE_ACCENTS = ['bg-sky-500', 'bg-violet-500', 'bg-amber-500', 'bg-emerald-500', 'bg-rose-500', 'bg-indigo-500'];
function employeeAccent(index: number): string {
    return EMPLOYEE_ACCENTS[index % EMPLOYEE_ACCENTS.length];
}

function slotStart(index: number): Date {
    const d = new Date(props.date + 'T00:00:00');
    d.setHours(START_HOUR, 0, 0, 0);
    d.setMinutes(d.getMinutes() + index * SLOT_MINUTES);
    return d;
}

const slots = computed(() => Array.from({ length: SLOT_COUNT }, (_, i) => {
    const d = slotStart(i);
    const isHour = d.getMinutes() === 0;
    return { row: i + 2, label: isHour ? d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '', isHour };
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

function formatRange(startsAt: string, endsAt: string): string {
    const fmt = (iso: string) => new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    return `${fmt(startsAt)}–${fmt(endsAt)}`;
}

function statusLabel(status: string): string {
    return locale.t('booking.statuses.' + status);
}

const gridTemplateColumns = computed(() => `${TIME_GUTTER}px repeat(${Math.max(props.employees.length, 1)}, minmax(160px, 1fr))`);

// A live "now" line, same idea as any real calendar app -- only meaningful when
// looking at today, ticks forward on its own without needing a page reload.
const now = ref(new Date());
let nowTimer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    nowTimer = setInterval(() => { now.value = new Date(); }, 60_000);
});
onUnmounted(() => {
    if (nowTimer) clearInterval(nowTimer);
});

function toLocalDateString(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const minutesIntoDay = computed(() => (now.value.getHours() - START_HOUR) * 60 + now.value.getMinutes());
const showNowLine = computed(() => props.date === toLocalDateString(now.value) && minutesIntoDay.value >= 0 && minutesIntoDay.value <= SLOT_COUNT * SLOT_MINUTES);
const nowLineTop = computed(() => HEADER_HEIGHT + (minutesIntoDay.value / SLOT_MINUTES) * ROW_HEIGHT);
</script>

<template>
    <div class="overflow-hidden rounded-xl bg-card shadow-sm ring-1 ring-foreground/10">
        <div class="relative max-h-[70vh] overflow-auto">
            <div class="grid text-xs" :style="{ gridTemplateColumns, gridTemplateRows: `${HEADER_HEIGHT}px`, gridAutoRows: `${ROW_HEIGHT}px` }">
                <div class="sticky top-0 left-0 z-20 border-b border-border bg-background" style="grid-row: 1; grid-column: 1"></div>
                <div
                    v-for="(e, ei) in employees"
                    :key="e.id"
                    class="sticky top-0 z-10 flex items-center gap-2 truncate border-b border-l border-border bg-background px-3 text-sm font-semibold ui-text"
                    :style="{ gridRow: 1, gridColumn: ei + 2 }"
                >
                    <span class="h-2 w-2 shrink-0 rounded-full" :class="employeeAccent(ei)" />
                    <span class="truncate">{{ e.name }}</span>
                </div>

                <template v-for="slot in slots" :key="slot.row">
                    <div
                        class="sticky left-0 z-10 border-b bg-background pr-2.5 text-right text-[10.5px] font-medium ui-subtle"
                        :class="slot.isHour ? 'border-border' : 'border-border/30'"
                        :style="{ gridRow: slot.row, gridColumn: 1 }"
                    >{{ slot.label }}</div>
                    <div
                        v-for="(e, ei) in employees"
                        :key="e.id + '-' + slot.row"
                        class="cursor-pointer border-l border-border transition-colors hover:bg-accent/50"
                        :class="slot.isHour ? 'border-b border-border' : 'border-b border-border/30'"
                        :style="{ gridRow: slot.row, gridColumn: ei + 2 }"
                        @click="emit('create-at', e.id, isoForSlot(slot.row - 2))"
                    />
                </template>

                <button
                    v-for="booking in bookings"
                    :key="booking.id"
                    type="button"
                    :title="`${booking.customer?.name ?? '—'} · ${booking.service?.name ?? ''} · ${statusLabel(booking.status)}`"
                    class="relative z-[6] m-0.5 flex flex-col justify-center gap-px overflow-hidden rounded-lg border px-2 py-1 text-left leading-tight shadow-sm transition-all hover:z-[7] hover:shadow-md hover:brightness-105"
                    :class="STATUS_COLORS[booking.status] ?? 'bg-muted'"
                    :style="{ gridRow: `${rowForIso(booking.starts_at)} / ${rowForIso(booking.ends_at)}`, gridColumn: employeeColumn(booking.employee_id) }"
                    @click.stop="emit('open', booking.id)"
                >
                    <span class="flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="STATUS_DOTS[booking.status] ?? 'bg-muted-foreground'" />
                        <span class="truncate text-[11px] font-semibold">{{ booking.customer?.name ?? '—' }}</span>
                    </span>
                    <span class="truncate pl-3 text-[10.5px] opacity-80">{{ booking.service?.name }}</span>
                    <span class="truncate pl-3 text-[10px] font-medium tabular-nums opacity-70">{{ formatRange(booking.starts_at, booking.ends_at) }}</span>
                </button>
            </div>

            <div
                v-if="showNowLine"
                class="pointer-events-none absolute right-0 z-[5] flex items-center"
                :style="{ top: nowLineTop + 'px', left: `${TIME_GUTTER}px` }"
            >
                <span class="-ml-[3px] h-1.5 w-1.5 shrink-0 rounded-full bg-red-500" />
                <span class="h-px flex-1 bg-red-500/70" />
            </div>
        </div>
    </div>
</template>
