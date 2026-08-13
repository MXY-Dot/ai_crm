<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import * as pdfjsLib from 'pdfjs-dist';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { Loader2 } from '@lucide/vue';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

const props = defineProps<{ src: string }>();
const emit = defineEmits<{ (event: 'error', message: string): void }>();

const container = ref<HTMLDivElement | null>(null);
const loading = ref(true);
let renderToken = 0;

async function render(): Promise<void> {
    const token = ++renderToken;
    loading.value = true;

    try {
        const response = await fetch(props.src, { credentials: 'same-origin' });
        if (! response.ok) throw new Error(`HTTP ${response.status}`);
        const buffer = await response.arrayBuffer();
        if (token !== renderToken) return;

        const pdf = await pdfjsLib.getDocument({ data: buffer }).promise;
        if (token !== renderToken || ! container.value) return;

        container.value.innerHTML = '';

        for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
            const page = await pdf.getPage(pageNumber);
            const viewport = page.getViewport({ scale: 1.3 });
            const canvas = window.document.createElement('canvas');
            canvas.className = 'mx-auto mb-4 max-w-full rounded-md shadow-sm border border-border';
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            const context = canvas.getContext('2d');
            if (! context) continue;

            await page.render({ canvas, canvasContext: context, viewport }).promise;
            if (token !== renderToken || ! container.value) return;
            container.value.appendChild(canvas);
        }
    } catch (error) {
        emit('error', error instanceof Error ? error.message : 'Не удалось открыть PDF');
    } finally {
        if (token === renderToken) loading.value = false;
    }
}

watch(() => props.src, render, { immediate: true });

onBeforeUnmount(() => {
    renderToken++;
});
</script>

<template>
    <div class="relative min-h-40">
        <div v-if="loading" class="flex items-center justify-center gap-2 py-10 ui-subtle">
            <Loader2 class="h-5 w-5 animate-spin" />Открываю PDF…
        </div>
        <div ref="container" />
    </div>
</template>
