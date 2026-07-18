<script setup lang="ts">
import { storeToRefs } from 'pinia';
import { Bot, CheckSquare, MessageSquare, Target, Users } from '@lucide/vue';
import VisualStatCard from '../components/dashboard/visual/VisualStatCard.vue';
import LeadPipeline from '../components/dashboard/LeadPipeline.vue';
import TaskList from '../components/dashboard/TaskList.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const store = useCrmDashboardStore();
const { customers, leads, conversations, openTasks, aiRuns } = storeToRefs(store);
</script>

<template>
    <section class="space-y-6">
        <header class="rounded-md border p-5 ui-surface">
            <p class="text-sm font-medium text-blue-400">Рабочий стол</p>
            <h2 class="mt-2 text-2xl font-semibold ui-text">Сводка по CRM</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 ui-subtle">Только живые данные: чаты, клиенты, лиды, AI-запуски и задачи.</p>
        </header>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <VisualStatCard label="Чаты" :value="conversations.length" :icon="MessageSquare" />
            <VisualStatCard label="Клиенты" :value="customers.length" :icon="Users" />
            <VisualStatCard label="Лиды" :value="leads.length" :icon="Target" />
            <VisualStatCard label="AI-запуски" :value="aiRuns.length" :icon="Bot" />
            <VisualStatCard label="Открытые задачи" :value="openTasks.length" :icon="CheckSquare" />
        </div>

        <div class="grid gap-5 ">
            <LeadPipeline />
            <TaskList :tasks="openTasks" />
        </div>
    </section>
</template>