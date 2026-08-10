<script setup lang="ts">
import type { Customer, Lead } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { titleCase, timeAgo } from '../../../lib/format';
import { Badge } from '../../ui/badge';

const props = defineProps<{ leads: Lead[]; customers: Customer[] }>();
defineEmits<{ select: [lead: Lead] }>();
const locale = useLocaleStore();

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
    <div class="overflow-x-auto rounded-xl border" style="border-color: var(--border); background: var(--card)">
        <table class="w-full min-w-[48rem] text-left text-sm">
            <thead class="text-xs uppercase ui-subtle" style="background: var(--muted)">
                <tr>
                    <th class="px-4 py-3">{{ locale.t('leads.title') }}</th>
                    <th class="px-4 py-3">{{ locale.t('leads.columnCompany') }}</th>
                    <th class="px-4 py-3">{{ locale.t('leads.columnStatus') }}</th>
                    <th class="px-4 py-3">{{ locale.t('leads.columnScore') }}</th>
                    <th class="px-4 py-3">{{ locale.t('leads.columnCreated') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y" style="border-color: var(--border)">
                <tr
                    v-for="lead in leads"
                    :key="lead.id"
                    class="cursor-pointer hover:bg-muted"
                    @click="$emit('select', lead)"
                >
                    <td class="px-4 py-3 font-medium ui-text">{{ lead.title }}</td>
                    <td class="px-4 py-3 ui-subtle">{{ customerName(lead) }}</td>
                    <td class="px-4 py-3"><Badge :tone="statusTone(lead.status)">{{ locale.t(`leads.statuses.${lead.status}`) }}</Badge></td>
                    <td class="px-4 py-3 ui-subtle">{{ lead.score }}</td>
                    <td class="px-4 py-3 ui-subtle">{{ timeAgo(lead.created_at, locale.locale) || titleCase(lead.source) }}</td>
                </tr>
                <tr v-if="! leads.length">
                    <td colspan="5" class="px-4 py-6 text-center ui-subtle">{{ locale.t('common.noResults') }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
