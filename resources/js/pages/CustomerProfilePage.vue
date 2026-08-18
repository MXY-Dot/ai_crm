<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, reactive, watch } from 'vue';
import type { Component } from 'vue';
import { storeToRefs } from 'pinia';
import { Briefcase, Cake, Mail, MapPin, MessageSquare, Phone, Sparkles, Target, ThumbsDown, ThumbsUp } from '@lucide/vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';
import { channelTone, timeAgo } from '../lib/format';
import { conversationStatusLabels, sourceLabels } from '../lib/statusLabels';
import { Avatar, AvatarFallback } from '../components/ui/avatar';
import { Badge } from '../components/ui/badge';
import { Button } from '../components/ui/button';
import { Card } from '../components/ui/card';
import { Input } from '../components/ui/input';
import { Separator } from '../components/ui/separator';
import { Switch } from '../components/ui/switch';
import { Textarea } from '../components/ui/textarea';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { customers, conversations, leads, selectedCustomerId, busy } = storeToRefs(store);
const customer = computed(() => customers.value.find((item) => item.id === selectedCustomerId.value) ?? customers.value[0] ?? null);

function toggleBusiness(value: boolean): void {
    if (! customer.value) return;
    store.updateCustomer(customer.value.id, { is_business: value });
}
const customerLeads = computed(() => leads.value.filter((lead) => lead.customer_id === customer.value?.id));
const customerConversations = computed(() => conversations.value.filter((conversation) => conversation.customer?.id === customer.value?.id));

// ЭТАП 17.4 — VIP/purchase fields already existed on Customer but were only
// ever surfaced on the separate VipCustomersPage; average check is simple
// arithmetic over fields already in the payload, no new backend call needed.
const averageCheck = computed(() => {
    const count = customer.value?.purchases_count ?? 0;
    const revenue = customer.value?.total_revenue ?? 0;
    return count > 0 ? Math.round((revenue / count) * 100) / 100 : 0;
});

// ЭТАП 17.7 — most recent non-empty ai_summary across this customer's own
// leads/conversations, already loaded in the store — no extra request.
const aiSummary = computed(() => {
    const items = [...customerLeads.value.map((lead) => ({ text: lead.ai_summary, date: lead.created_at })), ...customerConversations.value.map((c) => ({ text: c.ai_summary, date: c.last_message_at }))]
        .filter((item): item is { text: string; date: string | null } => !! item.text)
        .sort((a, b) => new Date(b.date ?? 0).getTime() - new Date(a.date ?? 0).getTime());
    return items[0]?.text ?? null;
});

const profileForm = reactive({ city: '', birth_year: '' });
watch(customer, (value) => {
    profileForm.city = value?.city ?? '';
    profileForm.birth_year = value?.birth_year ? String(value.birth_year) : '';
}, { immediate: true });

function saveProfileField(field: 'city' | 'birth_year'): void {
    if (! customer.value) return;
    if (field === 'city') {
        store.updateCustomer(customer.value.id, { city: profileForm.city.trim() || null });
    } else {
        const year = profileForm.birth_year.trim() ? Number(profileForm.birth_year) : null;
        store.updateCustomer(customer.value.id, { birth_year: year });
    }
}

// ЭТАП 17.2/17.3 — recorded manually by an operator after a post-service
// follow-up call (see customers:post-service-follow-up); negative feedback
// automatically creates a complaint task server-side.
const feedbackNotes = reactive({ text: '' });
async function submitFeedback(satisfaction: 'positive' | 'neutral' | 'negative'): Promise<void> {
    if (! customer.value) return;
    await store.recordCustomerFeedback({ customer_id: customer.value.id, satisfaction, notes: feedbackNotes.text.trim() || undefined });
    feedbackNotes.text = '';
}

type TimelineItem = { key: string; kind: 'lead' | 'conversation'; id: number; title: string; meta: string; date: string | null; icon: Component };

const timeline = computed<TimelineItem[]>(() => {
    const leadItems: TimelineItem[] = customerLeads.value.map((lead) => ({
        key: `lead-${lead.id}`, kind: 'lead', id: lead.id, title: lead.title, meta: locale.t(`leads.statuses.${lead.status}`), date: lead.created_at ?? null, icon: Target,
    }));
    const conversationItems: TimelineItem[] = customerConversations.value.map((conversation) => ({
        key: `conversation-${conversation.id}`, kind: 'conversation', id: conversation.id, title: conversation.subject, meta: conversationStatusLabels[conversation.status] ?? conversation.status, date: conversation.last_message_at, icon: MessageSquare,
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
        <Card data-tour="customer-summary">
            <div class="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-center gap-4">
                    <Avatar class="size-20 ring-4 ring-accent">
                        <AvatarFallback class="text-2xl font-semibold bg-primary text-primary-foreground">{{ customer.name[0] }}</AvatarFallback>
                    </Avatar>
                    <div>
                        <h2 class="font-display text-xl font-semibold ui-text">{{ customer.name }}</h2>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                            <Badge tone="green">Активный клиент</Badge>
                            <Badge v-if="customer.source" :tone="channelTone(customer.source)">{{ sourceLabels[customer.source] ?? customer.source }}</Badge>
                            <Badge v-if="customer.segment" tone="neutral">{{ locale.t(`vip.segment.${customer.segment}`) }}</Badge>
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
                <div class="flex items-center gap-3 rounded-lg border p-3 border-border">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted"><Phone class="h-4 w-4 text-primary" /></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase ui-subtle">Телефон</p>
                        <p class="truncate text-sm font-medium ui-text">{{ customer.phone ?? 'Не указан' }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-lg border p-3 border-border">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted"><Mail class="h-4 w-4 text-primary" /></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase ui-subtle">Email</p>
                        <p class="truncate text-sm font-medium ui-text">{{ customer.email ?? 'Не указан' }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-lg border p-3 border-border">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted"><MapPin class="h-4 w-4 text-primary" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-semibold uppercase ui-subtle">Город</p>
                        <Input v-model="profileForm.city" class="h-7 border-none px-0 text-sm font-medium shadow-none focus-visible:ring-0" placeholder="Не указан" @blur="saveProfileField('city')" @keyup.enter="($event.target as HTMLInputElement).blur()" />
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-lg border p-3 border-border">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted"><Cake class="h-4 w-4 text-primary" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-semibold uppercase ui-subtle">Год рождения</p>
                        <Input v-model="profileForm.birth_year" type="number" class="h-7 border-none px-0 text-sm font-medium shadow-none focus-visible:ring-0" placeholder="Не указан" @blur="saveProfileField('birth_year')" @keyup.enter="($event.target as HTMLInputElement).blur()" />
                    </div>
                </div>
                <div class="flex items-center justify-between gap-3 rounded-lg border p-3 sm:col-span-2 border-border">
                    <span class="flex items-center gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted"><Briefcase class="h-4 w-4 text-primary" /></span>
                        <span class="text-sm font-medium ui-text">{{ locale.t('contacts.isBusiness') }}</span>
                    </span>
                    <Switch :model-value="!!customer.is_business" :disabled="busy" @update:model-value="toggleBusiness" />
                </div>
            </div>
        </Card>

        <Card v-if="customer.vip_status || customer.purchases_count || aiSummary" data-tour="customer-vip" title="VIP и покупки" subtitle="Скоринг, покупки и то, что AI уже знает об этом клиенте.">
            <div class="grid gap-3 sm:grid-cols-4">
                <div class="rounded-lg border p-3 border-border text-center">
                    <p class="font-display text-xl font-bold ui-text">{{ customer.vip_score ?? '—' }}</p>
                    <p class="text-[10px] font-semibold uppercase tracking-wide ui-subtle">VIP-скор</p>
                </div>
                <div class="rounded-lg border p-3 border-border text-center">
                    <p class="font-display text-xl font-bold ui-text">{{ customer.purchases_count ?? 0 }}</p>
                    <p class="text-[10px] font-semibold uppercase tracking-wide ui-subtle">Покупок</p>
                </div>
                <div class="rounded-lg border p-3 border-border text-center">
                    <p class="font-display text-xl font-bold ui-text">{{ customer.total_revenue ?? 0 }}</p>
                    <p class="text-[10px] font-semibold uppercase tracking-wide ui-subtle">Сумма</p>
                </div>
                <div class="rounded-lg border p-3 border-border text-center">
                    <p class="font-display text-xl font-bold ui-text">{{ averageCheck }}</p>
                    <p class="text-[10px] font-semibold uppercase tracking-wide ui-subtle">Средний чек</p>
                </div>
            </div>
            <p v-if="customer.vip_reason" class="mt-3 text-sm ui-subtle">{{ customer.vip_reason }}</p>
            <Separator v-if="aiSummary" class="my-4" />
            <div v-if="aiSummary" class="flex items-start gap-3 rounded-lg border p-3 border-border">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted"><Sparkles class="h-4 w-4 text-primary" /></span>
                <p class="text-sm ui-text">{{ aiSummary }}</p>
            </div>
        </Card>

        <Card data-tour="customer-feedback" title="Отзыв клиента" subtitle="Записать впечатления после звонка/визита — негативный отзыв автоматически создаст задачу на разбор.">
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" size="sm" :disabled="busy" @click="submitFeedback('positive')"><ThumbsUp class="h-4 w-4 text-emerald-600" />Доволен</Button>
                <Button variant="outline" size="sm" :disabled="busy" @click="submitFeedback('neutral')">😐 Нейтрально</Button>
                <Button variant="outline" size="sm" :disabled="busy" @click="submitFeedback('negative')"><ThumbsDown class="h-4 w-4 text-red-600" />Недоволен</Button>
            </div>
            <Textarea v-model="feedbackNotes.text" class="mt-3 min-h-16" placeholder="Комментарий (необязательно)" />
        </Card>

        <Card data-tour="customer-timeline" title="Активность" subtitle="Лиды и диалоги, связанные с клиентом.">
            <div v-if="timeline.length" class="divide-y border-border">
                <button
                    v-for="item in timeline"
                    :key="item.key"
                    type="button"
                    class="flex w-full items-center gap-3 py-3 text-left transition hover:bg-muted"
                    @click="openItem(item)"
                >
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted">
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
