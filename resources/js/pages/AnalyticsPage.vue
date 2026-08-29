<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import type { AiRun, Conversation, Message } from '../stores/crmDashboard';
import { apiRequest } from '../lib/apiClient';
import AiPerformancePanel, { type AiPerformance } from '../components/dashboard/analytics/AiPerformancePanel.vue';
import AnalyticsExportMenu from '../components/dashboard/analytics/AnalyticsExportMenu.vue';
import AnalyticsKpis from '../components/dashboard/analytics/AnalyticsKpis.vue';
import DissatisfiedCustomersPanel, { type DissatisfiedCustomerRow } from '../components/dashboard/analytics/DissatisfiedCustomersPanel.vue';
import KnowledgeGapsPanel from '../components/dashboard/analytics/KnowledgeGapsPanel.vue';
import LlmUsagePanel, { type LlmUsageRow } from '../components/dashboard/analytics/LlmUsagePanel.vue';
import LoadHeatmap from '../components/dashboard/analytics/LoadHeatmap.vue';
import MessageLoadDonut from '../components/dashboard/analytics/MessageLoadDonut.vue';
import OperatorsPanel, { type OperatorRow } from '../components/dashboard/analytics/OperatorsPanel.vue';
import OutcomesPanel, { type OutcomeRow } from '../components/dashboard/analytics/OutcomesPanel.vue';
import PriorityBreakdown from '../components/dashboard/analytics/PriorityBreakdown.vue';
import SalesAnalyticsPanel, { type SalesAnalytics } from '../components/dashboard/analytics/SalesAnalyticsPanel.vue';
import SentimentPanel, { type SentimentRow } from '../components/dashboard/analytics/SentimentPanel.vue';
import SlaPanel, { type Sla } from '../components/dashboard/analytics/SlaPanel.vue';
import TopicsPanel, { type TopicRow } from '../components/dashboard/analytics/TopicsPanel.vue';
import DialogsTrendChart from '../components/dashboard/overview/DialogsTrendChart.vue';
import DateRangeFilter, { type DateRangeGranularity } from '../components/dashboard/DateRangeFilter.vue';
import { Skeleton } from '../components/ui/skeleton';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

type Analytics = {
    raw: { conversations: Conversation[]; messages: Message[]; ai_runs: AiRun[] };
    ai_performance: AiPerformance;
    sales: SalesAnalytics;
    llm_usage: LlmUsageRow[];
    sla: Sla;
    outcomes: OutcomeRow[];
    sentiment: SentimentRow[];
    dissatisfied_customers: DissatisfiedCustomerRow[];
    topics: TopicRow[];
    operators: OperatorRow[];
};

const dashboard = useCrmDashboardStore();
const { openTasks, tenant } = storeToRefs(dashboard);
const locale = useLocaleStore();
const exportTarget = ref<HTMLElement | null>(null);

const granularity = ref<DateRangeGranularity>('month');
const anchorDate = ref(new Date().toISOString().slice(0, 10));
const data = ref<Analytics | null>(null);
const loading = ref(true);

async function load(): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    loading.value = true;
    try {
        const params = new URLSearchParams({ range: granularity.value, date: anchorDate.value });
        data.value = await apiRequest<Analytics>(`/api/analytics?${params.toString()}`, { tenant: slug });
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить аналитику');
    } finally {
        loading.value = false;
    }
}

onMounted(load);
watch([granularity, anchorDate], load);

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
                <DateRangeFilter v-model:granularity="granularity" v-model:anchor="anchorDate" />
                <AnalyticsExportMenu data-tour="analytics-export" :target="exportTarget" :conversations="data?.raw.conversations ?? []" />
            </div>
        </div>

        <div v-if="loading && ! data" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <Skeleton v-for="i in 4" :key="i" class="h-28 rounded-xl" />
        </div>

        <div v-else ref="exportTarget" class="space-y-6 p-1">
            <AnalyticsKpis data-tour="analytics-kpis" :conversations="data?.raw.conversations ?? []" :open-tasks="openTasks" :ai-runs="data?.raw.ai_runs ?? []" />

            <div class="grid gap-6 lg:grid-cols-3" data-tour="analytics-charts">
                <DialogsTrendChart :conversations="data?.raw.conversations ?? []" />
                <MessageLoadDonut :messages="data?.raw.messages ?? []" />
            </div>

            <div class="grid gap-6 lg:grid-cols-2" data-tour="analytics-heatmap">
                <LoadHeatmap :messages="data?.raw.messages ?? []" />
                <PriorityBreakdown :conversations="data?.raw.conversations ?? []" />
            </div>

            <AiPerformancePanel :data="data?.ai_performance ?? null" :loading="loading" />
            <SalesAnalyticsPanel :data="data?.sales ?? null" :loading="loading" />

            <div class="grid gap-6 lg:grid-cols-2">
                <OutcomesPanel :data="data?.outcomes ?? null" :loading="loading" />
                <SentimentPanel :data="data?.sentiment ?? null" :loading="loading" />
            </div>

            <DissatisfiedCustomersPanel :data="data?.dissatisfied_customers ?? null" :loading="loading" @changed="load" />

            <div class="grid gap-6 lg:grid-cols-2">
                <TopicsPanel :data="data?.topics ?? null" :loading="loading" />
                <KnowledgeGapsPanel :granularity="granularity" :anchor-date="anchorDate" :tenant-slug="tenant?.slug ?? ''" />
            </div>

            <OperatorsPanel :data="data?.operators ?? null" :loading="loading" />

            <div class="grid gap-6 lg:grid-cols-2">
                <LlmUsagePanel :data="data?.llm_usage ?? null" :loading="loading" />
                <SlaPanel :data="data?.sla ?? null" :loading="loading" />
            </div>
        </div>
    </section>
</template>
