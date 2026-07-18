<script setup lang="ts">
import { storeToRefs } from 'pinia';
import { Languages } from '@lucide/vue';
import { useLocaleStore } from '../../stores/locale';
import type { Locale } from '../../i18n/messages';

const localeStore = useLocaleStore();
const { available, locale } = storeToRefs(localeStore);
</script>

<template>
    <div class="inline-flex items-center gap-1 rounded-md border p-1 ui-muted">
        <Languages class="ml-2 h-4 w-4 text-zinc-400" />
        <button
            v-for="item in available"
            :key="item.code"
            class="h-8 rounded-sm px-3 text-sm font-semibold transition"
            :class="locale === item.code ? 'bg-[var(--card)] text-[var(--foreground)] shadow-sm' : 'ui-subtle hover:text-[var(--foreground)]'"
            @click="localeStore.setLocale(item.code as Locale)"
        >
            {{ item.label }}
        </button>
    </div>
</template>