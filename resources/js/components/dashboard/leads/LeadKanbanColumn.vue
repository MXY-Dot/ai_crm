<script setup lang="ts">
import type { Customer, Lead } from '../../../stores/crmDashboard';
import LeadCard from './LeadCard.vue';

const props = defineProps<{ title: string; leads: Lead[]; customers: Customer[]; selectedId?: number | null }>();
defineEmits<{ select: [lead: Lead] }>();

function customerName(lead: Lead): string | null {
    return props.customers.find((customer) => customer.id === lead.customer_id)?.name ?? null;
}
</script>

<template>
    <div class="flex h-full w-[300px] shrink-0 flex-col">
        <div class="mb-3 flex items-center justify-between px-1">
            <h2 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider ui-subtle">
                {{ title }}
                <span class="rounded-full px-2 py-0.5 text-[11px] font-bold ui-text" style="background: var(--muted)">{{ leads.length }}</span>
            </h2>
        </div>
        <div class="flex-1 space-y-3 overflow-y-auto pb-4">
            <LeadCard
                v-for="lead in leads"
                :key="lead.id"
                :lead="lead"
                :customer-name="customerName(lead)"
                :selected="selectedId === lead.id"
                @select="$emit('select', lead)"
            />
            <p v-if="! leads.length" class="rounded-xl border border-dashed p-4 text-center text-xs ui-subtle" style="border-color: var(--border)">Пусто</p>
        </div>
    </div>
</template>
