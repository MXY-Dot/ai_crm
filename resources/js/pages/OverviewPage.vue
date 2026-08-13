<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { storeToRefs } from 'pinia';
import AiAttentionCard from '../components/dashboard/overview/AiAttentionCard.vue';
import ChannelStatusCard from '../components/dashboard/overview/ChannelStatusCard.vue';
import ChannelsDonutCard from '../components/dashboard/overview/ChannelsDonutCard.vue';
import DialogsTrendChart from '../components/dashboard/overview/DialogsTrendChart.vue';
import OverviewKpis from '../components/dashboard/overview/OverviewKpis.vue';
import RecentConversationsCard from '../components/dashboard/overview/RecentConversationsCard.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const store = useCrmDashboardStore();
const { customers, leads, conversations, channels, openTasks, aiRuns, aiHandoffs } = storeToRefs(store);

const today = computed(() => new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date()));

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-end justify-between">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">Обзор панель</h2>
                <p class="mt-1 text-sm ui-subtle">Сегодня, {{ today }}</p>
            </div>
            <span class="rounded-lg border px-3 py-1.5 text-xs font-medium ui-subtle border-border">{{ customers.length }} клиентов</span>
        </div>

        <OverviewKpis data-tour="ov-kpis" :conversations="conversations" :leads="leads" :open-tasks="openTasks" :ai-runs="aiRuns" />

        <div class="grid gap-6 lg:grid-cols-3" data-tour="ov-charts">
            <DialogsTrendChart :conversations="conversations" />
            <ChannelsDonutCard :conversations="conversations" />
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <RecentConversationsCard data-tour="ov-recent" :conversations="conversations" />
            <div class="flex flex-col gap-6" data-tour="ov-attention">
                <AiAttentionCard :ai-handoffs="aiHandoffs" />
                <ChannelStatusCard :channels="channels" />
            </div>
        </div>
    </section>
</template>
