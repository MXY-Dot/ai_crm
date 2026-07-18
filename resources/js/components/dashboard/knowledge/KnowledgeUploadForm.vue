<script setup lang="ts">
import { reactive, ref } from 'vue';
import { Upload } from '@lucide/vue';
import { Button } from '../../ui/button';

defineProps<{ busy: boolean; labels: Record<string, string> }>();
const emit = defineEmits<{ submit: [payload: { title: string; file: File }] }>();
const fileInput = ref<HTMLInputElement | null>(null);
const upload = reactive<{ title: string; file: File | null }>({ title: '', file: null });

function selectFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    upload.file = input.files?.[0] ?? null;
    if (upload.file && upload.title === '') upload.title = upload.file.name.replace(/\.[^.]+$/, '');
}

function submit(): void {
    if (! upload.file) return;
    emit('submit', { title: upload.title, file: upload.file });
    upload.title = '';
    upload.file = null;
    if (fileInput.value) fileInput.value.value = '';
}
</script>

<template>
    <form class="grid content-start gap-3 rounded-md border border-white/10 bg-white/[0.03] p-4" @submit.prevent="submit">
        <p class="flex items-center gap-2 text-sm font-medium text-white"><Upload class="h-4 w-4 text-emerald-300" /> {{ labels.uploadFile }}</p>
        <input v-model="upload.title" class="h-10 rounded-md border border-white/10 bg-white/5 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300" :placeholder="labels.optionalTitle">
        <input ref="fileInput" type="file" accept=".txt,.md,.csv,.json,.pdf,.docx,.xlsx" class="block rounded-md border border-dashed border-white/15 bg-white/5 px-3 py-3 text-sm text-zinc-300 file:mr-3 file:rounded-md file:border-0 file:bg-emerald-300 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-950" required @change="selectFile">
        <p class="text-xs leading-5 text-zinc-500">{{ labels.uploadHelp }}</p>
        <Button class="w-full sm:w-fit" variant="primary" type="submit" :disabled="busy || !upload.file"><Upload class="h-4 w-4" />{{ labels.uploadAndIndex }}</Button>
    </form>
</template>