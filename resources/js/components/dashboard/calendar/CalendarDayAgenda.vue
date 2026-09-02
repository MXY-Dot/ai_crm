<script setup lang="ts">
import { computed } from 'vue';
import { eventOnDate, isMutedStatus, type CalendarEvent, type CalendarResource } from '../../../lib/calendar';
import { useLocaleStore } from '../../../stores/locale';

// Used for Day view on modules with no real hour-of-day grid to render
// (multi-night stays, date-only ranges, resourceless all-day markers) --
// see CalendarDayGrid.vue's sibling role and CalendarController's own
// docblock for why. A flat, honest list rather than a fake hour axis.
const props = defineProps<{ date: string; events: CalendarEvent[]; resources: CalendarResource[]; accentClass: string }>();
const emit = defineEmits<{ open: [event: CalendarEvent] }>();
const locale = useLocaleStore();

const dayEvents = computed(() => props.events.filter((e) => eventOnDate(e, props.date)).sort((a, b) => a.starts_at.localeCompare(b.starts_at)));

function resourceName(id: number | string | null): string {
    if (id === null) return '';

    return props.resources.find((r) => String(r.id) === String(id))?.name ?? '';
}

function range(startsAt: string, endsAt: string): string {
    const sameDay = startsAt.slice(0, 10) === endsAt.slice(0, 10);
    const opts: Intl.DateTimeFormatOptions = sameDay ? { hour: '2-digit', minute: '2-digit' } : { day: '2-digit', month: '2-digit' };
    const fmt = (iso: string) => new Date(iso).toLocaleString(undefined, opts);

    return sameDay ? `${fmt(startsAt)}–${fmt(endsAt)}` : `${fmt(startsAt)} → ${fmt(endsAt)}`;
}
</script>

<template>
    <div class="overflow-hidden rounded-xl bg-card shadow-sm ring-1 ring-foreground/10">
        <div class="flex flex-col divide-y divide-border">
            <button
                v-for="event in dayEvents"
                :key="event.id"
                type="button"
                class="flex items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-accent/40"
                @click="emit('open', event)"
            >
                <span class="h-2 w-2 shrink-0 rounded-full" :class="accentClass" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold ui-text" :class="isMutedStatus(event.status) ? 'line-through opacity-60' : ''">{{ event.title }}</span>
                    <span class="block truncate text-xs ui-subtle">{{ event.subtitle }}<template v-if="resourceName(event.resource_id)"> · {{ resourceName(event.resource_id) }}</template></span>
                </span>
                <span class="shrink-0 text-xs font-medium tabular-nums ui-subtle">{{ range(event.starts_at, event.ends_at) }}</span>
            </button>
            <p v-if="! dayEvents.length" class="px-4 py-8 text-center text-sm ui-subtle">{{ locale.t('calendar.noEvents') }}</p>
        </div>
    </div>
</template>
