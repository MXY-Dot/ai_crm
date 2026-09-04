<script setup lang="ts">
import { computed } from 'vue';
import { CircleDollarSign } from '@lucide/vue';
import DataTable from '../DataTable.vue';
import { useLocaleStore } from '../../../stores/locale';

export type LlmUsageRow = {
    provider: string;
    requests: number;
    tokens_in: number;
    tokens_out: number;
    cost_usd: number;
    avg_latency_ms: number;
    errors: number;
    error_rate: number;
};

const props = defineProps<{ data: LlmUsageRow[] | null; loading: boolean }>();
const locale = useLocaleStore();

const providerLabels: Record<string, string> = { groq: 'Groq', openai: 'GPT', anthropic: 'Claude', google: 'Gemini', deepseek: 'DeepSeek' };

const rows = computed(() => (props.data ?? []).filter((row) => row.requests > 0 || row.errors > 0));
const totalCost = computed(() => (props.data ?? []).reduce((sum, row) => sum + row.cost_usd, 0));
const totalTokens = computed(() => (props.data ?? []).reduce((sum, row) => sum + row.tokens_in + row.tokens_out, 0));
const totalRequests = computed(() => (props.data ?? []).reduce((sum, row) => sum + row.requests, 0));

function formatMoney(value: number): string {
    return '$' + value.toFixed(value < 1 ? 4 : 2);
}

function formatLatency(ms: number): string {
    return ms >= 1000 ? `${(ms / 1000).toFixed(1)} с` : `${ms} мс`;
}
</script>

<template>
    <DataTable
        :loading="loading"
        :row-count="rows.length"
        :column-count="6"
        :empty-message="locale.t('analytics.llmUsage.empty')"
        min-width=""
    >
        <template #toolbar>
            <div class="flex items-center gap-2">
                <CircleDollarSign class="h-4 w-4 text-primary" />
                <div>
                    <h3 class="font-display text-base font-semibold ui-text">{{ locale.t('analytics.llmUsage.title') }}</h3>
                    <p class="text-xs ui-subtle">{{ locale.t('analytics.llmUsage.subtitle') }}</p>
                </div>
            </div>
            <span v-if="data" class="text-xs ui-subtle">{{ formatMoney(totalCost) }} · {{ totalTokens.toLocaleString('ru-RU') }} {{ locale.t('analytics.llmUsage.tokens') }} · {{ totalRequests }} {{ locale.t('analytics.llmUsage.requests') }}</span>
        </template>

        <template #thead>
            <th class="py-2 pr-3">{{ locale.t('analytics.llmUsage.provider') }}</th>
            <th class="py-2 pr-3">{{ locale.t('analytics.llmUsage.requests') }}</th>
            <th class="py-2 pr-3">{{ locale.t('analytics.llmUsage.tokensInOut') }}</th>
            <th class="py-2 pr-3">{{ locale.t('analytics.llmUsage.cost') }}</th>
            <th class="py-2 pr-3">{{ locale.t('analytics.llmUsage.latency') }}</th>
            <th class="py-2">{{ locale.t('analytics.llmUsage.errors') }}</th>
        </template>

        <tr v-for="row in rows" :key="row.provider">
            <td class="py-2.5 pr-3 font-medium ui-text">{{ providerLabels[row.provider] ?? row.provider }}</td>
            <td class="py-2.5 pr-3 font-mono ui-subtle">{{ row.requests }}</td>
            <td class="py-2.5 pr-3 font-mono ui-subtle">{{ row.tokens_in.toLocaleString('ru-RU') }} / {{ row.tokens_out.toLocaleString('ru-RU') }}</td>
            <td class="py-2.5 pr-3 font-mono ui-subtle">{{ formatMoney(row.cost_usd) }}</td>
            <td class="py-2.5 pr-3 font-mono ui-subtle">{{ formatLatency(row.avg_latency_ms) }}</td>
            <td class="py-2.5 font-mono" :class="row.errors > 0 ? 'text-destructive' : 'ui-subtle'">{{ row.errors }} ({{ row.error_rate }}%)</td>
        </tr>
    </DataTable>
</template>
