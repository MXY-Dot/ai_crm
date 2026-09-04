<script setup lang="ts">
import DataTable from '../DataTable.vue';
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
    <DataTable
        :loading="loading"
        :row-count="data?.length ?? 0"
        :column-count="5"
        :empty-message="locale.t('analytics.operatorsPanel.empty')"
        :skeleton-rows="3"
        min-width=""
    >
        <template #toolbar>
            <div>
                <h3 class="font-display text-base font-semibold ui-text">{{ locale.t('analytics.operatorsPanel.title') }}</h3>
                <p class="mt-1 text-sm ui-subtle">{{ locale.t('analytics.operatorsPanel.subtitle') }}</p>
            </div>
        </template>

        <template #thead>
            <th class="py-2 pr-4">{{ locale.t('analytics.operatorsPanel.name') }}</th>
            <th class="py-2 pr-4">{{ locale.t('analytics.operatorsPanel.conversations') }}</th>
            <th class="py-2 pr-4">{{ locale.t('analytics.operatorsPanel.closed') }}</th>
            <th class="py-2 pr-4">{{ locale.t('analytics.operatorsPanel.avgQuality') }}</th>
            <th class="py-2">{{ locale.t('analytics.operatorsPanel.unhappyClients') }}</th>
        </template>

        <tr v-for="row in data ?? []" :key="row.user_id">
            <td class="py-2 pr-4 ui-text">{{ row.name }}</td>
            <td class="py-2 pr-4 ui-subtle">{{ row.conversations }}</td>
            <td class="py-2 pr-4 ui-subtle">{{ row.closed }}</td>
            <td class="py-2 pr-4 ui-subtle">{{ row.avg_quality_score ?? '—' }}</td>
            <td class="py-2 ui-subtle">{{ row.unhappy_count }}</td>
        </tr>
    </DataTable>
</template>
