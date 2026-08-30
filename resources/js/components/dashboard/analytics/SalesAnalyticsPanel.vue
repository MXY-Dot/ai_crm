<script setup lang="ts">
import { computed } from 'vue';
import { VisArea, VisAxis, VisXYContainer } from '@unovis/vue';
import { TrendingDown, TrendingUp } from '@lucide/vue';
import { ChartContainer, ChartCrosshair, ChartTooltip, ChartTooltipContent, componentToString, type ChartConfig } from '../../ui/chart';
import { Progress } from '../../ui/progress';
import { Skeleton } from '../../ui/skeleton';
import { useLocaleStore } from '../../../stores/locale';

export type SalesAnalytics = {
    total_revenue: number;
    won_count: number;
    avg_deal_size: number;
    funnel: { status: 'new' | 'qualified' | 'won' | 'lost'; count: number; amount_sum: number }[];
    by_source: { source: string; count: number }[];
    trend: { date: string; label: string; amount: number }[];
};

const props = defineProps<{ data: SalesAnalytics | null; loading: boolean; previous?: SalesAnalytics | null }>();
const locale = useLocaleStore();

const statusLabels: Record<string, string> = { new: 'Новые', qualified: 'Квалифицированные', won: 'Выиграны', lost: 'Потеряны' };
const sourceLabels: Record<string, string> = { website: 'Сайт', telegram: 'Telegram', whatsapp: 'WhatsApp', instagram: 'Instagram', chatwoot: 'Единый инбокс', unknown: 'Не указан' };

const maxFunnelCount = computed(() => Math.max(1, ...(props.data?.funnel.map((r) => r.count) ?? [1])));
const maxSourceCount = computed(() => Math.max(1, ...(props.data?.by_source.map((r) => r.count) ?? [1])));

type Delta = { text: string; trend: 'up' | 'down' } | undefined;

function delta(curr: number, prev: number): Delta {
    if (prev === 0) return curr > 0 ? { text: 'новое за период', trend: 'up' } : undefined;

    const percent = Math.round(((curr - prev) / prev) * 100);
    if (percent === 0) return undefined;

    return { text: `${percent > 0 ? '+' : ''}${percent}% к прошлому периоду`, trend: percent >= 0 ? 'up' : 'down' };
}

const revenueDelta = computed<Delta>(() => (props.data && props.previous ? delta(props.data.total_revenue, props.previous.total_revenue) : undefined));
const wonCountDelta = computed<Delta>(() => (props.data && props.previous ? delta(props.data.won_count, props.previous.won_count) : undefined));
const avgDealDelta = computed<Delta>(() => (props.data && props.previous ? delta(props.data.avg_deal_size, props.previous.avg_deal_size) : undefined));

type TrendPoint = { date: string; label: string; amount: number };
const trendConfig: ChartConfig = { amount: { label: 'Выручка', color: 'var(--primary)' } };
const trendData = computed<TrendPoint[]>(() => props.data?.trend ?? []);

function formatMoney(value: number): string {
    return value.toLocaleString('ru-RU');
}
</script>

<template>
    <div class="rounded-xl border p-5 border-border bg-card">
        <div class="mb-1 flex items-center gap-2">
            <TrendingUp class="h-4 w-4 text-primary" />
            <h3 class="font-display text-base font-semibold ui-text">{{ locale.t('analytics.sales.title') }}</h3>
        </div>
        <p class="mb-5 text-xs ui-subtle">{{ locale.t('analytics.sales.subtitle') }}</p>

        <Skeleton v-if="loading" class="h-64 rounded-lg" />
        <template v-else-if="data">
            <div class="grid grid-cols-3 gap-3 text-center">
                <div class="rounded-lg border p-3 border-border">
                    <p class="font-display text-2xl font-bold ui-text">{{ formatMoney(data.total_revenue) }}</p>
                    <p class="mt-1 text-xs ui-subtle">{{ locale.t('analytics.sales.revenue') }}</p>
                    <p v-if="revenueDelta" class="mt-1 flex items-center justify-center gap-0.5 text-xs font-medium" :class="revenueDelta.trend === 'down' ? 'text-destructive' : 'text-primary'">
                        <component :is="revenueDelta.trend === 'down' ? TrendingDown : TrendingUp" class="h-3.5 w-3.5" />{{ revenueDelta.text }}
                    </p>
                </div>
                <div class="rounded-lg border p-3 border-border">
                    <p class="font-display text-2xl font-bold ui-text">{{ data.won_count }}</p>
                    <p class="mt-1 text-xs ui-subtle">{{ locale.t('analytics.sales.wonCount') }}</p>
                    <p v-if="wonCountDelta" class="mt-1 flex items-center justify-center gap-0.5 text-xs font-medium" :class="wonCountDelta.trend === 'down' ? 'text-destructive' : 'text-primary'">
                        <component :is="wonCountDelta.trend === 'down' ? TrendingDown : TrendingUp" class="h-3.5 w-3.5" />{{ wonCountDelta.text }}
                    </p>
                </div>
                <div class="rounded-lg border p-3 border-border">
                    <p class="font-display text-2xl font-bold ui-text">{{ formatMoney(data.avg_deal_size) }}</p>
                    <p class="mt-1 text-xs ui-subtle">{{ locale.t('analytics.sales.avgDeal') }}</p>
                    <p v-if="avgDealDelta" class="mt-1 flex items-center justify-center gap-0.5 text-xs font-medium" :class="avgDealDelta.trend === 'down' ? 'text-destructive' : 'text-primary'">
                        <component :is="avgDealDelta.trend === 'down' ? TrendingDown : TrendingUp" class="h-3.5 w-3.5" />{{ avgDealDelta.text }}
                    </p>
                </div>
            </div>

            <ChartContainer :config="trendConfig" class="mt-5 h-48 w-full">
                <VisXYContainer :data="trendData">
                    <VisArea
                        :x="(d: TrendPoint, i: number) => i"
                        :y="(d: TrendPoint) => d.amount"
                        :color="trendConfig.amount.color"
                        :opacity="0.15"
                        :line="true"
                        :line-width="2.5"
                        :line-color="trendConfig.amount.color"
                    />
                    <VisAxis type="x" :x="(d: TrendPoint, i: number) => i" :tick-line="false" :domain-line="false" :grid-line="false" :num-ticks="6" :tick-format="(i: number) => trendData[i]?.label ?? ''" />
                    <VisAxis type="y" :tick-format="() => ''" :tick-line="false" :domain-line="false" :grid-line="true" />
                    <ChartTooltip />
                    <ChartCrosshair :template="componentToString(trendConfig, ChartTooltipContent)" :color="[trendConfig.amount.color]" />
                </VisXYContainer>
            </ChartContainer>

            <div class="mt-5 grid gap-6 lg:grid-cols-2">
                <div>
                    <p class="mb-3 text-xs font-semibold uppercase ui-subtle">{{ locale.t('analytics.sales.funnel') }}</p>
                    <div class="space-y-3">
                        <div v-for="row in data.funnel" :key="row.status">
                            <div class="mb-1.5 flex items-center justify-between text-sm">
                                <span class="ui-text">{{ statusLabels[row.status] }}</span>
                                <span class="font-mono text-xs ui-subtle">{{ row.count }}<template v-if="row.amount_sum"> · {{ formatMoney(row.amount_sum) }}</template></span>
                            </div>
                            <Progress :model-value="(row.count / maxFunnelCount) * 100" />
                        </div>
                    </div>
                </div>
                <div>
                    <p class="mb-3 text-xs font-semibold uppercase ui-subtle">{{ locale.t('analytics.sales.bySource') }}</p>
                    <div v-if="data.by_source.length" class="space-y-3">
                        <div v-for="row in data.by_source" :key="row.source">
                            <div class="mb-1.5 flex items-center justify-between text-sm">
                                <span class="ui-text">{{ sourceLabels[row.source] ?? row.source }}</span>
                                <span class="font-mono text-xs ui-subtle">{{ row.count }}</span>
                            </div>
                            <Progress :model-value="(row.count / maxSourceCount) * 100" />
                        </div>
                    </div>
                    <p v-else class="text-sm ui-subtle">{{ locale.t('analytics.sales.noSource') }}</p>
                </div>
            </div>
        </template>
    </div>
</template>
