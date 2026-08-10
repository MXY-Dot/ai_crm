<script setup lang="ts">
import { computed } from 'vue';
import type { Conversation, Customer, Lead } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { channelTone, titleCase, timeAgo } from '../../../lib/format';
import { Avatar, AvatarFallback } from '../../ui/avatar';
import { Badge } from '../../ui/badge';

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
    <div class="overflow-x-auto rounded-xl border" style="border-color: var(--border); background: var(--card)">
        <table class="w-full min-w-[52rem] text-left text-sm">
            <thead class="text-xs uppercase ui-subtle" style="background: var(--muted)">
                <tr>
                    <th class="px-4 py-3">{{ locale.t('contacts.columnName') }}</th>
                    <th class="px-4 py-3">{{ locale.t('contacts.columnContact') }}</th>
                    <th class="px-4 py-3">{{ locale.t('contacts.columnSource') }}</th>
                    <th class="px-4 py-3">{{ locale.t('contacts.columnLeads') }}</th>
                    <th class="px-4 py-3">{{ locale.t('contacts.columnConversations') }}</th>
                    <th class="px-4 py-3">{{ locale.t('leads.columnCreated') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y" style="border-color: var(--border)">
                <tr
                    v-for="row in rows"
                    :key="row.customer.id"
                    class="cursor-pointer hover:bg-muted"
                    @click="$emit('select', row.customer)"
                >
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <Avatar class="size-8">
                                <AvatarFallback class="text-[11px] font-semibold" style="background: var(--accent); color: var(--accent-foreground)">{{ row.customer.name.slice(0, 2).toUpperCase() }}</AvatarFallback>
                            </Avatar>
                            <span class="font-medium ui-text">{{ row.customer.name }}</span>
                        </div>
                    </td>
                    <td class="px-4 py-3 ui-subtle">
                        <p>{{ row.customer.email ?? locale.t('crm.noEmail') }}</p>
                        <p class="text-xs">{{ row.customer.phone ?? locale.t('crm.noPhone') }}</p>
                    </td>
                    <td class="px-4 py-3"><Badge :tone="channelTone(row.customer.source)">{{ titleCase(row.customer.source) }}</Badge></td>
                    <td class="px-4 py-3 ui-subtle">{{ row.leads }}</td>
                    <td class="px-4 py-3 ui-subtle">{{ row.conversations }}</td>
                    <td class="px-4 py-3 ui-subtle">{{ timeAgo(row.customer.created_at, locale.locale) }}</td>
                </tr>
                <tr v-if="! rows.length">
                    <td colspan="6" class="px-4 py-6 text-center ui-subtle">{{ locale.t('common.noResults') }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
