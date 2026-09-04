<script setup lang="ts">
import { computed } from 'vue';
import type { Conversation, Customer, Lead } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { channelTone, timeAgo } from '../../../lib/format';
import { sourceLabels } from '../../../lib/statusLabels';
import { Avatar, AvatarFallback } from '../../ui/avatar';
import { Badge } from '../../ui/badge';
import DataTable from '../DataTable.vue';

const props = defineProps<{ customers: Customer[]; leads: Lead[]; conversations: Conversation[] }>();
defineEmits<{ select: [customer: Customer] }>();
const locale = useLocaleStore();

function leadCount(customerId: number): number {
    return props.leads.filter((lead) => lead.customer_id === customerId).length;
}

function conversationCount(customerId: number): number {
    return props.conversations.filter((conversation) => conversation.customer?.id === customerId).length;
}

const rows = computed(() => props.customers.map((customer) => ({
    customer,
    leads: leadCount(customer.id),
    conversations: conversationCount(customer.id),
})));
</script>

<template>
    <DataTable
        :row-count="rows.length"
        :column-count="6"
        :empty-message="locale.t('common.noResults')"
        min-width="min-w-[52rem]"
    >
        <template #thead>
            <th class="px-4 py-3">{{ locale.t('contacts.columnName') }}</th>
            <th class="px-4 py-3">{{ locale.t('contacts.columnContact') }}</th>
            <th class="px-4 py-3">{{ locale.t('contacts.columnSource') }}</th>
            <th class="px-4 py-3">{{ locale.t('contacts.columnLeads') }}</th>
            <th class="px-4 py-3">{{ locale.t('contacts.columnConversations') }}</th>
            <th class="px-4 py-3">{{ locale.t('leads.columnCreated') }}</th>
        </template>

        <tr
            v-for="row in rows"
            :key="row.customer.id"
            class="cursor-pointer hover:bg-muted"
            @click="$emit('select', row.customer)"
        >
            <td class="px-4 py-3">
                <div class="flex items-center gap-2">
                    <Avatar class="size-8">
                        <AvatarFallback class="text-[11px] font-semibold bg-accent text-accent-foreground">{{ row.customer.name.slice(0, 2).toUpperCase() }}</AvatarFallback>
                    </Avatar>
                    <span class="font-medium ui-text">{{ row.customer.name }}</span>
                </div>
            </td>
            <td class="px-4 py-3 ui-subtle">
                <p>{{ row.customer.email ?? locale.t('crm.noEmail') }}</p>
                <p class="text-xs">{{ row.customer.phone ?? locale.t('crm.noPhone') }}</p>
            </td>
            <td class="px-4 py-3"><Badge :tone="channelTone(row.customer.source)">{{ row.customer.source ? (sourceLabels[row.customer.source] ?? row.customer.source) : '—' }}</Badge></td>
            <td class="px-4 py-3 ui-subtle">{{ row.leads }}</td>
            <td class="px-4 py-3 ui-subtle">{{ row.conversations }}</td>
            <td class="px-4 py-3 ui-subtle">{{ timeAgo(row.customer.created_at, locale.locale) }}</td>
        </tr>
    </DataTable>
</template>
