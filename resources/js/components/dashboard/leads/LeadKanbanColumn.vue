<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { Customer, Lead } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import LeadCard from './LeadCard.vue';

const PAGE_SIZE = 10;

const props = defineProps<{ title: string; leads: Lead[]; customers: Customer[]; selectedId?: number | null }>();
defineEmits<{ select: [lead: Lead] }>();
const locale = useLocaleStore();

const visibleCount = ref(PAGE_SIZE);

watch(() => props.leads, () => { visibleCount.value = PAGE_SIZE; });

const visibleLeads = computed(() => props.leads.slice(0, visibleCount.value));
const hasMore = computed(() => props.leads.length > visibleCount.value);

function showMore(): void {
    visibleCount.value += PAGE_SIZE;
}

function customerName(lead: Lead): string | null {
    return props.customers.find((customer) => customer.id === lead.customer_id)?.name ?? null;
}
</script>

<template>
    <div class="flex h-full w-[300px] shrink-0 flex-col">
        <div class="mb-3 flex items-center justify-between px-1">
            <h2 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider ui-subtle">
                {{ title }}
                <span class="rounded-full px-2 py-0.5 text-[11px] font-bold ui-text bg-muted">{{ leads.length }}</span>
            </h2>
        </div>
        <div class="flex-1 space-y-3 overflow-y-auto pb-4">
            <LeadCard
                v-for="lead in visibleLeads"
                :key="lead.id"
                :lead="lead"
                :customer-name="customerName(lead)"
                :selected="selectedId === lead.id"
                @select="$emit('select', lead)"
            />
            <button
                v-if="hasMore"
                type="button"
                class="w-full rounded-xl border border-dashed border-border p-2.5 text-xs font-medium ui-subtle transition hover:border-primary/40 hover:text-primary"
                @click="showMore"
            >
                {{ locale.t('leads.showMore') }} ({{ leads.length - visibleCount }})
            </button>
            <p v-if="! leads.length" class="rounded-xl border border-dashed p-4 text-center text-xs ui-subtle border-border">Пусто</p>
        </div>
    </div>
</template>
