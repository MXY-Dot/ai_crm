<script setup lang="ts">
import { Timer } from '@lucide/vue';
import { Skeleton } from '../../ui/skeleton';
import { useLocaleStore } from '../../../stores/locale';

export type Sla = {
    avg_first_response_minutes: number | null;
    avg_resolution_hours: number | null;
    resolved_count: number;
};

defineProps<{ data: Sla | null; loading: boolean }>();
const locale = useLocaleStore();

function formatMinutes(value: number | null): string {
    if (value === null) return '—';

    return value >= 60 ? `${(value / 60).toFixed(1)} ч` : `${value.toFixed(0)} мин`;
}

function formatHours(value: number | null): string {
    if (value === null) return '—';

    return value >= 24 ? `${(value / 24).toFixed(1)} дн` : `${value.toFixed(1)} ч`;
}
</script>

<template>
    <div class="rounded-xl border p-5 border-border bg-card">
        <div class="mb-1 flex items-center gap-2">
            <Timer class="h-4 w-4 text-primary" />
            <h3 class="font-display text-base font-semibold ui-text">{{ locale.t('analytics.sla.title') }}</h3>
        </div>
        <p class="mb-5 text-xs ui-subtle">{{ locale.t('analytics.sla.subtitle') }}</p>

        <Skeleton v-if="loading" class="h-24 rounded-lg" />
        <div v-else-if="data" class="grid grid-cols-3 gap-3 text-center">
            <div class="rounded-lg border p-3 border-border">
                <p class="font-display text-2xl font-bold ui-text">{{ formatMinutes(data.avg_first_response_minutes) }}</p>
                <p class="mt-1 text-xs ui-subtle">{{ locale.t('analytics.sla.firstResponse') }}</p>
            </div>
            <div class="rounded-lg border p-3 border-border">
                <p class="font-display text-2xl font-bold ui-text">{{ formatHours(data.avg_resolution_hours) }}</p>
                <p class="mt-1 text-xs ui-subtle">{{ locale.t('analytics.sla.resolutionTime') }}</p>
            </div>
            <div class="rounded-lg border p-3 border-border">
                <p class="font-display text-2xl font-bold ui-text">{{ data.resolved_count }}</p>
                <p class="mt-1 text-xs ui-subtle">{{ locale.t('analytics.sla.resolvedCount') }}</p>
            </div>
        </div>
    </div>
</template>
