<script setup lang="ts">
import { reactive } from 'vue';
import { Globe } from '@lucide/vue';
import { Button } from '../../ui/button';
import { Input } from '../../ui/input';

defineProps<{ busy: boolean; labels: Record<string, string> }>();
const emit = defineEmits<{ submit: [payload: { url: string }] }>();
const form = reactive({ url: '' });

function submit(): void {
    emit('submit', { ...form });
    form.url = '';
}
</script>

<template>
    <form class="grid gap-3 rounded-xl border p-4 border-border bg-card" @submit.prevent="submit">
        <p class="flex items-center gap-2 text-sm font-medium ui-text"><Globe class="h-4 w-4 text-primary" /> {{ labels.fromUrl }}</p>
        <Input v-model="form.url" type="url" :placeholder="labels.urlPlaceholder" required />
        <Button class="w-full sm:w-fit" variant="primary" type="submit" :disabled="busy || ! form.url"><Globe class="h-4 w-4" />{{ labels.fetchUrl }}</Button>
    </form>
</template>
