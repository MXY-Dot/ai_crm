<script setup lang="ts">
import { reactive, ref } from 'vue';
import { Upload } from '@lucide/vue';
import { Button } from '../../ui/button';
import { Input } from '../../ui/input';

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
    <form class="grid content-start gap-3 rounded-xl border p-4 border-border bg-card" @submit.prevent="submit">
        <p class="flex items-center gap-2 text-sm font-medium ui-text"><Upload class="h-4 w-4 text-primary" /> {{ labels.uploadFile }}</p>
        <Input v-model="upload.title" :placeholder="labels.optionalTitle" />
        <input
            ref="fileInput"
            type="file"
            accept=".txt,.md,.csv,.json,.pdf,.docx,.xlsx"
            class="block rounded-lg border border-dashed px-3 py-3 text-sm ui-text file:mr-3 file:rounded-md file:border-0 file:px-3 file:py-2 file:text-sm file:font-medium border-border"

            required
            @change="selectFile"
        >
        <p class="text-xs leading-5 ui-subtle">{{ labels.uploadHelp }}</p>
        <Button class="w-full sm:w-fit" variant="primary" type="submit" :disabled="busy || !upload.file"><Upload class="h-4 w-4" />{{ labels.uploadAndIndex }}</Button>
    </form>
</template>
