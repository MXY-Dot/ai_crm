<script setup lang="ts">
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import { Mail, Phone, Star } from '@lucide/vue';
import { Badge } from '../components/ui/badge';
import { Card } from '../components/ui/card';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const { customers, conversations, leads } = storeToRefs(useCrmDashboardStore());
const customer = computed(() => customers.value[0] ?? null);
</script>

<template>
    <Card title="Customer Profile" subtitle="Full customer context for operators and AI handoff.">
        <div v-if="customer" class="grid gap-5 xl:grid-cols-[0.65fr_1fr]">
            <section class="rounded-lg border p-5 ui-muted">
                <div class="flex items-center gap-4"><div class="grid h-20 w-20 place-items-center rounded-full bg-blue-600 text-2xl font-semibold text-white">{{ customer.name[0] }}</div><div><h2 class="text-xl font-semibold ui-text">{{ customer.name }}</h2><Badge tone="green">Active</Badge></div></div>
                <div class="mt-5 space-y-3 text-sm ui-subtle"><p class="flex gap-2"><Phone class="h-4 w-4" />{{ customer.phone ?? 'No phone' }}</p><p class="flex gap-2"><Mail class="h-4 w-4" />{{ customer.email ?? 'No email' }}</p><p class="flex gap-2"><Star class="h-4 w-4" />VIP Customer</p></div>
            </section>
            <section class="rounded-lg border p-5 ui-muted"><h3 class="font-semibold ui-text">Activity</h3><div class="mt-4 divide-y" style="border-color: var(--border)"><p v-for="item in [...conversations, ...leads].slice(0, 5)" :key="`${'subject' in item ? 'c' : 'l'}-${item.id}`" class="py-3 text-sm ui-subtle">{{ 'subject' in item ? item.subject : item.title }}</p></div></section>
        </div>
    </Card>
</template>
