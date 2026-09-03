<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { eventOnDate, hasStrikethrough, resourceAccent, STATUS_COLORS, STATUS_DOTS, toLocalDateString, type CalendarEvent, type CalendarResource } from '../../../lib/calendar';

const props = defineProps<{ date: string; resources: CalendarResource[]; events: CalendarEvent[] }>();
const emit = defineEmits<{ open: [event: CalendarEvent] }>();

const START_HOUR = 8;
const END_HOUR = 21;
const SLOT_MINUTES = 15;
const SLOT_COUNT = ((END_HOUR - START_HOUR) * 60) / SLOT_MINUTES;
const HEADER_HEIGHT = 40;
const ROW_HEIGHT = 22;
const TIME_GUTTER = 64;

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

function rowForDate(d: Date): number {
    const minutesFromStart = (d.getHours() - START_HOUR) * 60 + d.getMinutes();
    return Math.max(0, Math.min(SLOT_COUNT, Math.round(minutesFromStart / SLOT_MINUTES))) + 2;
}

// A multi-day event (e.g. a hotel stay) that only overlaps this day -- rather
// than starting/ending on it -- clamps to the grid's own top/bottom edge
// instead of using its real start/end hour, which could otherwise land on
// the wrong-looking row (or the wrong day's hour entirely).
function eventStartRow(event: CalendarEvent): number {
    const d = new Date(event.starts_at);
    return toLocalDateString(d) === props.date ? rowForDate(d) : 2;
}

function eventEndRow(event: CalendarEvent): number {
    const d = new Date(event.ends_at);
    return toLocalDateString(d) === props.date ? rowForDate(d) : SLOT_COUNT + 2;
}

function resourceColumn(resourceId: number | string | null): number {
    return props.resources.findIndex((r) => String(r.id) === String(resourceId)) + 2;
}

function formatRange(startsAt: string, endsAt: string): string {
    const fmt = (iso: string) => new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    return `${fmt(startsAt)}–${fmt(endsAt)}`;
}

const gridTemplateColumns = computed(() => `${TIME_GUTTER}px repeat(${Math.max(props.resources.length, 1)}, minmax(160px, 1fr))`);

// CalendarPage deliberately fetches a wide +/-14/15 day window even for day
// view (CalendarDayAgenda needs that range to catch multi-night stays that
// only overlap today) -- so this grid gets every event in that window, not
// just today's, and must filter for itself. rowForDate() only looks at
// hour-of-day, so without this an event from a different day at the same
// time-of-day would silently render on today's row instead of not showing
// at all -- found live via a real screenshot where Sept 1-2 bookings were
// showing up on the Sept 8 grid.
const visibleEvents = computed(() => props.events.filter((e) => eventOnDate(e, props.date) && resourceColumn(e.resource_id) >= 2));

const now = ref(new Date());
let nowTimer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    nowTimer = setInterval(() => { now.value = new Date(); }, 60_000);
});
onUnmounted(() => {
    if (nowTimer) clearInterval(nowTimer);
});

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
                    v-for="(r, ri) in resources"
                    :key="r.id"
                    class="sticky top-0 z-10 flex items-center gap-2 truncate border-b border-l border-border bg-background px-3 text-sm font-semibold ui-text"
                    :style="{ gridRow: 1, gridColumn: ri + 2 }"
                >
                    <span class="h-2 w-2 shrink-0 rounded-full" :class="resourceAccent(ri)" />
                    <span class="truncate">{{ r.name }}</span>
                </div>

                <template v-for="slot in slots" :key="slot.row">
                    <div
                        class="sticky left-0 z-10 border-b bg-background pr-2.5 text-right text-[10.5px] font-medium ui-subtle"
                        :class="slot.isHour ? 'border-border' : 'border-border/30'"
                        :style="{ gridRow: slot.row, gridColumn: 1 }"
                    >{{ slot.label }}</div>
                    <div
                        v-for="r in resources"
                        :key="r.id + '-' + slot.row"
                        class="border-l border-border"
                        :class="slot.isHour ? 'border-b border-border' : 'border-b border-border/30'"
                        :style="{ gridRow: slot.row, gridColumn: resourceColumn(r.id) }"
                    />
                </template>

                <button
                    v-for="event in visibleEvents"
                    :key="event.id"
                    type="button"
                    :title="`${event.title} · ${event.subtitle}`"
                    class="relative z-[6] m-0.5 flex flex-col justify-center gap-px overflow-hidden rounded-lg border px-2 py-1 text-left leading-tight shadow-sm transition-all hover:z-[7] hover:shadow-md hover:brightness-105"
                    :class="STATUS_COLORS[event.status] ?? 'bg-muted'"
                    :style="{ gridRow: `${eventStartRow(event)} / ${eventEndRow(event)}`, gridColumn: resourceColumn(event.resource_id) }"
                    @click.stop="emit('open', event)"
                >
                    <span class="flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="STATUS_DOTS[event.status] ?? 'bg-muted-foreground'" />
                        <span class="truncate text-[11px] font-semibold" :class="hasStrikethrough(event.status) ? 'line-through' : ''">{{ event.title }}</span>
                    </span>
                    <span class="truncate pl-3 text-[10.5px] opacity-80">{{ event.subtitle }}</span>
                    <span class="truncate pl-3 text-[10px] font-medium tabular-nums opacity-70">{{ formatRange(event.starts_at, event.ends_at) }}</span>
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
