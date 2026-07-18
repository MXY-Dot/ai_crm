<script setup lang="ts">
import { reactive } from 'vue';
import { Plus } from '@lucide/vue';
import { Button } from '../../ui/button';

defineProps<{ busy: boolean; error: string | null; labels: Record<string, string> }>();
const emit = defineEmits<{ submit: [payload: { title: string; content: string }] }>();
const form = reactive({ title: '', content: '' });

function submit(): void {
    emit('submit', { ...form });
    Object.assign(form, { title: '', content: '' });
}
</script>

<template>
    <form class="grid gap-3 rounded-md border border-white/10 bg-white/[0.03] p-4" @submit.prevent="submit">
        <p class="flex items-center gap-2 text-sm font-medium text-white"><Plus class="h-4 w-4 text-emerald-300" /> {{ labels.pasteText }}</p>
        <p v-if="error" class="rounded-md border border-red-300/30 bg-red-300/10 p-3 text-sm text-red-100">{{ error }}</p>
        <input v-model="form.title" class="h-10 rounded-md border border-white/10 bg-white/5 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300" :placeholder="labels.titlePlaceholder" required>
        <textarea v-model="form.content" class="min-h-28 rounded-md border border-white/10 bg-white/5 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-emerald-300" :placeholder="labels.contentPlaceholder" required />
        <Button class="w-full sm:w-fit" variant="primary" type="submit" :disabled="busy"><Plus class="h-4 w-4" />{{ labels.indexText }}</Button>
    </form>
</template>