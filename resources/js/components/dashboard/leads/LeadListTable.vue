<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import type { Customer, Lead } from '../../../stores/crmDashboard';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { timeAgo } from '../../../lib/format';
import { sourceLabels } from '../../../lib/statusLabels';
import { Badge } from '../../ui/badge';
import LeadLostDialog from './LeadLostDialog.vue';

const PAGE_SIZE = 20;

const props = defineProps<{ leads: Lead[]; customers: Customer[] }>();
defineEmits<{ select: [lead: Lead] }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();

const page = ref(1);

watch(() => props.leads, () => { page.value = 1; });

const totalPages = computed(() => Math.max(1, Math.ceil(props.leads.length / PAGE_SIZE)));
const pagedLeads = computed(() => props.leads.slice((page.value - 1) * PAGE_SIZE, page.value * PAGE_SIZE));

function goToPage(target: number): void {
    page.value = Math.min(Math.max(target, 1), totalPages.value);
}

function customerName(lead: Lead): string {
    return props.customers.find((customer) => customer.id === lead.customer_id)?.name ?? locale.t('crm.noCustomer');
}

function statusTone(status: string): 'green' | 'blue' | 'amber' | 'red' | 'neutral' {
    if (status === 'won') return 'green';
    if (status === 'qualified') return 'blue';
    if (status === 'lost') return 'red';
    return 'neutral';
}
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-border bg-card">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[52rem] text-left text-sm">
                <thead class="text-xs uppercase ui-subtle bg-muted">
                    <tr>
                        <th class="px-4 py-3">{{ locale.t('leads.title') }}</th>
                        <th class="px-4 py-3">{{ locale.t('leads.columnCompany') }}</th>
                        <th class="px-4 py-3">{{ locale.t('leads.columnStatus') }}</th>
                        <th class="px-4 py-3">{{ locale.t('leads.columnScore') }}</th>
                        <th class="px-4 py-3">{{ locale.t('leads.columnCreated') }}</th>
                        <th class="px-4 py-3">{{ locale.t('leads.columnActions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y border-border">
                    <tr
                        v-for="lead in pagedLeads"
                        :key="lead.id"
                        class="cursor-pointer hover:bg-muted"
                        @click="$emit('select', lead)"
                    >
                        <td class="px-4 py-3 font-medium ui-text">{{ lead.title }}</td>
                        <td class="px-4 py-3 ui-subtle">{{ customerName(lead) }}</td>
                        <td class="px-4 py-3"><Badge :tone="statusTone(lead.status)">{{ locale.t(`leads.statuses.${lead.status}`) }}</Badge></td>
                        <td class="px-4 py-3 ui-subtle">{{ lead.score }}</td>
                        <td class="px-4 py-3 ui-subtle">{{ timeAgo(lead.created_at, locale.locale) || (lead.source ? (sourceLabels[lead.source] ?? lead.source) : '—') }}</td>
                        <td class="px-4 py-3" @click.stop>
                            <div v-if="lead.status !== 'won' && lead.status !== 'lost'" class="flex flex-wrap items-center gap-2">
                                <button
                                    v-if="lead.status === 'new'"
                                    class="rounded-md border px-2 py-1 text-[11px] font-medium ui-text hover:bg-muted disabled:opacity-50 border-border"
                                    :disabled="store.busy"
                                    @click="store.updateLeadStatus(lead.id, 'qualified')"
                                >
                                    Квалифицировать
                                </button>
                                <button
                                    class="rounded-md px-2 py-1 text-[11px] font-medium disabled:opacity-50 bg-primary text-primary-foreground"
                                    :disabled="store.busy"
                                    @click="store.updateLeadStatus(lead.id, 'won')"
                                >
                                    Выиграна
                                </button>
                                <LeadLostDialog :lead-id="lead.id" />
                            </div>
                            <span v-else class="text-xs ui-subtle">—</span>
                        </td>
                    </tr>
                    <tr v-if="! leads.length">
                        <td colspan="6" class="px-4 py-6 text-center ui-subtle">{{ locale.t('common.noResults') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="leads.length > PAGE_SIZE" class="flex items-center justify-between border-t border-border px-4 py-3 text-xs ui-subtle">
            <span>{{ (page - 1) * PAGE_SIZE + 1 }}–{{ Math.min(page * PAGE_SIZE, leads.length) }} / {{ leads.length }}</span>
            <div class="flex items-center gap-1">
                <button
                    type="button"
                    class="grid size-7 place-items-center rounded-md border border-border disabled:opacity-40"
                    :disabled="page <= 1"
                    :aria-label="'Prev'"
                    @click="goToPage(page - 1)"
                >
                    <ChevronLeft class="h-3.5 w-3.5" />
                </button>
                <span class="px-2 font-mono ui-text">{{ page }} / {{ totalPages }}</span>
                <button
                    type="button"
                    class="grid size-7 place-items-center rounded-md border border-border disabled:opacity-40"
                    :disabled="page >= totalPages"
                    :aria-label="'Next'"
                    @click="goToPage(page + 1)"
                >
                    <ChevronRight class="h-3.5 w-3.5" />
                </button>
            </div>
        </div>
    </div>
</template>
