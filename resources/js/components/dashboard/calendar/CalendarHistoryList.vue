<script setup lang="ts">
import { computed } from 'vue';
import { hasStrikethrough, isOverdue, STATUS_COLORS, STATUS_DOTS, statusLabel, toLocalDateString, type CalendarEvent, type CalendarResource } from '../../../lib/calendar';
import { useLocaleStore } from '../../../stores/locale';

// Flat, date-grouped read of past events -- CalendarPage's own "История"
// toggle mode. Unlike the day/week/month views (each bound to one specific
// date/range on screen), this exists purely so staff can scroll back through
// what already happened -- especially records CalendarPage's own isOverdue()
// flagged as never updated -- without paging backward one day at a time.
// $events arrives already filtered (status/overdue-only) and sorted newest-
// first by CalendarPage's own historyEvents computed.
const props = defineProps<{ events: CalendarEvent[]; resources: CalendarResource[] }>();
const emit = defineEmits<{ open: [event: CalendarEvent] }>();
const locale = useLocaleStore();

function resourceName(id: number | string | null): string {
    if (id === null) return '';

    return props.resources.find((r) => String(r.id) === String(id))?.name ?? '';
}

function time(iso: string): string {
    return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

function dateLabel(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, { weekday: 'short', day: '2-digit', month: 'long' });
}

const groups = computed(() => {
    const map = new Map<string, CalendarEvent[]>();
    for (const event of props.events) {
        const key = toLocalDateString(new Date(event.starts_at));
        if (! map.has(key)) map.set(key, []);
        map.get(key)!.push(event);
    }

    return Array.from(map.entries());
});
</script>

<template>
    <div class="space-y-4">
        <div v-for="[dateKey, dayEvents] in groups" :key="dateKey" class="overflow-hidden rounded-xl bg-card shadow-sm ring-1 ring-foreground/10">
            <div class="border-b border-border bg-background px-4 py-2 text-xs font-semibold uppercase tracking-wide ui-subtle">{{ dateLabel(dayEvents[0].starts_at) }}</div>
            <div class="flex flex-col divide-y divide-border">
                <button
                    v-for="event in dayEvents"
                    :key="event.id"
                    type="button"
                    class="flex items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-accent/40"
                    :class="isOverdue(event) ? 'bg-destructive/5' : ''"
                    @click="emit('open', event)"
                >
                    <span class="h-2 w-2 shrink-0 rounded-full" :class="STATUS_DOTS[event.status] ?? 'bg-muted-foreground'" />
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span class="truncate text-sm font-semibold ui-text" :class="hasStrikethrough(event.status) ? 'line-through opacity-60' : ''">{{ event.title }}</span>
                            <span v-if="isOverdue(event)" class="shrink-0 rounded-full bg-destructive/10 px-1.5 py-0.5 text-[10px] font-semibold text-destructive">Просрочено</span>
                        </span>
                        <span class="block truncate text-xs ui-subtle">{{ event.subtitle }}<template v-if="resourceName(event.resource_id)"> · {{ resourceName(event.resource_id) }}</template></span>
                    </span>
                    <span class="flex shrink-0 flex-col items-end gap-1">
                        <span class="rounded-full px-2 py-0.5 text-[10.5px] font-medium whitespace-nowrap" :class="STATUS_COLORS[event.status] ?? 'bg-muted text-muted-foreground'">{{ statusLabel(event.status) }}</span>
                        <span class="text-xs font-medium tabular-nums ui-subtle">{{ time(event.starts_at) }}</span>
                    </span>
                </button>
            </div>
        </div>
        <div v-if="! groups.length" class="rounded-xl bg-card px-4 py-10 text-center text-sm ui-subtle shadow-sm ring-1 ring-foreground/10">
            {{ locale.t('calendar.noEvents') }}
        </div>
    </div>
</template>
