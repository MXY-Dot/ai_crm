<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Blocks } from '@lucide/vue';
import { useLocaleStore } from '../stores/locale';
import { Card } from '../components/ui/card';
import { EmptyState } from '../components/ui/empty-state';

const locale = useLocaleStore();

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('nav.marketplace') }}</h2>
            <p class="mt-1 text-sm ui-subtle">{{ locale.t('marketplace.subtitle') }}</p>
        </div>

        <Card class="p-0">
            <EmptyState :icon="Blocks" :title="locale.t('marketplace.title')" :description="locale.t('marketplace.description')" :badge="locale.t('marketplace.badge')" />
        </Card>
    </section>
</template>
