<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { Megaphone, Sparkles, Users } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { useCrmDashboardStore } from '@/stores/crmDashboard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';

defineOptions({ layout: AppLayout });

type Campaign = {
    id: number;
    name: string;
    offer_text: string;
    segment: string | null;
    min_purchases: number | null;
    inactive_days: number | null;
    status: 'draft' | 'pending_approval' | 'approved' | 'sent' | 'cancelled';
    audience_count: number;
    approved_at: string | null;
    sent_at: string | null;
};

type AudienceContact = { id: number; name: string; phone: string | null; segment: string | null };

const dashboard = useCrmDashboardStore();
const { tenant } = storeToRefs(dashboard);

const campaigns = ref<Campaign[]>([]);
const loading = ref(true);
const saving = ref(false);
const draftingCopy = ref(false);
const audienceOpenFor = ref<number | null>(null);
const audienceContacts = ref<AudienceContact[]>([]);
const audienceLoading = ref(false);

const SEGMENT_OPTIONS = [
    { value: 'new', label: 'Новые' },
    { value: 'returning', label: 'Возвращающиеся' },
    { value: 'vip', label: 'VIP' },
    { value: 'top_vip', label: 'TOP VIP' },
    { value: 'lost', label: 'Потерянные' },
    { value: 'b2b', label: 'B2B' },
];

const STATUS_LABELS: Record<Campaign['status'], string> = {
    draft: 'Черновик',
    pending_approval: 'На согласовании',
    approved: 'Согласована',
    sent: 'Отправлена',
    cancelled: 'Отменена',
};

const STATUS_TONE: Record<Campaign['status'], 'default' | 'secondary' | 'outline'> = {
    draft: 'outline',
    pending_approval: 'secondary',
    approved: 'default',
    sent: 'default',
    cancelled: 'outline',
};

const form = reactive({ name: '', offer_text: '', segment: '', min_purchases: '', inactive_days: '' });

async function load(): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    loading.value = true;
    try {
        campaigns.value = await apiRequest<Campaign[]>('/api/campaigns', { tenant: slug });
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить кампании');
    } finally {
        loading.value = false;
    }
}

async function draftCopy(): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug || ! form.name.trim()) {
        toast.error('Сначала укажите название кампании');
        return;
    }

    draftingCopy.value = true;
    try {
        const result = await apiRequest<{ text: string }>('/api/campaigns/draft-copy', {
            method: 'POST',
            tenant: slug,
            body: { name: form.name.trim(), segment: form.segment || undefined },
        });
        if (result.text) {
            form.offer_text = result.text;
        } else {
            toast.error('AI недоступен — настройте модель ассистента в разделе AI, либо напишите текст сами');
        }
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось сгенерировать текст');
    } finally {
        draftingCopy.value = false;
    }
}

async function createCampaign(): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    saving.value = true;
    try {
        await apiRequest('/api/campaigns', {
            method: 'POST',
            tenant: slug,
            body: {
                name: form.name.trim(),
                offer_text: form.offer_text.trim(),
                segment: form.segment || undefined,
                min_purchases: form.min_purchases ? Number(form.min_purchases) : undefined,
                inactive_days: form.inactive_days ? Number(form.inactive_days) : undefined,
            },
        });
        toast.success('Кампания создана');
        Object.assign(form, { name: '', offer_text: '', segment: '', min_purchases: '', inactive_days: '' });
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось создать кампанию');
    } finally {
        saving.value = false;
    }
}

async function transition(campaign: Campaign, action: 'submit-for-approval' | 'approve' | 'mark-sent'): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    try {
        await apiRequest(`/api/campaigns/${campaign.id}/${action}`, { method: 'POST', tenant: slug });
        toast.success('Готово');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось выполнить действие');
    }
}

async function showAudience(campaign: Campaign): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    if (audienceOpenFor.value === campaign.id) {
        audienceOpenFor.value = null;
        return;
    }

    audienceOpenFor.value = campaign.id;
    audienceLoading.value = true;
    try {
        const result = await apiRequest<{ data: AudienceContact[] }>(`/api/campaigns/${campaign.id}/audience`, { tenant: slug });
        audienceContacts.value = result.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить список клиентов');
    } finally {
        audienceLoading.value = false;
    }
}

const draftCount = computed(() => campaigns.value.filter((c) => c.status === 'draft').length);
const pendingCount = computed(() => campaigns.value.filter((c) => c.status === 'pending_approval').length);

onMounted(load);
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="flex items-center gap-2 font-display text-2xl font-bold ui-text"><Megaphone class="h-5 w-5 text-primary" />Маркетинговые кампании</h2>
            <p class="mt-2 text-sm ui-subtle">WERO готовит аудиторию и текст — отправляете вы сами со своего WhatsApp/Telegram, ни одно сообщение не уходит клиенту автоматически.</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-5">
            <h3 class="mb-4 font-display text-base font-semibold ui-text">Новая кампания</h3>
            <form class="grid gap-4" @submit.prevent="createCampaign">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Название</span>
                        <Input v-model="form.name" maxlength="160" required />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Сегмент аудитории</span>
                        <Select v-model="form.segment">
                            <SelectTrigger class="w-full"><SelectValue placeholder="Любой сегмент" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="option in SEGMENT_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </label>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Мин. покупок</span>
                        <Input v-model="form.min_purchases" type="number" min="0" placeholder="Не важно" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Без покупок дольше (дней)</span>
                        <Input v-model="form.inactive_days" type="number" min="1" placeholder="Не важно" />
                    </label>
                </div>
                <label class="block text-sm">
                    <div class="mb-1 flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase ui-subtle">Текст предложения</span>
                        <Button type="button" variant="outline" size="sm" :disabled="draftingCopy" @click="draftCopy"><Sparkles class="h-3.5 w-3.5" />{{ draftingCopy ? '…' : 'AI-черновик' }}</Button>
                    </div>
                    <Textarea v-model="form.offer_text" class="min-h-24" maxlength="2000" required />
                </label>
                <Button variant="primary" type="submit" class="w-fit" :disabled="saving"><Megaphone class="h-4 w-4" />{{ saving ? '…' : 'Создать кампанию' }}</Button>
            </form>
        </div>

        <div v-if="loading" class="space-y-3">
            <Skeleton v-for="i in 3" :key="i" class="h-20 rounded-xl" />
        </div>
        <div v-else class="space-y-3">
            <p v-if="draftCount || pendingCount" class="text-sm ui-subtle">Черновиков: {{ draftCount }}, на согласовании: {{ pendingCount }}</p>
            <div v-for="campaign in campaigns" :key="campaign.id" class="rounded-xl border border-border bg-card p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h4 class="font-display text-sm font-semibold ui-text">{{ campaign.name }}</h4>
                            <Badge :variant="STATUS_TONE[campaign.status]">{{ STATUS_LABELS[campaign.status] }}</Badge>
                        </div>
                        <p class="mt-1 text-sm ui-subtle">{{ campaign.offer_text }}</p>
                        <button type="button" class="mt-2 flex items-center gap-1.5 text-xs font-medium text-primary hover:underline" @click="showAudience(campaign)">
                            <Users class="h-3.5 w-3.5" />{{ campaign.audience_count }} клиент(ов) — {{ audienceOpenFor === campaign.id ? 'скрыть список' : 'показать список' }}
                        </button>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="campaign.status === 'draft'" size="sm" variant="outline" @click="transition(campaign, 'submit-for-approval')">На согласование</Button>
                        <Button v-if="campaign.status === 'pending_approval'" size="sm" variant="primary" @click="transition(campaign, 'approve')">Согласовать</Button>
                        <Button v-if="campaign.status === 'approved'" size="sm" variant="outline" @click="transition(campaign, 'mark-sent')">Отметить отправленной</Button>
                    </div>
                </div>
                <div v-if="audienceOpenFor === campaign.id" class="mt-3 rounded-lg border border-border bg-background/40 p-3">
                    <p v-if="audienceLoading" class="text-xs ui-subtle">Загрузка…</p>
                    <ul v-else-if="audienceContacts.length" class="grid gap-1 text-xs ui-text sm:grid-cols-2">
                        <li v-for="contact in audienceContacts" :key="contact.id" class="truncate">{{ contact.name }} — {{ contact.phone ?? 'нет телефона' }}</li>
                    </ul>
                    <p v-else class="text-xs ui-subtle">Клиентов под этот фильтр не найдено</p>
                </div>
            </div>
            <p v-if="! campaigns.length" class="py-6 text-center text-sm ui-subtle">Кампаний пока нет</p>
        </div>
    </section>
</template>
