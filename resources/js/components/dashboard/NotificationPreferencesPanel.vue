<script setup lang="ts">
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Card } from '../ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select';
import { Switch } from '../ui/switch';

const FREQUENCIES = ['instant', 'hourly', 'daily', 'weekly', 'critical_only'] as const;
type Frequency = (typeof FREQUENCIES)[number];

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { company, channels, busy } = storeToRefs(store);

const notifications = computed(() => {
    const value = (company.value?.brand_settings?.notifications ?? {}) as Record<string, boolean | string>;
    return {
        email: (value.email as boolean) ?? true,
        push: (value.push as boolean) ?? false,
        telegram_bot: (value.telegram_bot as boolean) ?? false,
        frequency: (value.frequency as Frequency) ?? 'instant',
    };
});

const telegramConnected = computed(() => channels.value.some((channel) => channel.provider.toLowerCase().includes('telegram') && channel.status === 'connected'));

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
</script>

<template>
    <Card :title="locale.t('company.notificationsTitle')" :subtitle="locale.t('company.notificationsSubtitle')">
        <div class="space-y-4">
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
        </div>
    </Card>
</template>
