<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { Mail, Send } from '@lucide/vue';
import { apiRequest } from '../../lib/apiClient';
import { Button } from '../ui/button';
import { Card } from '../ui/card';
import { Input } from '../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select';
import { Switch } from '../ui/switch';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '../ui/tooltip';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';

const FREQUENCIES = ['instant', 'hourly', 'daily', 'weekly', 'critical_only'] as const;
type Frequency = (typeof FREQUENCIES)[number];

const BUCKETS = ['sales', 'complaints', 'ai_errors', 'operators'] as const;
type Bucket = (typeof BUCKETS)[number];
type BucketChannels = { email: boolean | null; telegram_bot: boolean | null };

// Same labels as NotificationCenterPage.vue's own BUCKET_TABS (that page hardcodes
// Russian rather than going through the locale store for these) -- kept identical
// so a bucket reads the same name in both places.
const BUCKET_LABELS: Record<Bucket, string> = {
    sales: 'Продажи',
    complaints: 'Жалобы',
    ai_errors: 'Ошибки AI',
    operators: 'Работа операторов',
};

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { company, channels, busy } = storeToRefs(store);

const notifications = computed(() => {
    const value = (company.value?.brand_settings?.notifications ?? {}) as Record<string, unknown>;
    const types = (value.types ?? {}) as Partial<Record<Bucket, Partial<BucketChannels>>>;
    return {
        email: (value.email as boolean) ?? true,
        push: (value.push as boolean) ?? false,
        telegram_bot: (value.telegram_bot as boolean) ?? false,
        frequency: (value.frequency as Frequency) ?? 'instant',
        large_order_threshold: (value.large_order_threshold as number | null) ?? null,
        types,
    };
});

// Local editable copy so typing a threshold doesn't PATCH on every keystroke —
// only when the field loses focus, same UX as any other blur-to-save number input.
const largeOrderThresholdInput = ref('');
watch(() => notifications.value.large_order_threshold, (value) => {
    largeOrderThresholdInput.value = value ? String(value) : '';
}, { immediate: true });

/** ТЗ раздел 15 — "появился крупный заказ" threshold, in the tenant's own currency. Empty/0 turns the trigger off entirely — no invented default. */
async function saveLargeOrderThreshold(): Promise<void> {
    if (! company.value) return;

    const parsed = parseFloat(largeOrderThresholdInput.value);
    const threshold = Number.isFinite(parsed) && parsed > 0 ? parsed : null;

    await store.updateCompany(company.value.id, {
        brand_settings: {
            ...(company.value.brand_settings ?? {}),
            notifications: { ...notifications.value, large_order_threshold: threshold },
        },
    });
}

/** null = inherits the global email/telegram toggle above; true/false = explicit per-type override. */
function typeChannelValue(bucket: Bucket, channel: 'email' | 'telegram_bot'): boolean | null {
    return notifications.value.types[bucket]?.[channel] ?? null;
}

const telegramConnected = computed(() => channels.value.some((channel) => channel.provider.toLowerCase().includes('telegram') && channel.status === 'connected'));

/** What "По умолчанию" actually resolves to for each channel right now — surfaced via tooltip on that button, see setTypeChannel/typeChannelValue above for how null falls back to these. */
const effectiveDefault = computed(() => ({
    email: notifications.value.email,
    telegram_bot: telegramConnected.value && notifications.value.telegram_bot,
}));

const CHANNEL_LABEL: Record<'email' | 'telegram_bot', string> = { email: 'Email-уведомления', telegram_bot: 'Telegram-бот' };

function optionTooltip(channel: 'email' | 'telegram_bot', opt: boolean | null): string {
    if (opt === null) {
        const on = effectiveDefault.value[channel] ? 'Вкл' : 'Выкл';
        return `Следует общему тумблеру «${CHANNEL_LABEL[channel]}» выше (сейчас: ${on})`;
    }
    const action = channel === 'email' ? 'присылать email' : 'присылать в Telegram';
    return opt
        ? `Всегда ${action} для этой категории, игнорируя общий тумблер`
        : `Никогда не ${action} для этой категории, игнорируя общий тумблер`;
}

const testEmailBusy = ref(false);
const testTelegramBusy = ref(false);

/** Owner clicks "Проверить" and gets a real send to their own account -- bypasses every preference/frequency gate in AppNotification, since the whole point is testing the channel itself, not the routing rules above it. */
async function testEmail(): Promise<void> {
    testEmailBusy.value = true;
    try {
        await apiRequest('/api/notification-settings/test-email', { method: 'POST' });
        toast.success('Тестовое письмо отправлено на вашу почту');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось отправить письмо');
    } finally {
        testEmailBusy.value = false;
    }
}

async function testTelegram(): Promise<void> {
    testTelegramBusy.value = true;
    try {
        await apiRequest('/api/notification-settings/test-telegram', { method: 'POST' });
        toast.success('Тестовое сообщение отправлено в Telegram');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось отправить сообщение');
    } finally {
        testTelegramBusy.value = false;
    }
}

async function toggle(key: 'email' | 'push' | 'telegram_bot', value: boolean): Promise<void> {
    if (! company.value) return;

    await store.updateCompany(company.value.id, {
        brand_settings: {
            ...(company.value.brand_settings ?? {}),
            notifications: { ...notifications.value, [key]: value },
        },
    });
}

/** ТЗ раздел 18 — "сразу / раз в час / ежедневно / еженедельно / только критические". */
async function setFrequency(frequency: string): Promise<void> {
    if (! company.value) return;

    await store.updateCompany(company.value.id, {
        brand_settings: {
            ...(company.value.brand_settings ?? {}),
            notifications: { ...notifications.value, frequency },
        },
    });
}

/** ТЗ раздел 18 — per-type channel override. `value` is null to inherit the global toggle, or an explicit true/false. */
async function setTypeChannel(bucket: Bucket, channel: 'email' | 'telegram_bot', value: boolean | null): Promise<void> {
    if (! company.value) return;

    const types = { ...notifications.value.types, [bucket]: { ...notifications.value.types[bucket], [channel]: value } };

    await store.updateCompany(company.value.id, {
        brand_settings: {
            ...(company.value.brand_settings ?? {}),
            notifications: { ...notifications.value, types },
        },
    });
}
</script>

<template>
    <Card :title="locale.t('company.notificationsTitle')" :subtitle="locale.t('company.notificationsSubtitle')">
        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-dashed p-3 border-border">
                <p class="mr-auto text-xs ui-subtle">Проверить, что уведомления реально доходят до вас лично:</p>
                <Button variant="outline" size="sm" :disabled="testEmailBusy" @click="testEmail"><Mail class="h-4 w-4" />Проверить Email</Button>
                <Button variant="outline" size="sm" :disabled="testTelegramBusy" @click="testTelegram"><Send class="h-4 w-4" />Проверить Telegram</Button>
            </div>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium ui-text">{{ locale.t('company.notifyEmail') }}</p>
                    <p class="text-xs ui-subtle">{{ locale.t('company.notifyEmailHelp') }}</p>
                </div>
                <Switch :model-value="notifications.email" :disabled="busy" @update:model-value="toggle('email', $event)" />
            </div>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium ui-text">{{ locale.t('company.notifyPush') }}</p>
                    <p class="text-xs ui-subtle">{{ locale.t('company.notifyPushHelp') }}</p>
                </div>
                <Switch :model-value="notifications.push" :disabled="busy" @update:model-value="toggle('push', $event)" />
            </div>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium ui-text">{{ locale.t('company.notifyTelegram') }}</p>
                    <p class="text-xs ui-subtle">{{ telegramConnected ? locale.t('company.notifyTelegramHelp') : locale.t('company.notifyTelegramLocked') }}</p>
                </div>
                <Switch :model-value="telegramConnected && notifications.telegram_bot" :disabled="busy || !telegramConnected" @update:model-value="toggle('telegram_bot', $event)" />
            </div>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium ui-text">{{ locale.t('company.notifyFrequency') }}</p>
                    <p class="text-xs ui-subtle">{{ locale.t('company.notifyFrequencyHelp') }}</p>
                </div>
                <Select :model-value="notifications.frequency" :disabled="busy" @update:model-value="(v) => setFrequency(String(v))">
                    <SelectTrigger class="h-9 w-48"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="f in FREQUENCIES" :key="f" :value="f">{{ locale.t('company.notifyFrequencyOptions.' + f) }}</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium ui-text">{{ locale.t('company.notifyLargeOrder') }}</p>
                    <p class="text-xs ui-subtle">{{ locale.t('company.notifyLargeOrderHelp') }}</p>
                </div>
                <Input
                    v-model="largeOrderThresholdInput"
                    type="number"
                    min="0"
                    step="1"
                    class="h-9 w-32"
                    :placeholder="locale.t('company.notifyLargeOrderOff')"
                    :disabled="busy"
                    @blur="saveLargeOrderThreshold"
                    @keyup.enter="saveLargeOrderThreshold"
                />
            </div>

            <div>
                <p class="mb-2 text-sm font-medium ui-text">{{ locale.t('company.notifyByType') }}</p>
                <p class="mb-3 text-xs ui-subtle">{{ locale.t('company.notifyByTypeHelp') }}</p>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border bg-muted/30">
                                <th class="px-3 py-2 text-left font-medium ui-text">{{ locale.t('company.notifyByTypeCategory') }}</th>
                                <th class="px-3 py-2 text-center font-medium ui-text">{{ locale.t('company.notifyEmail') }}</th>
                                <th class="px-3 py-2 text-center font-medium ui-text">{{ locale.t('company.notifyTelegram') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="bucket in BUCKETS" :key="bucket" class="border-b border-border last:border-0">
                                <td class="px-3 py-2 ui-text">{{ BUCKET_LABELS[bucket] }}</td>
                                <td class="px-3 py-2">
                                    <TooltipProvider :delay-duration="200">
                                        <div class="flex justify-center gap-1">
                                            <Tooltip v-for="opt in [null, true, false]" :key="String(opt)">
                                                <TooltipTrigger as-child>
                                                    <button
                                                        type="button"
                                                        class="rounded px-2 py-0.5 text-xs"
                                                        :class="typeChannelValue(bucket, 'email') === opt ? 'bg-primary text-primary-foreground' : 'ui-subtle hover:bg-muted'"
                                                        :disabled="busy"
                                                        @click="setTypeChannel(bucket, 'email', opt)"
                                                    >{{ opt === null ? locale.t('company.notifyTypeInherit') : opt ? locale.t('company.notifyTypeOn') : locale.t('company.notifyTypeOff') }}</button>
                                                </TooltipTrigger>
                                                <TooltipContent>{{ optionTooltip('email', opt) }}</TooltipContent>
                                            </Tooltip>
                                        </div>
                                    </TooltipProvider>
                                </td>
                                <td class="px-3 py-2">
                                    <TooltipProvider :delay-duration="200">
                                        <div class="flex justify-center gap-1">
                                            <Tooltip v-for="opt in [null, true, false]" :key="String(opt)">
                                                <TooltipTrigger as-child>
                                                    <button
                                                        type="button"
                                                        class="rounded px-2 py-0.5 text-xs"
                                                        :class="typeChannelValue(bucket, 'telegram_bot') === opt ? 'bg-primary text-primary-foreground' : 'ui-subtle hover:bg-muted'"
                                                        :disabled="busy"
                                                        @click="setTypeChannel(bucket, 'telegram_bot', opt)"
                                                    >{{ opt === null ? locale.t('company.notifyTypeInherit') : opt ? locale.t('company.notifyTypeOn') : locale.t('company.notifyTypeOff') }}</button>
                                                </TooltipTrigger>
                                                <TooltipContent>{{ optionTooltip('telegram_bot', opt) }}</TooltipContent>
                                            </Tooltip>
                                        </div>
                                    </TooltipProvider>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </Card>
</template>
