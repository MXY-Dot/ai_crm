<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { onMounted } from 'vue';
import { storeToRefs } from 'pinia';
import { BookOpen, CheckCircle2 } from '@lucide/vue';
import KnowledgeBasePanel from '../components/dashboard/KnowledgeBasePanel.vue';
import { Badge } from '../components/ui/badge';
import { Card } from '../components/ui/card';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const store = useCrmDashboardStore();
const { knowledgeDocuments } = storeToRefs(store);

onMounted(() => {
    void store.refreshDashboard();
});

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-6">
        <Card title="База знаний" subtitle="Документы и тексты, которые AI использует для ответов клиентам.">
            <template #actions>
                <Badge :tone="knowledgeDocuments.length > 0 ? 'green' : 'amber'">
                    {{ knowledgeDocuments.length }} документов
                </Badge>
            </template>
            <p class="flex items-center gap-2 text-sm text-emerald-400">
                <CheckCircle2 class="h-4 w-4" /> TXT, MD, CSV и JSON индексируются сразу; PDF, DOCX и XLSX сохраняются для парсера.
            </p>
        </Card>

        <KnowledgeBasePanel />
    </section>
</template>