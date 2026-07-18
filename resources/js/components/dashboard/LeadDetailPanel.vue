<script setup lang="ts">
import { CheckCircle2, MessageSquare, XCircle } from '@lucide/vue';
import type { Conversation, Customer, Lead, Task } from '../../stores/crmDashboard';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Badge } from '../ui/badge';
import { Card } from '../ui/card';

const props = defineProps<{ lead: Lead | null; customer: Customer | null; tasks: Task[]; conversations: Conversation[] }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();

function closeAs(status: 'won' | 'lost'): void {
    if (props.lead) store.updateLeadStatus(props.lead.id, status);
}
</script>

<template>
    <Card :title="locale.t('leads.detailTitle')" :subtitle="locale.t('leads.detailSubtitle')">
        <p v-if="!lead" class="rounded-md border border-dashed border-white/10 p-5 text-sm text-zinc-400">{{ locale.t('leads.selectLead') }}</p>
        <div v-else class="space-y-5">
            <section class="rounded-md border border-white/10 bg-white/[0.03] p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold text-white">{{ lead.title }}</h3>
                        <p class="mt-1 text-sm text-zinc-400">{{ customer?.name ?? locale.t('crm.noCustomer') }} - {{ lead.source ?? locale.t('common.manual') }}</p>
                    </div>
                    <Badge :tone="lead.status === 'won' ? 'green' : lead.status === 'lost' ? 'amber' : 'blue'">{{ locale.t(`leads.statuses.${lead.status}`) }}</Badge>
                </div>
                <p v-if="lead.ai_summary" class="mt-4 text-sm leading-6 text-zinc-400">{{ lead.ai_summary }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button class="rounded-sm border border-white/10 px-3 py-2 text-xs text-zinc-200 hover:bg-white/10" @click="store.updateLeadStatus(lead.id, 'qualified')">{{ locale.t('crm.qualify') }}</button>
                    <button class="inline-flex items-center gap-2 rounded-sm border border-emerald-300/30 px-3 py-2 text-xs text-emerald-200 hover:bg-emerald-300/10" @click="closeAs('won')"><CheckCircle2 class="h-3.5 w-3.5" />{{ locale.t('crm.win') }}</button>
                    <button class="inline-flex items-center gap-2 rounded-sm border border-amber-300/30 px-3 py-2 text-xs text-amber-200 hover:bg-amber-300/10" @click="closeAs('lost')"><XCircle class="h-3.5 w-3.5" />{{ locale.t('crm.lose') }}</button>
                </div>
            </section>
            <section class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-md border border-white/10 bg-white/[0.03] p-4">
                    <p class="mb-3 font-medium text-white">{{ locale.t('leads.linkedTasks') }}</p>
                    <p v-if="tasks.length === 0" class="text-sm text-zinc-500">{{ locale.t('leads.noLinkedTasks') }}</p>
                    <article v-for="task in tasks" :key="task.id" class="mb-3 rounded-md border border-white/10 p-3 last:mb-0">
                        <p class="font-medium text-white">{{ task.title }}</p>
                        <p class="mt-1 text-xs text-zinc-500">{{ locale.t(`tasks.status.${task.status}`) }}</p>
                    </article>
                </div>
                <div class="rounded-md border border-white/10 bg-white/[0.03] p-4">
                    <p class="mb-3 flex items-center gap-2 font-medium text-white"><MessageSquare class="h-4 w-4 text-emerald-300" />{{ locale.t('leads.linkedConversations') }}</p>
                    <p v-if="conversations.length === 0" class="text-sm text-zinc-500">{{ locale.t('leads.noLinkedConversations') }}</p>
                    <article v-for="conversation in conversations" :key="conversation.id" class="mb-3 rounded-md border border-white/10 p-3 last:mb-0">
                        <p class="font-medium text-white">{{ conversation.subject }}</p>
                        <p v-if="conversation.ai_summary" class="mt-1 line-clamp-2 text-sm text-zinc-400">{{ conversation.ai_summary }}</p>
                    </article>
                </div>
            </section>
        </div>
    </Card>
</template>