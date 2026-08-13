<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { Dialog, DialogContent, DialogTitle, DialogTrigger } from '@/components/ui/dialog';

const props = defineProps<{ src: string; alt?: string; class?: HTMLAttributes['class'] }>();
</script>

<template>
    <Dialog>
        <DialogTrigger as-child>
            <button type="button" class="block cursor-zoom-in" :class="props.class">
                <slot />
            </button>
        </DialogTrigger>
        <DialogContent class="max-w-3xl border-none bg-transparent p-0 shadow-none ring-0 sm:max-w-3xl">
            <DialogTitle class="sr-only">{{ alt || 'Изображение' }}</DialogTitle>
            <img :src="src" :alt="alt || ''" class="max-h-[85vh] w-full rounded-lg object-contain">
        </DialogContent>
    </Dialog>
</template>
