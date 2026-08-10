<script setup lang="ts">
import type { Component } from 'vue';
import { TrendingUp } from '@lucide/vue';

defineProps<{ label: string; value: string | number; delta?: string; icon?: Component }>();
</script>

<template>
    <article class="rounded-md border p-4 ui-surface">
        <div class="flex items-start justify-between gap-3">
            <p class="text-xs font-medium uppercase tracking-wide ui-subtle">{{ label }}</p>
            <component :is="icon ?? TrendingUp" class="h-4 w-4 text-primary" />
        </div>
        <p class="mt-4 text-3xl font-semibold tracking-tight ui-text">{{ value }}</p>
        <p v-if="delta" class="mt-1 text-xs font-medium text-emerald-400">{{ delta }}</p>
    </article>
</template>