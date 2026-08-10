<script setup lang="ts">
import { reactive } from 'vue';
import { Plus } from '@lucide/vue';
import { Alert, AlertDescription } from '../../ui/alert';
import { Button } from '../../ui/button';
import { Input } from '../../ui/input';
import { Textarea } from '../../ui/textarea';

defineProps<{ busy: boolean; error: string | null; labels: Record<string, string> }>();
const emit = defineEmits<{ submit: [payload: { title: string; content: string }] }>();
const form = reactive({ title: '', content: '' });

function submit(): void {
    emit('submit', { ...form });
    Object.assign(form, { title: '', content: '' });
}
</script>

<template>
    <form class="grid gap-3 rounded-xl border p-4" style="border-color: var(--border); background: var(--card)" @submit.prevent="submit">
        <p class="flex items-center gap-2 text-sm font-medium ui-text"><Plus class="h-4 w-4 text-primary" /> {{ labels.pasteText }}</p>
        <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
        <Input v-model="form.title" :placeholder="labels.titlePlaceholder" required />
        <Textarea v-model="form.content" class="min-h-28" :placeholder="labels.contentPlaceholder" required />
        <Button class="w-full sm:w-fit" variant="primary" type="submit" :disabled="busy"><Plus class="h-4 w-4" />{{ labels.indexText }}</Button>
    </form>
</template>
