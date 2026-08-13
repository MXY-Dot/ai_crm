<script setup lang="ts">
import { ref, watch } from 'vue';
import mammoth from 'mammoth';
import { Loader2 } from '@lucide/vue';

const props = defineProps<{ src: string }>();
const emit = defineEmits<{ (event: 'error', message: string): void }>();

const html = ref('');
const loading = ref(true);
let renderToken = 0;

async function render(): Promise<void> {
    const token = ++renderToken;
    loading.value = true;
    html.value = '';

    try {
        const response = await fetch(props.src, { credentials: 'same-origin' });
        if (! response.ok) throw new Error(`HTTP ${response.status}`);
        const arrayBuffer = await response.arrayBuffer();
        if (token !== renderToken) return;

        const result = await mammoth.convertToHtml({ arrayBuffer });
        if (token !== renderToken) return;
        html.value = result.value;
    } catch (error) {
        emit('error', error instanceof Error ? error.message : 'Не удалось открыть документ Word');
    } finally {
        if (token === renderToken) loading.value = false;
    }
}

watch(() => props.src, render, { immediate: true });
</script>

<template>
    <div class="min-h-40">
        <div v-if="loading" class="flex items-center justify-center gap-2 py-10 ui-subtle">
            <Loader2 class="h-5 w-5 animate-spin" />Открываю документ…
        </div>
        <!-- eslint-disable-next-line vue/no-v-html -->
        <div v-else class="docx-preview mx-auto max-w-full rounded-md border border-border bg-white p-8 text-neutral-900 shadow-sm" v-html="html" />
    </div>
</template>

<style scoped>
.docx-preview :deep(p) {
    margin: 0 0 0.75em;
    line-height: 1.6;
}
.docx-preview :deep(h1),
.docx-preview :deep(h2),
.docx-preview :deep(h3) {
    margin: 1em 0 0.5em;
    font-weight: 600;
}
.docx-preview :deep(table) {
    border-collapse: collapse;
    margin: 0.75em 0;
}
.docx-preview :deep(td),
.docx-preview :deep(th) {
    border: 1px solid #d4d4d4;
    padding: 0.4em 0.6em;
}
.docx-preview :deep(img) {
    max-width: 100%;
}
</style>
