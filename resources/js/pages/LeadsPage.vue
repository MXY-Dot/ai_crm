<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Plus, SlidersHorizontal } from '@lucide/vue';
import { Button } from '../components/ui/button';
import LeadPipeline from '../components/dashboard/LeadPipeline.vue';
import CrmQuickForms from '../components/dashboard/CrmQuickForms.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const store = useCrmDashboardStore();
const { leads, selectedLeadId } = storeToRefs(store);
const query = ref('');
const filtersOpen = ref(false);
const stage = ref('all');
const showLeadForm = ref(false);

const filteredLeads = computed(() => {
    const normalizedQuery = query.value.trim().toLowerCase();

    return leads.value.filter((lead) => {
        const matchesQuery = !normalizedQuery || [lead.title, lead.source, lead.status]
            .some((value) => value?.toLowerCase().includes(normalizedQuery));

        return matchesQuery && (stage.value === 'all' || lead.status === stage.value);
    });
});

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold ui-text">Leads</h2>
            <div class="flex gap-2">
                <input v-model="query" class="h-9 rounded-md border bg-transparent px-3 text-sm ui-text" placeholder="Search leads...">
                <Button size="sm" @click="filtersOpen = !filtersOpen"><SlidersHorizontal class="h-4 w-4" />Filters</Button>
                <Button size="sm" variant="primary" @click="showLeadForm = true"><Plus class="h-4 w-4" />New Lead</Button>
            </div>
        </div>
        <div v-if="filtersOpen" class="flex flex-wrap items-center gap-3 rounded-md border p-3 ui-surface">
            <label class="text-sm ui-subtle">Stage / status
                <select v-model="stage" class="ml-2 h-9 rounded-md border bg-transparent px-3 text-sm ui-text">
                    <option value="all">All</option>
                    <option value="new">New</option>
                    <option value="qualified">Qualified</option>
                    <option value="won">Won</option>
                    <option value="lost">Lost</option>
                </select>
            </label>
        </div>
        <CrmQuickForms v-if="showLeadForm" lead-only @lead-created="showLeadForm = false" />
        <LeadPipeline :leads="filteredLeads" :selected-id="selectedLeadId" :show-filters="false" @select="store.openLead($event.id)" @add-lead="showLeadForm = true" />
    </section>
</template>
