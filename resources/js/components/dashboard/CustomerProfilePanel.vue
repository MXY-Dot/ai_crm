<script setup lang="ts">
import { Mail, MessageSquare, Phone, Target } from '@lucide/vue';
import type { Conversation, Customer, Lead } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Badge } from '../ui/badge';
import { Card } from '../ui/card';

defineProps<{ customer: Customer | null; leads: Lead[]; conversations: Conversation[] }>();
const locale = useLocaleStore();
</script>

<template>
    <Card :title="locale.t('crm.profile')" :subtitle="locale.t('crm.profileSubtitle')">
        <div v-if="!customer" class="rounded-md border border-dashed border-white/10 p-5 text-sm text-zinc-400">
            {{ locale.t('crm.selectCustomer') }}
        </div>
        <div v-else class="space-y-5">
            <section class="rounded-md border border-white/10 bg-white/[0.03] p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold text-white">{{ customer.name }}</h3>
                        <p class="mt-1 text-sm text-zinc-500">{{ customer.source ?? locale.t('common.manual') }}</p>
                    </div>
                    <Badge tone="green">{{ locale.t('crm.activeCustomer') }}</Badge>
                </div>
                <div class="mt-4 grid gap-3 text-sm text-zinc-300 sm:grid-cols-2">
                    <p class="inline-flex items-center gap-2"><Phone class="h-4 w-4 text-emerald-300" />{{ customer.phone ?? locale.t('crm.noPhone') }}</p>
                    <p class="inline-flex items-center gap-2"><Mail class="h-4 w-4 text-emerald-300" />{{ customer.email ?? locale.t('crm.noEmail') }}</p>
                </div>
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-md border border-white/10 bg-white/[0.03] p-4">
                    <p class="mb-3 flex items-center gap-2 font-medium text-white"><Target class="h-4 w-4 text-emerald-300" />{{ locale.t('crm.relatedLeads') }}</p>
                    <div class="space-y-3">
                        <p v-if="leads.length === 0" class="text-sm text-zinc-500">{{ locale.t('crm.noRelatedLeads') }}</p>
                        <article v-for="lead in leads" :key="lead.id" class="rounded-md border border-white/10 p-3">
                            <p class="font-medium text-white">{{ lead.title }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ lead.source ?? locale.t('common.manual') }} - {{ locale.t('common.aiScore') }} {{ lead.score }}</p>
                            <Badge class="mt-2">{{ lead.status }}</Badge>
                        </article>
                    </div>
                </div>

                <div class="rounded-md border border-white/10 bg-white/[0.03] p-4">
                    <p class="mb-3 flex items-center gap-2 font-medium text-white"><MessageSquare class="h-4 w-4 text-emerald-300" />{{ locale.t('crm.relatedConversations') }}</p>
                    <div class="space-y-3">
                        <p v-if="conversations.length === 0" class="text-sm text-zinc-500">{{ locale.t('crm.noRelatedConversations') }}</p>
                        <article v-for="conversation in conversations" :key="conversation.id" class="rounded-md border border-white/10 p-3">
                            <p class="font-medium text-white">{{ conversation.subject }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ conversation.channel?.name ?? conversation.channel?.provider ?? 'CRM' }} - {{ conversation.priority }}</p>
                            <p v-if="conversation.ai_summary" class="mt-2 line-clamp-2 text-sm text-zinc-400">{{ conversation.ai_summary }}</p>
                        </article>
                    </div>
                </div>
            </section>
        </div>
    </Card>
</template>