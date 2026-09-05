<script setup lang="ts">
import { computed } from 'vue';
import { Monitor } from '@lucide/vue';
import { CHANNEL_ICONS, CHANNEL_LABELS, eventOnDate, hasStrikethrough, isNew, isOverdue, STATUS_COLORS, STATUS_DOTS, statusLabel, toLocalDateString, type CalendarEvent, type CalendarResource } from '../../../lib/calendar';
import { useLocaleStore } from '../../../stores/locale';

const props = defineProps<{ weekStart: string; events: CalendarEvent[]; resources: CalendarResource[] }>();
const emit = defineEmits<{ 'select-day': [date: string]; open: [event: CalendarEvent] }>();
const locale = useLocaleStore();

const days = computed(() => Array.from({ length: 7 }, (_, i) => {
    const d = new Date(props.weekStart + 'T00:00:00');
    d.setDate(d.getDate() + i);
    return toLocalDateString(d);
}));

function dayEvents(date: string): CalendarEvent[] {
    return props.events.filter((e) => eventOnDate(e, date)).sort((a, b) => a.starts_at.localeCompare(b.starts_at));
}

function resourceName(id: number | string | null): string {
    if (id === null) return '';

    return props.resources.find((r) => String(r.id) === String(id))?.name ?? '';
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
                    v-for="event in dayEvents(date)"
                    :key="event.id"
                    type="button"
                    class="flex items-start gap-1.5 rounded-lg border px-1.5 py-1 text-left text-[11px] leading-tight transition-colors hover:border-border hover:bg-accent/50"
                    :class="[hasStrikethrough(event.status) ? 'opacity-60' : '', isOverdue(event) ? 'border-destructive/40 bg-destructive/5' : 'border-border/60 bg-background/60']"
                    :title="event.channel ? (CHANNEL_LABELS[event.channel] ?? event.channel) : undefined"
                    @click="emit('open', event)"
                >
                    <span class="mt-1 size-1.5 shrink-0 rounded-full" :class="STATUS_DOTS[event.status] ?? 'bg-muted-foreground'" />
                    <component :is="CHANNEL_ICONS[event.channel] ?? Monitor" v-if="event.channel" class="mt-0.5 h-3 w-3 shrink-0 opacity-70" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium ui-text" :class="hasStrikethrough(event.status) ? 'line-through' : ''">{{ time(event.starts_at) }} · {{ event.title }}</span>
                        <span class="block truncate ui-subtle">{{ event.subtitle }}<template v-if="resourceName(event.resource_id)"> · {{ resourceName(event.resource_id) }}</template></span>
                        <span class="mt-0.5 flex flex-wrap items-center gap-1">
                            <span class="rounded-full px-1.5 py-0.5 text-[9.5px] font-medium" :class="STATUS_COLORS[event.status] ?? 'bg-muted text-muted-foreground'">{{ statusLabel(event.status) }}</span>
                            <span v-if="isNew(event)" class="rounded-full bg-emerald-500/15 px-1.5 py-0.5 text-[9.5px] font-semibold text-emerald-700 dark:text-emerald-400">Новое</span>
                            <span v-if="isOverdue(event)" class="rounded-full bg-destructive/10 px-1.5 py-0.5 text-[9.5px] font-semibold text-destructive">Просрочено</span>
                        </span>
                    </span>
                </button>
                <p v-if="! dayEvents(date).length" class="text-[11px] ui-subtle">{{ locale.t('calendar.noEvents') }}</p>
            </div>
        </div>
    </div>
</template>
