<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Plus, Search } from '@lucide/vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';
import { Button } from '../components/ui/button';
import { Input } from '../components/ui/input';
import { Tabs, TabsList, TabsTrigger } from '../components/ui/tabs';
import CrmQuickForms from '../components/dashboard/CrmQuickForms.vue';
import LeadPipeline from '../components/dashboard/LeadPipeline.vue';
import LeadListTable from '../components/dashboard/leads/LeadListTable.vue';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { leads, customers, selectedLeadId } = storeToRefs(store);
const query = ref('');
const showLeadForm = ref(false);
const view = ref<'kanban' | 'list'>('kanban');

const filteredLeads = computed(() => {
    const normalizedQuery = query.value.trim().toLowerCase();
    if (! normalizedQuery) return leads.value;

    return leads.value.filter((lead) => [lead.title, lead.source, lead.status]
        .some((value) => value?.toLowerCase().includes(normalizedQuery)));
});

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="font-display text-xl font-bold ui-text">{{ locale.t('leads.title') }}</h2>
                <Tabs v-model="view">
                    <TabsList>
                        <TabsTrigger value="kanban">{{ locale.t('leads.kanbanView') }}</TabsTrigger>
                        <TabsTrigger value="list">{{ locale.t('leads.listView') }}</TabsTrigger>
                    </TabsList>
                </Tabs>
            </div>
            <div class="flex gap-2">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 ui-subtle" />
                    <Input v-model="query" class="h-9 pl-9 lg:pl-10" :placeholder="locale.t('leads.searchPlaceholder')" />
                </div>
                <Button size="sm" variant="primary" @click="showLeadForm = true"><Plus class="h-4 w-4" />{{ locale.t('leads.createLead') }}</Button>
            </div>
        </div>
        <CrmQuickForms v-if="showLeadForm" lead-only @lead-created="showLeadForm = false" />
        <LeadPipeline v-if="view === 'kanban'" :leads="filteredLeads" :selected-id="selectedLeadId" @select="store.openLead($event.id)" @add-lead="showLeadForm = true" />
        <LeadListTable v-else :leads="filteredLeads" :customers="customers" @select="store.openLead($event.id)" />
    </section>
</template>
