<script setup lang="ts">
import { BookOpen, FileText } from '@lucide/vue';
import type { KnowledgeDocument } from '../../../stores/crmDashboard';
import { Badge } from '../../ui/badge';

defineProps<{ documents: KnowledgeDocument[]; labels: Record<string, string> }>();

function tone(status: string): 'green' | 'blue' | 'amber' | 'neutral' {
    if (status === 'indexed') return 'green';
    if (status === 'queued') return 'blue';
    if (status === 'failed') return 'amber';
    return 'neutral';
}
</script>

<template>
    <div class="space-y-3">
        <p v-if="documents.length === 0" class="rounded-md border border-dashed border-white/10 bg-white/[0.02] p-5 text-sm text-zinc-400">{{ labels.empty }}</p>
        <article v-for="document in documents" :key="document.id" class="rounded-md border border-white/10 bg-white/[0.03] p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="flex items-center gap-2 font-medium text-white"><FileText class="h-4 w-4 text-emerald-300" />{{ document.title }}</p>
                    <p class="mt-1 text-xs text-zinc-500">{{ document.file_name ?? document.source_type }} - v{{ document.version }}</p>
                </div>
                <Badge :tone="tone(document.status)">{{ document.status }}</Badge>
            </div>
            <p class="mt-3 text-sm leading-6 text-zinc-400">{{ document.summary }}</p>
            <p class="mt-3 flex items-center gap-2 text-xs text-zinc-500"><BookOpen class="h-3.5 w-3.5" />{{ document.chunks_count }} {{ labels.chunks }}</p>
        </article>
    </div>
</template>