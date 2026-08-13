<script setup lang="ts">
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Search } from '@lucide/vue';
import { Input } from '../ui/input';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import KnowledgeSourcesTable from './knowledge/KnowledgeSourcesTable.vue';
import KnowledgeStatsBento from './knowledge/KnowledgeStatsBento.vue';
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
    <div class="space-y-6">
        <KnowledgeStatsBento data-tour="kb-stats" :documents="knowledgeDocuments" />

        <div class="relative">
            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 ui-subtle" />
            <Input v-model="query" class="h-10 pl-9 lg:pl-10" :placeholder="labels.search" />
        </div>

        <div class="grid gap-4 lg:grid-cols-2" data-tour="kb-add">
            <KnowledgeTextForm :busy="busy" :error="error" :labels="labels" @submit="indexText" />
            <KnowledgeUploadForm :busy="busy" :labels="labels" @submit="uploadFile" />
        </div>

        <div data-tour="kb-sources">
            <KnowledgeSourcesTable :documents="filteredDocuments" />
        </div>
    </div>
</template>
