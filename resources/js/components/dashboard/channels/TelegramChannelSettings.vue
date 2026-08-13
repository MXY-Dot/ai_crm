<script setup lang="ts">
import { computed, reactive } from 'vue';
import { PlugZap, Save } from '@lucide/vue';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Input } from '../../ui/input';
import type { IntegrationSettings } from '../../../stores/crmDashboard';

const props = defineProps<{ settings: IntegrationSettings['telegram'] | null; busy: boolean }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();

const form = reactive({ botToken: '', webhookSecret: '' });
const testing = computed(() => store.busy);

async function save(): Promise<void> {
    await store.updateIntegrationSettings({
        telegram: {
            bot_token: form.botToken || undefined,
            webhook_secret: form.webhookSecret || undefined,
        },
    });
    form.botToken = '';
    form.webhookSecret = '';
}

async function testConnection(): Promise<void> {
    await store.testIntegrationConnection({ provider: 'telegram' });
}
</script>

<template>
    <form class="space-y-3" @submit.prevent="save">
        <label class="block text-sm">
            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Bot token</span>
            <Input v-model="form.botToken" type="password" placeholder="Токен от @BotFather" autocomplete="new-password" />
            <span v-if="settings?.bot_token_mask" class="mt-1 block text-xs ui-subtle">Текущий: {{ settings.bot_token_mask }}</span>
            <span v-else class="mt-1 block text-xs ui-subtle">Получите токен у @BotFather в Telegram (команда /newbot)</span>
        </label>
        <label class="block text-sm">
            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('settings.webhookSecret') }}</span>
            <Input v-model="form.webhookSecret" type="password" placeholder="Необязательно — сгенерируется автоматически" autocomplete="new-password" />
        </label>
        <p v-if="settings?.webhook_url" class="break-all text-xs ui-subtle">{{ settings.webhook_url }}</p>
        <div class="flex gap-2">
            <Button size="sm" variant="primary" type="submit" class="flex-1" :disabled="busy">
                <Save class="h-4 w-4" /> {{ busy ? locale.t('common.waiting') : locale.t('settings.save') }}
            </Button>
            <Button v-if="settings?.bot_token_configured" size="sm" variant="outline" type="button" :disabled="testing" @click="testConnection">
                <PlugZap class="h-4 w-4" /> Проверить
            </Button>
        </div>
    </form>
</template>
