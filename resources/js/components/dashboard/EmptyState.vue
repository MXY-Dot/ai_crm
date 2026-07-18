<script setup lang="ts">
import { useLocaleStore } from '../../stores/locale';

const locale = useLocaleStore();
</script>

<template>
    <section class="rounded-md border border-amber-300/30 bg-amber-300/10 p-5 text-amber-100">
        <h2 class="text-base font-semibold">{{ locale.t('empty.title') }}</h2>
        <p class="mt-2 text-sm text-amber-100/80">{{ locale.t('empty.text') }}</p>
    </section>
</template>