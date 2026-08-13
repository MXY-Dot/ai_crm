<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import AnalyticsExportMenu from '../components/dashboard/analytics/AnalyticsExportMenu.vue';
import AnalyticsKpis from '../components/dashboard/analytics/AnalyticsKpis.vue';
import LoadHeatmap from '../components/dashboard/analytics/LoadHeatmap.vue';
import MessageLoadDonut from '../components/dashboard/analytics/MessageLoadDonut.vue';
import PriorityBreakdown from '../components/dashboard/analytics/PriorityBreakdown.vue';
import DialogsTrendChart from '../components/dashboard/overview/DialogsTrendChart.vue';
import DateRangeFilter, { type DateRangeGranularity } from '../components/dashboard/DateRangeFilter.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const { conversations, openTasks, aiRuns, messages } = storeToRefs(useCrmDashboardStore());
const exportTarget = ref<HTMLElement | null>(null);

const granularity = ref<DateRangeGranularity>('month');
const anchorDate = ref(new Date().toISOString().slice(0, 10));

const range = computed(() => {
    const anchor = new Date(anchorDate.value);
    const start = new Date(anchor);
    const end = new Date(anchor);

    if (granularity.value === 'day') {
        start.setHours(0, 0, 0, 0);
        end.setHours(23, 59, 59, 999);
    } else if (granularity.value === 'week') {
        const day = start.getDay() === 0 ? 7 : start.getDay();
        start.setDate(start.getDate() - day + 1);
        start.setHours(0, 0, 0, 0);
        end.setTime(start.getTime());
        end.setDate(end.getDate() + 6);
        end.setHours(23, 59, 59, 999);
    } else {
        start.setDate(1);
        start.setHours(0, 0, 0, 0);
        end.setMonth(end.getMonth() + 1, 0);
        end.setHours(23, 59, 59, 999);
    }

    return { start, end };
});

function withinRange(value: string | null): boolean {
    if (! value) return false;
    const time = new Date(value).getTime();

    return time >= range.value.start.getTime() && time <= range.value.end.getTime();
}

const filteredConversations = computed(() => conversations.value.filter((c) => withinRange(c.last_message_at)));
const filteredMessages = computed(() => messages.value.filter((m) => withinRange(m.sent_at)));

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">Глубокая аналитика</h2>
                <p class="mt-1 text-sm ui-subtle">Обзор эффективности омниканальных коммуникаций</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <DateRangeFilter v-model:granularity="granularity" v-model:anchor="anchorDate" />
                <AnalyticsExportMenu data-tour="analytics-export" :target="exportTarget" :conversations="filteredConversations" />
            </div>
        </div>

        <div ref="exportTarget" class="space-y-6 p-1">
            <AnalyticsKpis data-tour="analytics-kpis" :conversations="filteredConversations" :open-tasks="openTasks" :ai-runs="aiRuns" />

            <div class="grid gap-6 lg:grid-cols-3" data-tour="analytics-charts">
                <DialogsTrendChart :conversations="filteredConversations" />
                <MessageLoadDonut :messages="filteredMessages" />
            </div>

            <div class="grid gap-6 lg:grid-cols-2" data-tour="analytics-heatmap">
                <LoadHeatmap :messages="filteredMessages" />
                <PriorityBreakdown :conversations="filteredConversations" />
            </div>
        </div>
    </section>
</template>
