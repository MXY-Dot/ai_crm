<script setup lang="ts">
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Card } from '../ui/card';
import KnowledgeDocumentList from './knowledge/KnowledgeDocumentList.vue';
import KnowledgeStats from './knowledge/KnowledgeStats.vue';
import KnowledgeTextForm from './knowledge/KnowledgeTextForm.vue';
import KnowledgeUploadForm from './knowledge/KnowledgeUploadForm.vue';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { knowledgeDocuments, aiAgents, busy, error } = storeToRefs(store);
const query = ref('');

const labels = computed(() => ({
    chunks: locale.t('kb.chunks'),
    contentPlaceholder: locale.t('kb.contentPlaceholder'),
    empty: locale.t('kb.empty'),
    indexText: locale.t('kb.indexText'),
    indexed: locale.t('kb.indexed'),
    optionalTitle: locale.t('kb.optionalTitle'),
    pasteText: locale.t('kb.pasteText'),
    queued: locale.t('kb.queued'),
    search: locale.t('kb.search'),
    titlePlaceholder: locale.t('kb.titlePlaceholder'),
    total: locale.t('kb.total'),
    uploadAndIndex: locale.t('kb.uploadAndIndex'),
    uploadFile: locale.t('kb.uploadFile'),
    uploadHelp: locale.t('kb.uploadHelp'),
}));

const filteredDocuments = computed(() => {
    const value = query.value.trim().toLowerCase();
    if (! value) return knowledgeDocuments.value;
    return knowledgeDocuments.value.filter((document) => [
        document.title,
        document.summary,
        document.file_name,
        document.source_type,
        document.status,
    ].some((field) => (field ?? '').toLowerCase().includes(value)));
});

async function indexText(payload: { title: string; content: string }): Promise<void> {
    await store.indexKnowledgeText({ ...payload, ai_agent_id: aiAgents.value[0]?.id ?? null });
}

async function uploadFile(payload: { title: string; file: File }): Promise<void> {
    await store.uploadKnowledgeFile({ title: payload.title || undefined, file: payload.file, ai_agent_id: aiAgents.value[0]?.id ?? null });
}
</script>

<template>
    <Card :title="locale.t('kb.title')" :subtitle="locale.t('kb.subtitle')">
        <div class="mb-5 grid gap-4 xl:grid-cols-[0.8fr_1.2fr]">
            <KnowledgeStats :documents="knowledgeDocuments" :labels="labels" />
            <input v-model="query" class="h-10 rounded-md border border-white/10 bg-white/5 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300" :placeholder="labels.search">
        </div>

        <div class="mb-5 grid gap-4 lg:grid-cols-2">
            <KnowledgeTextForm :busy="busy" :error="error" :labels="labels" @submit="indexText" />
            <KnowledgeUploadForm :busy="busy" :labels="labels" @submit="uploadFile" />
        </div>

        <KnowledgeDocumentList :documents="filteredDocuments" :labels="labels" />
    </Card>
</template>