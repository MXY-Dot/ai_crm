<script setup lang="ts">
import { FileText, Globe2, Notebook } from '@lucide/vue';
import type { KnowledgeDocument } from '../../../stores/crmDashboard';
import { Badge } from '../../ui/badge';
import DataTable from '../DataTable.vue';
import DeleteDocumentButton from './DeleteDocumentButton.vue';
import { knowledgeStatusLabels } from '../../../lib/statusLabels';

defineProps<{ documents: KnowledgeDocument[] }>();
const emit = defineEmits<{ (event: 'open', documentId: number): void }>();

function icon(document: KnowledgeDocument) {
    if (document.file_name) return FileText;
    if (document.source_type === 'manual') return Notebook;

    return Globe2;
}

function typeLabel(document: KnowledgeDocument): string {
    if (document.file_name) return 'Документ';
    if (document.source_type === 'manual') return 'Текст';

    return 'Ссылка';
}

function tone(status: string): 'green' | 'blue' | 'amber' | 'neutral' {
    if (status === 'indexed') return 'green';
    if (status === 'queued') return 'blue';
    if (status === 'failed') return 'amber';

    return 'neutral';
}

function dateLabel(value: string | null): string {
    return value ? new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '—';
}
</script>

<template>
    <DataTable :row-count="documents.length" :column-count="7" empty-message="Источники не найдены" min-width="">
        <template #toolbar>
            <h3 class="font-display text-base font-semibold ui-text">Источники данных</h3>
        </template>

        <template #thead>
            <th class="w-10 px-4 py-3"></th>
            <th class="px-2 py-3">Название</th>
            <th class="px-4 py-3">Тип</th>
            <th class="px-4 py-3">Статус</th>
            <th class="px-4 py-3 text-right">Фрагменты</th>
            <th class="px-4 py-3 text-right">Обновлено</th>
            <th class="w-10 px-4 py-3"></th>
        </template>

        <tr
            v-for="document in documents"
            :key="document.id"
            class="cursor-pointer transition hover:bg-muted"
            @click="emit('open', document.id)"
        >
            <td class="px-4 py-3"><component :is="icon(document)" class="h-4 w-4 ui-subtle" /></td>
            <td class="px-2 py-3">
                <div class="font-medium ui-text">{{ document.title }}</div>
                <div class="mt-0.5 text-xs ui-subtle">{{ document.file_name ?? document.summary ?? '—' }}</div>
            </td>
            <td class="px-4 py-3 ui-subtle">{{ typeLabel(document) }}</td>
            <td class="px-4 py-3"><Badge :tone="tone(document.status)">{{ knowledgeStatusLabels[document.status] ?? document.status }}</Badge></td>
            <td class="px-4 py-3 text-right font-mono ui-text">{{ document.chunks_count }}</td>
            <td class="px-4 py-3 text-right ui-subtle">{{ dateLabel(document.updated_at) }}</td>
            <td class="px-4 py-3 text-right" @click.stop><DeleteDocumentButton :document-id="document.id" :title="document.title" /></td>
        </tr>
    </DataTable>
</template>
