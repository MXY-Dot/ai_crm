<script setup lang="ts">
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { Check, Copy } from '@lucide/vue';

withDefaults(defineProps<{ code: string; label?: string; wrap?: boolean }>(), {
    wrap: false,
});

const copied = ref(false);

async function copy(code: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(code);
        copied.value = true;
        toast.success('Скопировано');
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        toast.error('Не удалось скопировать');
    }
}
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-border bg-muted/40">
        <div class="flex items-center justify-between gap-2 border-b border-border bg-muted px-3 py-1.5">
            <span class="truncate text-xs font-medium ui-subtle">{{ label ?? 'Код' }}</span>
            <button
                type="button"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-md px-1.5 py-0.5 text-xs font-medium text-primary transition hover:bg-primary/10"
                @click="copy(code)"
            >
                <Check v-if="copied" class="h-3.5 w-3.5" />
                <Copy v-else class="h-3.5 w-3.5" />
                {{ copied ? 'Скопировано' : 'Копировать' }}
            </button>
        </div>
        <pre
            class="overflow-x-auto p-3 text-xs leading-5 ui-text"
            :class="wrap ? 'whitespace-pre-wrap break-all' : ''"
        ><code class="font-mono">{{ code }}</code></pre>
    </div>
</template>
