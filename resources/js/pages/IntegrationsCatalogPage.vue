<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Blocks } from '@lucide/vue';
import { useLocaleStore } from '../stores/locale';
import { Badge } from '../components/ui/badge';
import { Card } from '../components/ui/card';

const locale = useLocaleStore();

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('nav.marketplace') }}</h2>
            <p class="mt-1 text-sm ui-subtle">{{ locale.t('marketplace.subtitle') }}</p>
        </div>

        <Card class="flex flex-col items-center gap-4 px-6 py-20 text-center gap-3">
            <span class="grid h-16 w-16 place-items-center rounded-2xl mx-auto" style="background: var(--accent)">
                <Blocks class="h-8 w-8" style="color: var(--accent-foreground)" />
            </span>
            <Badge tone="green">{{ locale.t('marketplace.badge') }}</Badge>
            <div class="mx-auto max-w-md">
                <h3 class="font-display text-lg font-semibold ui-text">{{ locale.t('marketplace.title') }}</h3>
                <p class="mt-2 text-sm leading-6 ui-subtle">{{ locale.t('marketplace.description') }}</p>
            </div>
        </Card>
    </section>
</template>
