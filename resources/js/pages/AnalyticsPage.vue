<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import type { AiRun, Conversation, Message } from '../stores/crmDashboard';
import { apiRequest } from '../lib/apiClient';
import AiPerformancePanel, { type AiPerformance } from '../components/dashboard/analytics/AiPerformancePanel.vue';
import AiReportsPanel from '../components/dashboard/analytics/AiReportsPanel.vue';
import AnalyticsExportMenu from '../components/dashboard/analytics/AnalyticsExportMenu.vue';
import AnalyticsKpis, { type PeriodKpisFull } from '../components/dashboard/analytics/AnalyticsKpis.vue';
import ConversationFunnelPanel, { type FunnelStage } from '../components/dashboard/analytics/ConversationFunnelPanel.vue';
import DissatisfiedCustomersPanel, { type DissatisfiedCustomerRow } from '../components/dashboard/analytics/DissatisfiedCustomersPanel.vue';
import KnowledgeGapsPanel from '../components/dashboard/analytics/KnowledgeGapsPanel.vue';
import LlmUsagePanel, { type LlmUsageRow } from '../components/dashboard/analytics/LlmUsagePanel.vue';
import LoadHeatmap from '../components/dashboard/analytics/LoadHeatmap.vue';
import LostCustomersPanel, { type LostCustomers } from '../components/dashboard/analytics/LostCustomersPanel.vue';
import MessageLoadDonut from '../components/dashboard/analytics/MessageLoadDonut.vue';
import OperatorsPanel, { type OperatorRow } from '../components/dashboard/analytics/OperatorsPanel.vue';
import OutcomesPanel, { type OutcomeRow } from '../components/dashboard/analytics/OutcomesPanel.vue';
import PriorityBreakdown from '../components/dashboard/analytics/PriorityBreakdown.vue';
import SalesAnalyticsPanel, { type SalesAnalytics } from '../components/dashboard/analytics/SalesAnalyticsPanel.vue';
import SentimentPanel, { type SentimentRow } from '../components/dashboard/analytics/SentimentPanel.vue';
import SlaPanel, { type Sla } from '../components/dashboard/analytics/SlaPanel.vue';
import TopicsPanel, { type TopicRow } from '../components/dashboard/analytics/TopicsPanel.vue';
import DialogsTrendChart from '../components/dashboard/overview/DialogsTrendChart.vue';
import AnalyticsPeriodFilter, { type DatePreset } from '../components/dashboard/analytics/AnalyticsPeriodFilter.vue';
import PeriodComparisonPanel from '../components/dashboard/analytics/PeriodComparisonPanel.vue';
import { Skeleton } from '../components/ui/skeleton';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

type Analytics = {
    raw: { conversations: Conversation[]; messages: Message[]; ai_runs: AiRun[] };
    kpis: PeriodKpisFull;
    previous_kpis: PeriodKpisFull | null;
    ai_performance: AiPerformance;
    sales: SalesAnalytics;
    previous_sales: SalesAnalytics | null;
    llm_usage: LlmUsageRow[];
    sla: Sla;
    outcomes: OutcomeRow[];
    sentiment: SentimentRow[];
    dissatisfied_customers: DissatisfiedCustomerRow[];
    topics: TopicRow[];
    operators: OperatorRow[];
    lost_customers: LostCustomers;
    conversation_funnel: FunnelStage[];
};

const dashboard = useCrmDashboardStore();
const { openTasks, tenant } = storeToRefs(dashboard);
const locale = useLocaleStore();
const exportTarget = ref<HTMLElement | null>(null);

const preset = ref<DatePreset>('this_month');
const customFrom = ref(new Date().toISOString().slice(0, 8) + '01');
const customTo = ref(new Date().toISOString().slice(0, 10));
const compare = ref(false);
const data = ref<Analytics | null>(null);
const loading = ref(true);

function buildParams(): URLSearchParams {
    const params = new URLSearchParams({ preset: preset.value });
    if (preset.value === 'custom') {
        if (customFrom.value) params.set('from', customFrom.value);
        if (customTo.value) params.set('to', customTo.value);
    }
    if (compare.value) params.set('compare', '1');

    return params;
}

// Initialized synchronously (not '') so KnowledgeGapsPanel's own onMounted(load),
// which fires around the same tick as this page's, doesn't race a request out
// with no preset before this page's own load() finishes and sets the real one.
const queryString = ref(buildParams().toString());

async function load(): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    loading.value = true;
    try {
        const params = buildParams();
        queryString.value = params.toString();
        data.value = await apiRequest<Analytics>(`/api/analytics?${queryString.value}`, { tenant: slug });
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить аналитику');
    } finally {
        loading.value = false;
    }
}

onMounted(load);
watch([preset, customFrom, customTo, compare], load);

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('analytics.pageTitle') }}</h2>
                <p class="mt-1 text-sm ui-subtle">{{ locale.t('analytics.pageSubtitle') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <AnalyticsPeriodFilter v-model:preset="preset" v-model:from="customFrom" v-model:to="customTo" v-model:compare="compare" />
                <AnalyticsExportMenu data-tour="analytics-export" :target="exportTarget" :conversations="data?.raw.conversations ?? []" :query-string="queryString" :tenant-slug="tenant?.slug ?? ''" />
            </div>
        </div>

        <div v-if="loading && ! data" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <Skeleton v-for="i in 4" :key="i" class="h-28 rounded-xl" />
        </div>

        <div v-else ref="exportTarget" class="space-y-6 p-1">
            <PeriodComparisonPanel v-if="compare" :current="data?.kpis ?? null" :previous="data?.previous_kpis ?? null" />
            <AnalyticsKpis data-tour="analytics-kpis" :kpis="data?.kpis ?? null" :open-tasks-count="openTasks.length" />
            <AiReportsPanel :tenant-slug="tenant?.slug ?? ''" />
            <ConversationFunnelPanel :data="data?.conversation_funnel ?? null" :loading="loading" />

            <div class="grid gap-6 lg:grid-cols-3" data-tour="analytics-charts">
                <DialogsTrendChart :conversations="data?.raw.conversations ?? []" />
                <MessageLoadDonut :messages="data?.raw.messages ?? []" />
            </div>

            <div class="grid gap-6 lg:grid-cols-2" data-tour="analytics-heatmap">
                <LoadHeatmap :messages="data?.raw.messages ?? []" />
                <PriorityBreakdown :conversations="data?.raw.conversations ?? []" />
            </div>

            <AiPerformancePanel :data="data?.ai_performance ?? null" :loading="loading" />
            <SalesAnalyticsPanel :data="data?.sales ?? null" :previous="data?.previous_sales ?? null" :loading="loading" />

            <div class="grid gap-6 lg:grid-cols-2">
                <OutcomesPanel :data="data?.outcomes ?? null" :loading="loading" />
                <SentimentPanel :data="data?.sentiment ?? null" :loading="loading" />
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <DissatisfiedCustomersPanel :data="data?.dissatisfied_customers ?? null" :loading="loading" @changed="load" />
                <LostCustomersPanel :data="data?.lost_customers ?? null" :loading="loading" />
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <TopicsPanel :data="data?.topics ?? null" :loading="loading" />
                <KnowledgeGapsPanel :query-string="queryString" :tenant-slug="tenant?.slug ?? ''" />
            </div>

            <OperatorsPanel :data="data?.operators ?? null" :loading="loading" />

            <div class="grid gap-6 lg:grid-cols-2">
                <LlmUsagePanel :data="data?.llm_usage ?? null" :loading="loading" />
                <SlaPanel :data="data?.sla ?? null" :loading="loading" />
            </div>
        </div>
    </section>
</template>
