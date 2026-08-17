<script setup lang="ts">
import { Gauge } from '@lucide/vue';
import { Progress } from '../../ui/progress';
import { Skeleton } from '../../ui/skeleton';
import { useLocaleStore } from '../../../stores/locale';

export type AiPerformance = {
    runs: number;
    avg_confidence: number;
    avg_latency_ms: number;
    handoff_rate: number;
    ai_replacement_rate: number;
};

defineProps<{ data: AiPerformance | null; loading: boolean }>();
const locale = useLocaleStore();

function formatLatency(ms: number): string {
    return ms >= 1000 ? `${(ms / 1000).toFixed(1)} с` : `${ms} мс`;
}
</script>

<template>
    <div class="rounded-xl border p-5 border-border bg-card">
        <div class="mb-1 flex items-center gap-2">
            <Gauge class="h-4 w-4 text-primary" />
            <h3 class="font-display text-base font-semibold ui-text">{{ locale.t('analytics.aiPerformance.title') }}</h3>
        </div>
        <p class="mb-5 text-xs ui-subtle">{{ locale.t('analytics.aiPerformance.subtitle') }}</p>

        <Skeleton v-if="loading" class="h-40 rounded-lg" />
        <template v-else-if="data">
            <div class="grid grid-cols-2 gap-3 text-center sm:grid-cols-4">
                <div class="rounded-lg border p-3 border-border">
                    <p class="font-display text-2xl font-bold ui-text">{{ data.runs }}</p>
                    <p class="mt-1 text-xs ui-subtle">{{ locale.t('analytics.aiPerformance.runs') }}</p>
                </div>
                <div class="rounded-lg border p-3 border-border">
                    <p class="font-display text-2xl font-bold ui-text">{{ data.ai_replacement_rate }}%</p>
                    <p class="mt-1 text-xs ui-subtle">{{ locale.t('analytics.aiPerformance.replacementRate') }}</p>
                </div>
                <div class="rounded-lg border p-3 border-border">
                    <p class="font-display text-2xl font-bold ui-text">{{ data.handoff_rate }}%</p>
                    <p class="mt-1 text-xs ui-subtle">{{ locale.t('analytics.aiPerformance.escalation') }}</p>
                </div>
                <div class="rounded-lg border p-3 border-border">
                    <p class="font-display text-2xl font-bold ui-text">{{ formatLatency(data.avg_latency_ms) }}</p>
                    <p class="mt-1 text-xs ui-subtle">{{ locale.t('analytics.aiPerformance.latency') }}</p>
                </div>
            </div>
            <div class="mt-4">
                <div class="mb-1.5 flex items-center justify-between text-sm">
                    <span class="ui-text">{{ locale.t('analytics.aiPerformance.confidence') }}</span>
                    <span class="font-mono text-xs ui-subtle">{{ data.avg_confidence }}%</span>
                </div>
                <Progress :model-value="data.avg_confidence" />
                <p class="mt-1.5 text-[11px] ui-subtle">{{ locale.t('analytics.aiPerformance.confidenceHint') }}</p>
            </div>
        </template>
    </div>
</template>
