<script setup lang="ts">
import { computed } from 'vue';
import { VisDonut, VisDonutSelectors, VisSingleContainer } from '@unovis/vue';
import type { Conversation } from '../../../stores/crmDashboard';
import { sourceLabels } from '../../../lib/statusLabels';
import { ChartContainer, ChartTooltip, type ChartConfig } from '../../ui/chart';

function providerLabel(provider: string): string {
    return sourceLabels[provider] ?? provider;
}

const props = defineProps<{ conversations: Conversation[] }>();
const channelColors: Record<string, string> = {
    telegram: 'var(--brand-telegram)',
    whatsapp: 'var(--brand-whatsapp)',
    instagram: 'var(--brand-instagram-to)',
    website: 'var(--brand-website)',
    web: 'var(--brand-website)',
};

type Segment = { provider: string; count: number; percent: number; color: string };

const segments = computed<Segment[]>(() => {
    const total = props.conversations.length;
    if (! total) return [];

    const counts = new Map<string, number>();
    for (const conversation of props.conversations) {
        const key = conversation.channel?.provider ?? 'other';
        counts.set(key, (counts.get(key) ?? 0) + 1);
    }

    return [...counts.entries()]
        .sort((a, b) => b[1] - a[1])
        .map(([provider, count]) => ({
            provider,
            count,
            percent: Math.round((count / total) * 100),
            color: channelColors[provider.toLowerCase()] ?? 'var(--muted-foreground)',
        }));
});

const chartConfig = computed<ChartConfig>(() => Object.fromEntries(
    segments.value.map((segment) => [segment.provider, { label: providerLabel(segment.provider), color: segment.color }]),
));

function tooltipHtml(segment: Segment): string {
    return `<div class="grid min-w-32 gap-1.5 rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
        <div class="flex w-full items-center gap-2">
            <span class="h-2.5 w-2.5 shrink-0 rounded-xs" style="background:${segment.color}"></span>
            <span class="flex-1 text-muted-foreground">${providerLabel(segment.provider)}</span>
            <span class="font-mono font-medium text-foreground">${segment.count} (${segment.percent}%)</span>
        </div>
    </div>`;
}
</script>

<template>
    <div class="flex flex-col rounded-xl border p-5 border-border bg-card">
        <h3 class="mb-6 font-display text-base font-semibold ui-text">Распределение каналов</h3>
        <div class="flex flex-1 flex-col items-center justify-center">
            <ChartContainer :config="chartConfig" class="h-40 w-40 shrink-0">
                <VisSingleContainer :data="segments" :height="160" :width="160">
                    <VisDonut
                        :value="(d: Segment) => d.count"
                        :color="(d: Segment) => d.color"
                        :corner-radius="3"
                        :pad-angle="segments.length > 1 ? 0.02 : 0"
                        :arc-width="18"
                        :central-label="String(conversations.length)"
                    />
                    <ChartTooltip :triggers="{ [VisDonutSelectors.segment]: (d: any) => tooltipHtml(d.data) }" />
                </VisSingleContainer>
            </ChartContainer>
            <div class="mt-6 w-full space-y-2">
                <div v-for="segment in segments" :key="segment.provider" class="flex items-center justify-between text-sm">
                    <div class="flex items-center gap-2">
                        <span class="h-3 w-3 rounded-full" :style="{ background: segment.color }" />
                        <span class="ui-text">{{ providerLabel(segment.provider) }}</span>
                    </div>
                    <span class="font-mono text-xs ui-subtle">{{ segment.percent }}%</span>
                </div>
                <p v-if="! segments.length" class="text-sm ui-subtle">Нет данных по каналам</p>
            </div>
        </div>
    </div>
</template>
