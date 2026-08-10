<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { onMounted } from 'vue';
import KnowledgeBasePanel from '../components/dashboard/KnowledgeBasePanel.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const store = useCrmDashboardStore();

onMounted(() => {
    void store.refreshDashboard();
});

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">База знаний</h2>
            <p class="mt-2 max-w-2xl text-sm leading-6 ui-subtle">Управляйте источниками данных для обучения вашего AI-ассистента. Чем качественнее данные, тем точнее ответы. TXT, MD, CSV и JSON индексируются сразу; PDF, DOCX и XLSX сохраняются для парсера.</p>
        </div>

        <KnowledgeBasePanel />
    </section>
</template>
