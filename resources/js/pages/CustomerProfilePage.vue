<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { storeToRefs } from 'pinia';
import { Mail, MessageSquare, Phone, Target } from '@lucide/vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';
import { channelTone, titleCase, timeAgo } from '../lib/format';
import { Avatar, AvatarFallback } from '../components/ui/avatar';
import { Badge } from '../components/ui/badge';
import { Card } from '../components/ui/card';
import { Separator } from '../components/ui/separator';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { customers, conversations, leads, selectedCustomerId } = storeToRefs(store);
const customer = computed(() => customers.value.find((item) => item.id === selectedCustomerId.value) ?? customers.value[0] ?? null);
const customerLeads = computed(() => leads.value.filter((lead) => lead.customer_id === customer.value?.id));
const customerConversations = computed(() => conversations.value.filter((conversation) => conversation.customer?.id === customer.value?.id));

type TimelineItem = { key: string; kind: 'lead' | 'conversation'; id: number; title: string; meta: string; date: string | null; icon: Component };

const timeline = computed<TimelineItem[]>(() => {
    const leadItems: TimelineItem[] = customerLeads.value.map((lead) => ({
        key: `lead-${lead.id}`, kind: 'lead', id: lead.id, title: lead.title, meta: titleCase(lead.status), date: lead.created_at ?? null, icon: Target,
    }));
    const conversationItems: TimelineItem[] = customerConversations.value.map((conversation) => ({
        key: `conversation-${conversation.id}`, kind: 'conversation', id: conversation.id, title: conversation.subject, meta: titleCase(conversation.status), date: conversation.last_message_at, icon: MessageSquare,
    }));

    return [...leadItems, ...conversationItems].sort((a, b) => new Date(b.date ?? 0).getTime() - new Date(a.date ?? 0).getTime());
});

function openItem(item: TimelineItem): void {
    if (item.kind === 'lead') store.openLead(item.id);
    else store.openConversation(item.id);
}

defineOptions({ layout: AppLayout });
</script>

<template>
    <div v-if="customer" class="space-y-6">
        <Card>
            <div class="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-center gap-4">
                    <Avatar class="size-20 ring-4" style="--tw-ring-color: var(--accent)">
                        <AvatarFallback class="text-2xl font-semibold" style="background: var(--primary); color: var(--primary-foreground)">{{ customer.name[0] }}</AvatarFallback>
                    </Avatar>
                    <div>
                        <h2 class="font-display text-xl font-semibold ui-text">{{ customer.name }}</h2>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                            <Badge tone="green">Активный клиент</Badge>
                            <Badge v-if="customer.source" :tone="channelTone(customer.source)">{{ titleCase(customer.source) }}</Badge>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-4 sm:gap-8">
                    <div class="text-center">
                        <p class="font-display text-2xl font-bold ui-text">{{ customerLeads.length }}</p>
                        <p class="text-[10px] font-semibold uppercase tracking-wide ui-subtle">Лидов</p>
                    </div>
                    <div class="text-center">
                        <p class="font-display text-2xl font-bold ui-text">{{ customerConversations.length }}</p>
                        <p class="text-[10px] font-semibold uppercase tracking-wide ui-subtle">Диалогов</p>
                    </div>
                    <div class="text-center">
                        <p class="font-display text-2xl font-bold ui-text">{{ customer.created_at ? timeAgo(customer.created_at, locale.locale) : '—' }}</p>
                        <p class="text-[10px] font-semibold uppercase tracking-wide ui-subtle">В базе с</p>
                    </div>
                </div>
            </div>

            <Separator class="my-5" />

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="flex items-center gap-3 rounded-lg border p-3" style="border-color: var(--border)">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg" style="background: var(--muted)"><Phone class="h-4 w-4 text-primary" /></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase ui-subtle">Телефон</p>
                        <p class="truncate text-sm font-medium ui-text">{{ customer.phone ?? 'Не указан' }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-lg border p-3" style="border-color: var(--border)">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg" style="background: var(--muted)"><Mail class="h-4 w-4 text-primary" /></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase ui-subtle">Email</p>
                        <p class="truncate text-sm font-medium ui-text">{{ customer.email ?? 'Не указан' }}</p>
                    </div>
                </div>
            </div>
        </Card>

        <Card title="Активность" subtitle="Лиды и диалоги, связанные с клиентом.">
            <div v-if="timeline.length" class="divide-y" style="border-color: var(--border)">
                <button
                    v-for="item in timeline"
                    :key="item.key"
                    type="button"
                    class="flex w-full items-center gap-3 py-3 text-left transition hover:bg-muted"
                    @click="openItem(item)"
                >
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg" style="background: var(--muted)">
                        <component :is="item.icon" class="h-4 w-4 text-primary" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium ui-text">{{ item.title }}</p>
                        <p class="text-xs ui-subtle">{{ item.date ? timeAgo(item.date, locale.locale) : '' }}</p>
                    </div>
                    <Badge tone="neutral">{{ item.meta }}</Badge>
                </button>
            </div>
            <p v-else class="py-6 text-center text-sm ui-subtle">Активности пока нет</p>
        </Card>
    </div>
    <Card v-else title="Профиль клиента">
        <p class="text-sm ui-subtle">Клиент не найден</p>
    </Card>
</template>
