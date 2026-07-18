<script setup lang="ts">
import type { KnowledgeDocument } from '../../../stores/crmDashboard';

defineProps<{ documents: KnowledgeDocument[]; labels: Record<string, string> }>();

function countBy(documents: KnowledgeDocument[], status: string): number {
    return documents.filter((document) => document.status === status).length;
}
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-md border border-white/10 bg-white/[0.03] p-3">
            <p class="text-xs text-zinc-500">{{ labels.total }}</p>
            <p class="mt-1 text-2xl font-semibold text-white">{{ documents.length }}</p>
        </div>
        <div class="rounded-md border border-white/10 bg-white/[0.03] p-3">
            <p class="text-xs text-zinc-500">{{ labels.indexed }}</p>
            <p class="mt-1 text-2xl font-semibold text-emerald-200">{{ countBy(documents, 'indexed') }}</p>
        </div>
        <div class="rounded-md border border-white/10 bg-white/[0.03] p-3">
            <p class="text-xs text-zinc-500">{{ labels.queued }}</p>
            <p class="mt-1 text-2xl font-semibold text-sky-200">{{ countBy(documents, 'queued') }}</p>
        </div>
    </div>
</template>