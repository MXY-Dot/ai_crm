<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { eventOnDate, hasStrikethrough, isNew, isOverdue, resourceAccent, STATUS_COLORS, STATUS_DOTS, toLocalDateString, type CalendarEvent, type CalendarResource } from '../../../lib/calendar';
import { useLocaleStore } from '../../../stores/locale';

const locale = useLocaleStore();
const props = defineProps<{ date: string; resources: CalendarResource[]; events: CalendarEvent[] }>();
const emit = defineEmits<{ open: [event: CalendarEvent]; create: [payload: { resourceId: number | string; iso: string }] }>();

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

function slotIso(row: number): string {
    return slotStart(row - 2).toISOString();
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

type SubColumn = { col: number; cols: number };

/**
 * Two events for the SAME master overlapping in time used to just land in
 * the same grid cell and visually stack directly on top of each other --
 * found live via a real screenshot, unreadable ("orgy of colors") and, even
 * after an earlier fix excluded cancelled/no-show events from this and drew
 * them as a fixed corner sliver instead, still overlapping an active event
 * behind it and blocking clicks ("нажать одно появляется другое" -- found
 * live a second time). EVERY event now participates here, full stop, no
 * status exceptions -- the only way to guarantee nothing ever visually
 * blocks anything else. Standard greedy interval-partitioning (same idea
 * Google Calendar's day view uses): events are swept in start-time order,
 * each placed in the first column whose last event has already ended, a new
 * column opened otherwise; once a contiguous overlapping cluster closes,
 * every event in it gets the cluster's own final column count so they split
 * that width evenly.
 */
function computeSubColumns(events: CalendarEvent[]): Map<string, SubColumn> {
    const layout = new Map<string, SubColumn>();
    const sorted = events.slice().sort((a, b) => a.starts_at.localeCompare(b.starts_at));

    let cluster: CalendarEvent[] = [];
    let clusterEnd: number | null = null;

    const flush = () => {
        if (! cluster.length) return;

        const columns: CalendarEvent[][] = [];

        for (const event of cluster) {
            const start = new Date(event.starts_at).getTime();
            let col = columns.findIndex((column) => new Date(column[column.length - 1].ends_at).getTime() <= start);

            if (col === -1) {
                columns.push([event]);
                col = columns.length - 1;
            } else {
                columns[col].push(event);
            }

            layout.set(event.id, { col, cols: 0 });
        }

        for (const event of cluster) {
            layout.get(event.id)!.cols = columns.length;
        }

        cluster = [];
        clusterEnd = null;
    };

    for (const event of sorted) {
        const start = new Date(event.starts_at).getTime();

        if (clusterEnd !== null && start >= clusterEnd) {
            flush();
        }

        cluster.push(event);
        const end = new Date(event.ends_at).getTime();
        clusterEnd = clusterEnd === null ? end : Math.max(clusterEnd, end);
    }
    flush();

    return layout;
}

const subColumns = computed(() => {
    const byResource = new Map<string, CalendarEvent[]>();

    for (const event of visibleEvents.value) {
        const key = String(event.resource_id);

        if (! byResource.has(key)) byResource.set(key, []);
        byResource.get(key)!.push(event);
    }

    const merged = new Map<string, SubColumn>();

    for (const group of byResource.values()) {
        for (const [id, info] of computeSubColumns(group)) {
            merged.set(id, info);
        }
    }

    return merged;
});

function eventStyle(event: CalendarEvent): Record<string, string> {
    const base = { gridRow: `${eventStartRow(event)} / ${eventEndRow(event)}`, gridColumn: String(resourceColumn(event.resource_id)) };
    const info = subColumns.value.get(event.id);

    if (! info || info.cols <= 1) {
        return base;
    }

    const width = 100 / info.cols;

    return { ...base, width: `calc(${width}% - 3px)`, marginLeft: `${info.col * width}%` };
}

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
                        class="cursor-pointer border-l border-border transition-colors hover:bg-primary/10"
                        :class="slot.isHour ? 'border-b border-border' : 'border-b border-border/30'"
                        :style="{ gridRow: slot.row, gridColumn: resourceColumn(r.id) }"
                        :title="locale.t('calendar.clickToCreate')"
                        @click="emit('create', { resourceId: r.id, iso: slotIso(slot.row) })"
                    />
                </template>

                <button
                    v-for="event in visibleEvents"
                    :key="event.id"
                    type="button"
                    :title="`${event.title} · ${event.subtitle}${isOverdue(event) ? ' · Просрочено, статус не обновлён' : ''}`"
                    class="relative z-[6] mt-0.5 mb-0.5 flex flex-col justify-center gap-px overflow-hidden rounded-lg border px-2 py-1 text-left leading-tight shadow-sm transition-all hover:z-[7] hover:shadow-md hover:brightness-105"
                    :class="[STATUS_COLORS[event.status] ?? 'bg-muted', isOverdue(event) ? 'ring-2 ring-destructive' : '', hasStrikethrough(event.status) ? 'opacity-70' : '']"
                    :style="eventStyle(event)"
                    @click.stop="emit('open', event)"
                >
                    <span v-if="isNew(event)" class="absolute right-1 top-1 size-1.5 shrink-0 rounded-full bg-emerald-500" title="Новая запись" />
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
