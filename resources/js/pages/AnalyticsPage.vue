<script setup lang="ts">
import { storeToRefs } from 'pinia';
import { Bot, CheckSquare, MessageSquare, Target, Users } from '@lucide/vue';
import VisualStatCard from '../components/dashboard/visual/VisualStatCard.vue';
import LeadPipeline from '../components/dashboard/LeadPipeline.vue';
import TaskList from '../components/dashboard/TaskList.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const { conversations, customers, leads, openTasks, aiRuns } = storeToRefs(useCrmDashboardStore());
</script>

<template>
    <section class="space-y-6">
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <VisualStatCard label="Диалоги" :value="conversations.length" :icon="MessageSquare" />
            <VisualStatCard label="Клиенты" :value="customers.length" :icon="Users" />
            <VisualStatCard label="Лиды" :value="leads.length" :icon="Target" />
            <VisualStatCard label="AI-запуски" :value="aiRuns.length" :icon="Bot" />
            <VisualStatCard label="Задачи" :value="openTasks.length" :icon="CheckSquare" />
        </div>
        <div class="grid gap-5 xl:grid-cols-[1.1fr_0.9fr]">
            <LeadPipeline />
            <TaskList :tasks="openTasks" />
        </div>
    </section>
</template>