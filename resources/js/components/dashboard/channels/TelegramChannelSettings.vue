<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Save } from '@lucide/vue';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Input } from '../../ui/input';
import { Switch } from '../../ui/switch';
import type { IntegrationSettings } from '../../../stores/crmDashboard';

const props = defineProps<{ settings: IntegrationSettings['telegram'] | null; busy: boolean }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();

const form = reactive({ botToken: '', webhookSecret: '', autoReply: false });

watch(() => props.settings, (value) => {
    form.autoReply = value?.auto_reply_enabled ?? false;
}, { immediate: true });

async function save(): Promise<void> {
    await store.updateIntegrationSettings({
        telegram: {
            bot_token: form.botToken || undefined,
            webhook_secret: form.webhookSecret || undefined,
            auto_reply_enabled: form.autoReply,
        },
    });
    form.botToken = '';
    form.webhookSecret = '';
}
</script>

<template>
    <form class="space-y-3" @submit.prevent="save">
        <label class="block text-sm">
            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Bot token</span>
            <Input v-model="form.botToken" type="password" placeholder="Оставить пустым, чтобы не менять" autocomplete="new-password" />
            <span v-if="settings?.bot_token_mask" class="mt-1 block text-xs ui-subtle">{{ settings.bot_token_mask }}</span>
        </label>
        <label class="block text-sm">
            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('settings.webhookSecret') }}</span>
            <Input v-model="form.webhookSecret" type="password" placeholder="Оставить пустым, чтобы не менять" autocomplete="new-password" />
        </label>
        <p v-if="settings?.webhook_url" class="break-all text-xs ui-subtle">{{ settings.webhook_url }}</p>
        <label class="flex items-center justify-between rounded-lg border px-3 py-2 text-sm ui-text border-border">
            <span>AI автоответ в Telegram</span>
            <Switch v-model="form.autoReply" />
        </label>
        <Button size="sm" variant="primary" type="submit" class="w-full" :disabled="busy">
            <Save class="h-4 w-4" /> {{ busy ? locale.t('common.waiting') : locale.t('settings.save') }}
        </Button>
    </form>
</template>
