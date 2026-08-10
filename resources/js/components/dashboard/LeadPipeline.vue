<script setup lang="ts">
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import type { Lead } from '../../stores/crmDashboard';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import LeadKanbanColumn from './leads/LeadKanbanColumn.vue';

const props = defineProps<{ selectedId?: number | null; leads?: Lead[] }>();
const emit = defineEmits<{ select: [lead: Lead]; addLead: [] }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { leads: allLeads, customers } = storeToRefs(store);

const sourceLeads = computed(() => props.leads ?? allLeads.value);
const columns = computed(() => ([
    { status: 'new', title: locale.t('leads.statuses.new') },
    { status: 'qualified', title: locale.t('leads.statuses.qualified') },
    { status: 'won', title: locale.t('leads.statuses.won') },
    { status: 'lost', title: locale.t('leads.statuses.lost') },
] as const).map((column) => ({
    ...column,
    leads: sourceLeads.value.filter((lead) => lead.status === column.status),
})));

function selectLead(lead: Lead): void {
    emit('select', lead);
}
</script>

<template>
    <div class="rounded-xl border" style="border-color: var(--border); background: var(--card)">
        <div class="flex items-center justify-between border-b p-4" style="border-color: var(--border)">
            <div>
                <h2 class="font-display text-base font-semibold ui-text">{{ locale.t('leads.title') }}</h2>
                <p class="text-sm ui-subtle">{{ locale.t('leads.subtitle') }}</p>
            </div>
            <button class="rounded-lg px-3 py-1.5 text-sm font-medium" style="background: var(--primary); color: var(--primary-foreground)" @click="emit('addLead')">+ {{ locale.t('leads.title') }}</button>
        </div>
        <div v-if="sourceLeads.length" class="flex gap-4 overflow-x-auto p-4">
            <LeadKanbanColumn
                v-for="column in columns"
                :key="column.status"
                :title="column.title"
                :leads="column.leads"
                :customers="customers"
                :selected-id="selectedId"
                @select="selectLead"
            />
        </div>
        <div v-else class="p-6 text-center">
            <p class="text-sm ui-subtle">No leads found.</p>
        </div>
    </div>
</template>
