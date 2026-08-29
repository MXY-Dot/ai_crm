<script setup lang="ts">
import { Card } from '../../ui/card';
import { Skeleton } from '../../ui/skeleton';
import { useLocaleStore } from '../../../stores/locale';

export type OutcomeRow = { outcome: string; count: number; percent: number };

defineProps<{ data: OutcomeRow[] | null; loading: boolean }>();
const locale = useLocaleStore();
</script>

<template>
    <Card :title="locale.t('analytics.outcomes.title')" :subtitle="locale.t('analytics.outcomes.subtitle')">
        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 4" :key="i" class="h-8 rounded-lg" />
        </div>
        <p v-else-if="! data || ! data.length" class="pb-4 text-sm ui-subtle">{{ locale.t('analytics.outcomes.empty') }}</p>
        <div v-else class="space-y-2 pb-4">
            <div v-for="row in data" :key="row.outcome" class="flex items-center gap-3 text-sm">
                <span class="w-48 shrink-0 truncate ui-text">{{ locale.t('analytics.outcomes.' + row.outcome) }}</span>
                <div class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                    <div class="h-full rounded-full bg-primary" :style="{ width: row.percent + '%' }" />
                </div>
                <span class="w-16 shrink-0 text-right ui-subtle">{{ row.count }} · {{ row.percent }}%</span>
            </div>
        </div>
    </Card>
</template>
