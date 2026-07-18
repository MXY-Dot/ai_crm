<script setup lang="ts">
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import { Bot, CheckCircle2, MessageSquare, Send, UserRound, Workflow } from '@lucide/vue';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Badge } from '../ui/badge';
import { Card } from '../ui/card';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { conversations, customers, leads, messages } = storeToRefs(store);

const hasChatwootConversation = computed(() => conversations.value.some((item) => item.external_id));
const hasCustomerMessage = computed(() => messages.value.some((item) => item.sender_type === 'customer'));
const hasAiDraft = computed(() => messages.value.some((item) => item.sender_type === 'ai'));
const hasOperatorReply = computed(() => messages.value.some((item) => item.sender_type === 'operator'));
const hasCrmLinks = computed(() => customers.value.length > 0 && leads.value.length > 0 && conversations.value.some((item) => item.customer && item.lead));

const steps = computed(() => [
    { key: 'chatwoot', ok: hasChatwootConversation.value, icon: MessageSquare },
    { key: 'customer', ok: hasCustomerMessage.value, icon: UserRound },
    { key: 'draft', ok: hasAiDraft.value, icon: Bot },
    { key: 'reply', ok: hasOperatorReply.value, icon: Send },
    { key: 'links', ok: hasCrmLinks.value, icon: Workflow },
]);
const done = computed(() => steps.value.filter((step) => step.ok).length);
const percent = computed(() => Math.round((done.value / steps.value.length) * 100));
</script>

<template>
    <Card :title="locale.t('demoFlow.title')" :subtitle="locale.t('demoFlow.subtitle')">
        <template #actions>
            <Badge :tone="percent >= 80 ? 'green' : percent >= 50 ? 'amber' : 'red'">{{ percent }}%</Badge>
        </template>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <article v-for="step in steps" :key="step.key" class="rounded-md border border-white/10 bg-white/[0.03] p-3">
                <div class="flex items-center justify-between gap-2">
                    <component :is="step.icon" class="h-4 w-4" :class="step.ok ? 'text-emerald-300' : 'text-amber-300'" />
                    <CheckCircle2 v-if="step.ok" class="h-4 w-4 text-emerald-300" />
                </div>
                <p class="mt-3 text-sm font-medium text-white">{{ locale.t(`demoFlow.${step.key}.title`) }}</p>
                <p class="mt-1 text-xs leading-5 text-zinc-400">{{ locale.t(`demoFlow.${step.key}.${step.ok ? 'ok' : 'todo'}`) }}</p>
            </article>
        </div>
    </Card>
</template>