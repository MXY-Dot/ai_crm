<script setup lang="ts">
import { computed } from 'vue';
import { Database, Sparkles } from '@lucide/vue';
import type { KnowledgeDocument } from '../../../stores/crmDashboard';

const props = defineProps<{ documents: KnowledgeDocument[] }>();

const totalChunks = computed(() => props.documents.reduce((sum, doc) => sum + doc.chunks_count, 0));
const indexedShare = computed(() => (props.documents.length
    ? Math.round((props.documents.filter((doc) => doc.status === 'indexed').length / props.documents.length) * 100)
    : 0));
</script>

<template>
    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border p-5 border-border bg-card">
            <div class="mb-2 flex items-center justify-between">
                <span class="text-[11px] font-semibold uppercase tracking-wider ui-subtle">Всего источников</span>
                <Database class="h-4 w-4 ui-subtle" />
            </div>
            <p class="font-display text-3xl font-bold ui-text">{{ documents.length }}</p>
        </div>
        <div class="rounded-xl border p-5 border-border bg-card">
            <div class="mb-2 flex items-center justify-between">
                <span class="text-[11px] font-semibold uppercase tracking-wider ui-subtle">Фрагментов знаний</span>
                <Database class="h-4 w-4 ui-subtle" />
            </div>
            <p class="font-display text-3xl font-bold ui-text">{{ totalChunks }}</p>
        </div>
        <div class="relative overflow-hidden rounded-xl border p-5 border-primary bg-card">
            <div class="pointer-events-none absolute right-0 top-0 h-16 w-16 rounded-bl-full bg-primary/12" />
            <div class="mb-2 flex items-center justify-between">
                <span class="text-[11px] font-semibold uppercase tracking-wider text-primary">Проиндексировано</span>
                <Sparkles class="h-4 w-4 text-primary" />
            </div>
            <p class="font-display text-3xl font-bold ui-text">{{ indexedShare }}%</p>
        </div>
    </div>
</template>
