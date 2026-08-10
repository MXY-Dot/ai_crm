<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { storeToRefs } from 'pinia';
import AnalyticsKpis from '../components/dashboard/analytics/AnalyticsKpis.vue';
import LoadHeatmap from '../components/dashboard/analytics/LoadHeatmap.vue';
import MessageLoadDonut from '../components/dashboard/analytics/MessageLoadDonut.vue';
import PriorityBreakdown from '../components/dashboard/analytics/PriorityBreakdown.vue';
import DialogsTrendChart from '../components/dashboard/overview/DialogsTrendChart.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const { conversations, openTasks, aiRuns, messages } = storeToRefs(useCrmDashboardStore());

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">Глубокая аналитика</h2>
            <p class="mt-1 text-sm ui-subtle">Обзор эффективности омниканальных коммуникаций</p>
        </div>

        <AnalyticsKpis :conversations="conversations" :open-tasks="openTasks" :ai-runs="aiRuns" />

        <div class="grid gap-6 lg:grid-cols-3">
            <DialogsTrendChart :conversations="conversations" />
            <MessageLoadDonut :messages="messages" />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <LoadHeatmap :messages="messages" />
            <PriorityBreakdown :conversations="conversations" />
        </div>
    </section>
</template>
