<script setup lang="ts">
import { computed } from 'vue';
import { Monitor } from '@lucide/vue';
import { CHANNEL_ICONS, CHANNEL_LABELS, eventOnDate, hasStrikethrough, isNew, isOverdue, STATUS_COLORS, STATUS_DOTS, statusLabel, type CalendarEvent, type CalendarResource } from '../../../lib/calendar';
import { useLocaleStore } from '../../../stores/locale';

// Used for Day view on modules with no real hour-of-day grid to render
// (multi-night stays, date-only ranges, resourceless all-day markers) --
// see CalendarDayGrid.vue's sibling role and CalendarController's own
// docblock for why. A flat, honest list rather than a fake hour axis.
const props = defineProps<{ date: string; events: CalendarEvent[]; resources: CalendarResource[] }>();
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
                :class="isOverdue(event) ? 'bg-destructive/5' : ''"
                :title="event.channel ? (CHANNEL_LABELS[event.channel] ?? event.channel) : undefined"
                @click="emit('open', event)"
            >
                <span class="h-2 w-2 shrink-0 rounded-full" :class="STATUS_DOTS[event.status] ?? 'bg-muted-foreground'" />
                <span class="min-w-0 flex-1">
                    <span class="flex items-center gap-1.5">
                        <component :is="CHANNEL_ICONS[event.channel] ?? Monitor" v-if="event.channel" class="h-3.5 w-3.5 shrink-0 opacity-70" />
                        <span class="truncate text-sm font-semibold ui-text" :class="hasStrikethrough(event.status) ? 'line-through opacity-60' : ''">{{ event.title }}</span>
                        <span v-if="isNew(event)" class="shrink-0 rounded-full bg-emerald-500/15 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-400">Новое</span>
                        <span v-if="isOverdue(event)" class="shrink-0 rounded-full bg-destructive/10 px-1.5 py-0.5 text-[10px] font-semibold text-destructive">Просрочено</span>
                    </span>
                    <span class="block truncate text-xs ui-subtle">{{ event.subtitle }}<template v-if="resourceName(event.resource_id)"> · {{ resourceName(event.resource_id) }}</template></span>
                </span>
                <span class="flex shrink-0 flex-col items-end gap-1">
                    <span class="rounded-full px-2 py-0.5 text-[10.5px] font-medium whitespace-nowrap" :class="STATUS_COLORS[event.status] ?? 'bg-muted text-muted-foreground'">{{ statusLabel(event.status) }}</span>
                    <span class="text-xs font-medium tabular-nums ui-subtle">{{ range(event.starts_at, event.ends_at) }}</span>
                </span>
            </button>
            <p v-if="! dayEvents.length" class="px-4 py-8 text-center text-sm ui-subtle">{{ locale.t('calendar.noEvents') }}</p>
        </div>
    </div>
</template>
