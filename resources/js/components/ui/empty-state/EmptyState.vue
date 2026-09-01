<script setup lang="ts">
import type { Component } from 'vue';
import { Badge } from '../badge';

// The same visual language IntegrationsCatalogPage.vue introduced for its
// "coming soon" state -- pulled out here so every whole-panel/page empty
// state in the app looks the same, instead of a bare line of text.
//
// Deliberately NOT wrapped in a Card itself: some call sites (a page with no
// Card yet, e.g. OrdersPage.vue) want this AS a Card; others (ServicesPanel.vue
// and friends) already render their whole content inside one Card, and this
// is just the empty-state content that goes inside it. Wrap at the call site.
defineProps<{ icon: Component; title: string; description?: string; badge?: string }>();
</script>

<template>
    <div class="flex flex-col items-center gap-3 px-6 py-16 text-center">
        <span class="grid h-16 w-16 place-items-center rounded-2xl bg-accent">
            <component :is="icon" class="h-8 w-8 text-accent-foreground" />
        </span>
        <Badge v-if="badge" tone="green">{{ badge }}</Badge>
        <div class="mx-auto max-w-md">
            <h3 class="font-display text-lg font-semibold ui-text">{{ title }}</h3>
            <p v-if="description" class="mt-2 text-sm leading-6 ui-subtle">{{ description }}</p>
        </div>
    </div>
</template>
