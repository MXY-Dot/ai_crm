<script setup lang="ts">
import { Badge } from '../../ui/badge';
import { Card } from '../../ui/card';
import { Skeleton } from '../../ui/skeleton';
import { useLocaleStore } from '../../../stores/locale';

export type TopicRow = { topic: string; count: number; percent: number; change_percent: number; is_new: boolean };

defineProps<{ data: TopicRow[] | null; loading: boolean }>();
const locale = useLocaleStore();

function changeLabel(value: number): string {
    return (value > 0 ? '+' : '') + value + '%';
}
</script>

<template>
    <Card :title="locale.t('analytics.topics.title')" :subtitle="locale.t('analytics.topics.subtitle')">
        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 4" :key="i" class="h-10 rounded-lg" />
        </div>
        <p v-else-if="! data || ! data.length" class="pb-4 text-sm ui-subtle">{{ locale.t('analytics.topics.empty') }}</p>
        <div v-else class="divide-y divide-border pb-2">
            <div v-for="row in data" :key="row.topic" class="flex items-center justify-between gap-3 py-2 text-sm">
                <div class="flex min-w-0 items-center gap-2">
                    <span class="truncate ui-text">{{ row.topic }}</span>
                    <Badge v-if="row.is_new" variant="secondary">{{ locale.t('analytics.topics.new') }}</Badge>
                </div>
                <div class="flex shrink-0 items-center gap-3 text-xs ui-subtle">
                    <span>{{ row.count }} {{ locale.t('analytics.topics.requests') }} · {{ row.percent }}%</span>
                    <span :class="row.change_percent > 0 ? 'text-emerald-600 dark:text-emerald-400' : row.change_percent < 0 ? 'text-destructive' : ''">{{ changeLabel(row.change_percent) }}</span>
                </div>
            </div>
        </div>
    </Card>
</template>
