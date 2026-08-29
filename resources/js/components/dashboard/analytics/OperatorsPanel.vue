<script setup lang="ts">
import { Card } from '../../ui/card';
import { Skeleton } from '../../ui/skeleton';
import { useLocaleStore } from '../../../stores/locale';

export type OperatorRow = {
    user_id: number;
    name: string;
    conversations: number;
    closed: number;
    avg_quality_score: number | null;
    unhappy_count: number;
};

defineProps<{ data: OperatorRow[] | null; loading: boolean }>();
const locale = useLocaleStore();
</script>

<template>
    <Card :title="locale.t('analytics.operatorsPanel.title')" :subtitle="locale.t('analytics.operatorsPanel.subtitle')">
        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-10 rounded-lg" />
        </div>
        <p v-else-if="! data || ! data.length" class="pb-4 text-sm ui-subtle">{{ locale.t('analytics.operatorsPanel.empty') }}</p>
        <div v-else class="overflow-x-auto pb-2">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-border text-xs ui-subtle">
                        <th class="py-2 pr-4 font-medium">{{ locale.t('analytics.operatorsPanel.name') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ locale.t('analytics.operatorsPanel.conversations') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ locale.t('analytics.operatorsPanel.closed') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ locale.t('analytics.operatorsPanel.avgQuality') }}</th>
                        <th class="py-2 font-medium">{{ locale.t('analytics.operatorsPanel.unhappyClients') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in data" :key="row.user_id">
                        <td class="py-2 pr-4 ui-text">{{ row.name }}</td>
                        <td class="py-2 pr-4 ui-subtle">{{ row.conversations }}</td>
                        <td class="py-2 pr-4 ui-subtle">{{ row.closed }}</td>
                        <td class="py-2 pr-4 ui-subtle">{{ row.avg_quality_score ?? '—' }}</td>
                        <td class="py-2 ui-subtle">{{ row.unhappy_count }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>
</template>
