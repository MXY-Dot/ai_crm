<script setup lang="ts">
import { Card } from '../../ui/card';
import { Skeleton } from '../../ui/skeleton';
import { useLocaleStore } from '../../../stores/locale';

export type SentimentRow = { sentiment: string; count: number; percent: number };

defineProps<{ data: SentimentRow[] | null; loading: boolean }>();
const locale = useLocaleStore();

const COLORS: Record<string, string> = {
    very_happy: 'bg-emerald-500',
    happy: 'bg-emerald-400',
    neutral: 'bg-muted-foreground/40',
    unhappy: 'bg-amber-500',
    very_unhappy: 'bg-orange-500',
    angry: 'bg-destructive',
};
</script>

<template>
    <Card :title="locale.t('analytics.sentimentPanel.title')" :subtitle="locale.t('analytics.sentimentPanel.subtitle')">
        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 4" :key="i" class="h-8 rounded-lg" />
        </div>
        <p v-else-if="! data || ! data.filter((r) => r.count > 0).length" class="pb-4 text-sm ui-subtle">{{ locale.t('analytics.sentimentPanel.empty') }}</p>
        <div v-else class="space-y-2 pb-4">
            <div v-for="row in data.filter((r) => r.count > 0)" :key="row.sentiment" class="flex items-center gap-3 text-sm">
                <span class="w-32 shrink-0 truncate ui-text">{{ locale.t('analytics.sentimentPanel.' + row.sentiment) }}</span>
                <div class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                    <div class="h-full rounded-full" :class="COLORS[row.sentiment]" :style="{ width: row.percent + '%' }" />
                </div>
                <span class="w-16 shrink-0 text-right ui-subtle">{{ row.count }} · {{ row.percent }}%</span>
            </div>
        </div>
    </Card>
</template>
