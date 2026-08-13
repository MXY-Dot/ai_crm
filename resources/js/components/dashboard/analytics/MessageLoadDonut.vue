<script setup lang="ts">
import { computed } from 'vue';
import { VisDonut, VisDonutSelectors, VisSingleContainer } from '@unovis/vue';
import type { Message } from '../../../stores/crmDashboard';
import { ChartContainer, ChartTooltip, type ChartConfig } from '../../ui/chart';

const props = defineProps<{ messages: Message[] }>();

type Segment = { key: 'ai' | 'operator'; label: string; count: number; percent: number; color: string };

const chartConfig: ChartConfig = {
    ai: { label: 'AI Ассистент', color: 'var(--primary)' },
    operator: { label: 'Операторы', color: 'var(--muted-foreground)' },
};

const segments = computed<Segment[]>(() => {
    const total = props.messages.length;
    const ai = props.messages.filter((message) => message.sender_type === 'ai').length;
    const operator = props.messages.filter((message) => message.sender_type === 'operator').length;

    return [
        { key: 'ai', label: chartConfig.ai.label as string, count: ai, percent: total ? Math.round((ai / total) * 100) : 0, color: chartConfig.ai.color as string },
        { key: 'operator', label: chartConfig.operator.label as string, count: operator, percent: total ? Math.round((operator / total) * 100) : 0, color: chartConfig.operator.color as string },
    ];
});

const aiPercent = computed(() => segments.value.find((segment) => segment.key === 'ai')?.percent ?? 0);

function tooltipHtml(segment: Segment): string {
    return `<div class="grid min-w-32 gap-1.5 rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
        <div class="flex w-full items-center gap-2">
            <span class="h-2.5 w-2.5 shrink-0 rounded-xs" style="background:${segment.color}"></span>
            <span class="flex-1 text-muted-foreground">${segment.label}</span>
            <span class="font-mono font-medium text-foreground">${segment.count} (${segment.percent}%)</span>
        </div>
    </div>`;
}
</script>

<template>
    <div class="flex flex-col rounded-xl border p-5 border-border bg-card">
        <div class="mb-6">
            <h3 class="font-display text-base font-semibold ui-text">AI vs Операторы</h3>
            <p class="text-sm ui-subtle">Распределение ответов</p>
        </div>
        <div class="flex flex-1 items-center justify-center">
            <ChartContainer :config="chartConfig" class="h-40 w-40 shrink-0">
                <VisSingleContainer :data="segments" :height="160" :width="160">
                    <VisDonut
                        :value="(d: Segment) => d.count"
                        :color="(d: Segment) => d.color"
                        :corner-radius="3"
                        :pad-angle="0.02"
                        :arc-width="18"
                        :central-label="`${aiPercent}%`"
                        central-sub-label="AI"
                    />
                    <ChartTooltip :triggers="{ [VisDonutSelectors.segment]: (d: any) => tooltipHtml(d.data) }" />
                </VisSingleContainer>
            </ChartContainer>
        </div>
        <div class="mt-6 space-y-2 text-sm">
            <div v-for="segment in segments" :key="segment.key" class="flex items-center justify-between">
                <span class="flex items-center gap-2 ui-text"><span class="h-3 w-3 rounded-full" :style="{ background: segment.color }" />{{ segment.label }}</span>
                <span class="font-mono ui-subtle">{{ segment.count }}</span>
            </div>
        </div>
    </div>
</template>
