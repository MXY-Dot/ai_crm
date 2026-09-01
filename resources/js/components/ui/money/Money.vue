<script setup lang="ts">
import { useLocaleStore } from '@/stores/locale';

withDefaults(defineProps<{ value: number | string; tone?: 'default' | 'muted' | 'lg'; negative?: boolean }>(), {
    tone: 'default',
});

const locale = useLocaleStore();
</script>

<template>
    <span
        class="font-semibold tabular-nums"
        :class="tone === 'lg' ? 'text-lg ui-text' : tone === 'muted' ? 'text-xs font-medium ui-subtle' : 'text-sm ui-text'"
    >{{ negative ? '−' : '' }}{{ value }}<span class="ml-1 text-[0.85em] font-normal ui-subtle">{{ locale.t('commerce.currency') }}</span></span>
</template>
