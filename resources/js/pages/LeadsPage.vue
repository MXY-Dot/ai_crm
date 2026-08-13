<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Kanban, List, Plus, Search } from '@lucide/vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';
import { Button } from '../components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../components/ui/dialog';
import { Input } from '../components/ui/input';
import { Tabs, TabsList, TabsTrigger } from '../components/ui/tabs';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '../components/ui/tooltip';
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
                <TooltipProvider :delay-duration="200">
                    <Tabs v-model="view" data-tour="leads-toggle">
                        <TabsList class="h-10 p-1 md:h-11">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <TabsTrigger value="kanban" class="h-8 gap-1.5 px-3 md:h-9 md:px-4" :aria-label="locale.t('leads.kanbanView')">
                                        <Kanban class="h-4 w-4 md:h-[1.1rem] md:w-[1.1rem]" />
                                        <span class="hidden sm:inline">{{ locale.t('leads.kanbanView') }}</span>
                                    </TabsTrigger>
                                </TooltipTrigger>
                                <TooltipContent class="sm:hidden">{{ locale.t('leads.kanbanView') }}</TooltipContent>
                            </Tooltip>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <TabsTrigger value="list" class="h-8 gap-1.5 px-3 md:h-9 md:px-4" :aria-label="locale.t('leads.listView')">
                                        <List class="h-4 w-4 md:h-[1.1rem] md:w-[1.1rem]" />
                                        <span class="hidden sm:inline">{{ locale.t('leads.listView') }}</span>
                                    </TabsTrigger>
                                </TooltipTrigger>
                                <TooltipContent class="sm:hidden">{{ locale.t('leads.listView') }}</TooltipContent>
                            </Tooltip>
                        </TabsList>
                    </Tabs>
                </TooltipProvider>
            </div>
            <div class="flex gap-2">
                <div class="relative" data-tour="leads-search">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 ui-subtle" />
                    <Input v-model="query" class="h-9 pl-9 lg:pl-10" :placeholder="locale.t('leads.searchPlaceholder')" />
                </div>
                <Button data-tour="leads-create" size="sm" variant="primary" @click="showLeadForm = true"><Plus class="h-4 w-4" />{{ locale.t('leads.createLead') }}</Button>
            </div>
        </div>
        <Dialog v-model:open="showLeadForm">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ locale.t('crm.newLead') }}</DialogTitle>
                </DialogHeader>
                <CrmQuickForms lead-only bare @lead-created="showLeadForm = false" />
            </DialogContent>
        </Dialog>
        <div data-tour="leads-board">
            <LeadPipeline v-if="view === 'kanban'" :leads="filteredLeads" :selected-id="selectedLeadId" @select="store.openLead($event.id)" @add-lead="showLeadForm = true" />
            <LeadListTable v-else :leads="filteredLeads" :customers="customers" @select="store.openLead($event.id)" />
        </div>
    </section>
</template>
