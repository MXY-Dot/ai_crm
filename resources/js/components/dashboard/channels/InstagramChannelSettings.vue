<script setup lang="ts">
import { computed, reactive } from 'vue';
import { PlugZap, Save } from '@lucide/vue';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Input } from '../../ui/input';
import InstagramIcon from '../../icons/InstagramIcon.vue';
import type { IntegrationSettings } from '../../../stores/crmDashboard';

const props = defineProps<{ settings: IntegrationSettings['instagram'] | null; busy: boolean }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();

/**
 * Full browser navigation (not fetch) -- Instagram's own consent dialog and
 * the redirect chain back have to happen in the actual address bar. See
 * MetaOAuthController::instagramStart().
 */
const connectUrl = computed(() => `/api/oauth/instagram/start?tenant_id=${encodeURIComponent(store.tenant?.slug ?? '')}`);

const form = reactive({ pageAccessToken: '', businessAccountId: props.settings?.business_account_id ?? '' });
const testing = computed(() => store.busy);

async function save(): Promise<void> {
    await store.updateIntegrationSettings({
        instagram: {
            page_access_token: form.pageAccessToken || undefined,
            business_account_id: form.businessAccountId || undefined,
        },
    });
    form.pageAccessToken = '';
}

async function testConnection(): Promise<void> {
    await store.testIntegrationConnection({ provider: 'instagram' });
}
</script>

<template>
    <form class="space-y-3" @submit.prevent="save">
        <Button as="a" :href="connectUrl" size="sm" variant="primary" class="w-full">
            <InstagramIcon class="h-4 w-4" /> Подключить через Instagram
        </Button>
        <p class="text-center text-xs ui-subtle">или вставьте токен вручную —</p>

        <label class="block text-sm">
            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Page access token</span>
            <Input v-model="form.pageAccessToken" type="password" placeholder="Токен связанной Facebook-страницы" autocomplete="new-password" />
            <span v-if="settings?.page_access_token_mask" class="mt-1 block break-all text-xs ui-subtle">Текущий: {{ settings.page_access_token_mask }}</span>
        </label>
        <label class="block text-sm">
            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Instagram Business Account ID</span>
            <Input v-model="form.businessAccountId" placeholder="ID бизнес-аккаунта Instagram" />
        </label>
        <p class="text-xs ui-subtle">Данные из Meta for Developers → Instagram Graph API. Прямое подключение, без единого инбокса.</p>
        <div v-if="settings?.webhook_url" class="rounded-md border border-dashed p-2">
            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Webhook URL для Meta App → Webhooks</span>
            <p class="break-all text-xs ui-subtle">{{ settings.webhook_url }}</p>
            <p class="mt-1 text-xs ui-subtle">Один URL для всех клиентов — регистрируется один раз в настройках приложения Meta, не здесь.</p>
        </div>
        <div class="flex gap-2">
            <Button size="sm" variant="primary" type="submit" class="flex-1" :disabled="busy">
                <Save class="h-4 w-4" /> {{ busy ? locale.t('common.waiting') : locale.t('settings.save') }}
            </Button>
            <Button v-if="settings?.page_access_token_configured" size="sm" variant="outline" type="button" :disabled="testing" @click="testConnection">
                <PlugZap class="h-4 w-4" /> Проверить
            </Button>
        </div>
    </form>
</template>
