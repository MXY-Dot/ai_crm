<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import { Plus, SlidersHorizontal } from '@lucide/vue';
import { Button } from '../components/ui/button';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const store = useCrmDashboardStore();
const { leads } = storeToRefs(store);
const columns = computed(() => ['new', 'contacted', 'qualified', 'won'].map((status) => ({
    status,
    title: status === 'new' ? 'New' : status[0].toUpperCase() + status.slice(1),
    items: leads.value.filter((lead) => status === 'contacted' ? lead.status === 'open' : lead.status === status),
})));

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold ui-text">Leads</h2>
            <div class="flex gap-2"><Button size="sm"><SlidersHorizontal class="h-4 w-4" />Filters</Button><Button size="sm" variant="primary"><Plus class="h-4 w-4" />New Lead</Button></div>
        </div>
        <div class="grid gap-4 xl:grid-cols-4">
            <section v-for="column in columns" :key="column.status" class="rounded-lg border p-3 ui-surface">
                <div class="mb-3 flex items-center justify-between"><h3 class="text-sm font-semibold ui-text">{{ column.title }}</h3><span class="text-xs ui-subtle">{{ column.items.length }}</span></div>
                <article v-for="lead in column.items" :key="lead.id" class="mb-3 rounded-md border p-3 ui-muted">
                    <p class="font-medium ui-text">{{ lead.title }}</p><p class="mt-1 text-xs ui-subtle">Source: {{ lead.source ?? 'Website' }}</p><p class="mt-2 text-xs text-blue-400">{{ lead.score }} AI score</p>
                </article>
                <button class="h-9 w-full rounded-md border text-sm ui-subtle hover:text-[var(--foreground)]" type="button">+ Add Lead</button>
            </section>
        </div>
    </section>
</template>
