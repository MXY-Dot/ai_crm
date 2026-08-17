<script setup lang="ts">
import { computed } from 'vue';
import { CircleDollarSign } from '@lucide/vue';
import { Skeleton } from '../../ui/skeleton';
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
    <div class="rounded-xl border p-5 border-border bg-card">
        <div class="mb-1 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <CircleDollarSign class="h-4 w-4 text-primary" />
                <h3 class="font-display text-base font-semibold ui-text">{{ locale.t('analytics.llmUsage.title') }}</h3>
            </div>
            <span v-if="data" class="text-xs ui-subtle">{{ formatMoney(totalCost) }} · {{ totalTokens.toLocaleString('ru-RU') }} {{ locale.t('analytics.llmUsage.tokens') }} · {{ totalRequests }} {{ locale.t('analytics.llmUsage.requests') }}</span>
        </div>
        <p class="mb-5 text-xs ui-subtle">{{ locale.t('analytics.llmUsage.subtitle') }}</p>

        <Skeleton v-if="loading" class="h-32 rounded-lg" />
        <p v-else-if="! rows.length" class="rounded-lg border border-dashed p-4 text-center text-sm border-border ui-subtle">{{ locale.t('analytics.llmUsage.empty') }}</p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-xs uppercase border-border ui-subtle">
                        <th class="py-2 pr-3 font-semibold">{{ locale.t('analytics.llmUsage.provider') }}</th>
                        <th class="py-2 pr-3 font-semibold">{{ locale.t('analytics.llmUsage.requests') }}</th>
                        <th class="py-2 pr-3 font-semibold">{{ locale.t('analytics.llmUsage.tokensInOut') }}</th>
                        <th class="py-2 pr-3 font-semibold">{{ locale.t('analytics.llmUsage.cost') }}</th>
                        <th class="py-2 pr-3 font-semibold">{{ locale.t('analytics.llmUsage.latency') }}</th>
                        <th class="py-2 font-semibold">{{ locale.t('analytics.llmUsage.errors') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.provider" class="border-b last:border-0 border-border">
                        <td class="py-2.5 pr-3 font-medium ui-text">{{ providerLabels[row.provider] ?? row.provider }}</td>
                        <td class="py-2.5 pr-3 font-mono ui-subtle">{{ row.requests }}</td>
                        <td class="py-2.5 pr-3 font-mono ui-subtle">{{ row.tokens_in.toLocaleString('ru-RU') }} / {{ row.tokens_out.toLocaleString('ru-RU') }}</td>
                        <td class="py-2.5 pr-3 font-mono ui-subtle">{{ formatMoney(row.cost_usd) }}</td>
                        <td class="py-2.5 pr-3 font-mono ui-subtle">{{ formatLatency(row.avg_latency_ms) }}</td>
                        <td class="py-2.5 font-mono" :class="row.errors > 0 ? 'text-destructive' : 'ui-subtle'">{{ row.errors }} ({{ row.error_rate }}%)</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
