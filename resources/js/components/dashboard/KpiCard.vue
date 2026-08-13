<script setup lang="ts">
defineProps<{ label: string; value: string | number; hint?: string }>();
defineSlots<{ icon?: () => unknown; hint?: () => unknown }>();
</script>

<template>
    <div class="rounded-xl border border-border bg-card p-4">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase ui-subtle">{{ label }}</span>
            <slot name="icon" />
        </div>
        <p class="mt-3 font-display text-3xl font-bold ui-text">{{ value }}</p>
        <p v-if="hint || $slots.hint" class="mt-1 text-xs ui-subtle"><slot name="hint">{{ hint }}</slot></p>
    </div>
</template>
