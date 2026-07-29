<script setup lang="ts">
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import type { Lead } from '../../stores/crmDashboard';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Badge } from '../ui/badge';
import { Card } from '../ui/card';
import { ToggleGroup, ToggleGroupItem } from '../ui/toggle-group';

const props = withDefaults(defineProps<{ selectedId?: number | null; leads?: Lead[]; showFilters?: boolean }>(), {
    showFilters: true,
});
const emit = defineEmits<{ select: [lead: Lead]; addLead: [] }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { filteredLeads, leadStatus, busy } = storeToRefs(store);
const statuses = computed(() => ['all', 'new', 'qualified', 'won', 'lost'].map((status) => ({ value: status, label: locale.t(`leads.statuses.${status}`) })));
const displayedLeads = computed(() => props.leads ?? filteredLeads.value);

function selectLead(lead: Lead): void {
    emit('select', lead);
}
</script>

<template>
    <Card :title="locale.t('leads.title')" :subtitle="locale.t('leads.subtitle')">
        <template v-if="props.showFilters" #actions>
            <ToggleGroup v-model="leadStatus" type="single" variant="outline" size="sm" class="flex-wrap">
                <ToggleGroupItem v-for="status in statuses" :key="status.value" :value="status.value">{{ status.label }}</ToggleGroupItem>
            </ToggleGroup>
        </template>
        <div v-if="displayedLeads.length" class="divide-y divide-white/10">
            <article v-for="lead in displayedLeads" :key="lead.id" :class="['rounded-md border p-3', selectedId === lead.id ? 'border-emerald-300/40 bg-emerald-300/10' : 'border-transparent hover:bg-white/[0.04]']">
                <button class="block w-full text-left focus:outline-none focus:ring-2 focus:ring-emerald-300/50" type="button" :aria-label="`Select ${lead.title}`" @click="selectLead(lead)">
                    <div>
                        <p class="font-medium text-white">{{ lead.title }}</p>
                        <p class="mt-1 text-sm text-zinc-400">{{ lead.source ?? locale.t('common.manual') }} - {{ locale.t('common.aiScore') }} {{ lead.score }}</p>
                        <p v-if="lead.ai_summary" class="mt-2 max-w-2xl text-sm text-zinc-500">{{ lead.ai_summary }}</p>
                    </div>
                </button>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <Badge :tone="lead.status === 'qualified' ? 'green' : 'blue'">{{ locale.t(`leads.statuses.${lead.status}`) }}</Badge>
                    <button class="rounded-sm border border-white/10 px-2 py-1 text-xs text-zinc-300 hover:bg-white/10 disabled:opacity-50" :disabled="busy" @click="store.updateLeadStatus(lead.id, 'qualified')">{{ locale.t('crm.qualify') }}</button>
                    <button class="rounded-sm border border-white/10 px-2 py-1 text-xs text-zinc-300 hover:bg-white/10 disabled:opacity-50" :disabled="busy" @click="store.updateLeadStatus(lead.id, 'won')">{{ locale.t('crm.win') }}</button>
                </div>
            </article>
        </div>
        <div v-else class="rounded-md border border-dashed p-6 text-center">
            <p class="text-sm text-zinc-400">No leads found.</p>
            <button class="mt-3 rounded-md border px-3 py-2 text-sm text-zinc-200 hover:bg-white/10" type="button" @click="emit('addLead')">+ Add lead</button>
        </div>
    </Card>
</template>