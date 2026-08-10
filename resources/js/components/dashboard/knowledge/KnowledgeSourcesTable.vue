<script setup lang="ts">
import { FileText, Globe2, Notebook } from '@lucide/vue';
import type { KnowledgeDocument } from '../../../stores/crmDashboard';
import { Badge } from '../../ui/badge';
import DeleteDocumentButton from './DeleteDocumentButton.vue';

defineProps<{ documents: KnowledgeDocument[] }>();

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
    <div class="overflow-hidden rounded-xl border border-border bg-card">
        <div class="border-b p-4 border-border bg-muted">
            <h3 class="font-display text-base font-semibold ui-text">Источники данных</h3>
        </div>
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="text-[11px] font-semibold uppercase tracking-wider ui-subtle">
                    <th class="w-10 px-4 py-3"></th>
                    <th class="px-2 py-3 font-medium">Название</th>
                    <th class="px-4 py-3 font-medium">Тип</th>
                    <th class="px-4 py-3 font-medium">Статус</th>
                    <th class="px-4 py-3 text-right font-medium">Фрагменты</th>
                    <th class="px-4 py-3 text-right font-medium">Обновлено</th>
                    <th class="w-10 px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="document in documents" :key="document.id" class="border-t transition hover:bg-muted border-border">
                    <td class="px-4 py-3"><component :is="icon(document)" class="h-4 w-4 ui-subtle" /></td>
                    <td class="px-2 py-3">
                        <div class="font-medium ui-text">{{ document.title }}</div>
                        <div class="mt-0.5 text-xs ui-subtle">{{ document.file_name ?? document.summary ?? '—' }}</div>
                    </td>
                    <td class="px-4 py-3 ui-subtle">{{ typeLabel(document) }}</td>
                    <td class="px-4 py-3"><Badge :tone="tone(document.status)">{{ document.status }}</Badge></td>
                    <td class="px-4 py-3 text-right font-mono ui-text">{{ document.chunks_count }}</td>
                    <td class="px-4 py-3 text-right ui-subtle">{{ dateLabel(document.updated_at) }}</td>
                    <td class="px-4 py-3 text-right"><DeleteDocumentButton :document-id="document.id" :title="document.title" /></td>
                </tr>
                <tr v-if="! documents.length">
                    <td colspan="7" class="px-4 py-6 text-center ui-subtle">Источники не найдены</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
