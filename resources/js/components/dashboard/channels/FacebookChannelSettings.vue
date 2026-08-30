<script setup lang="ts">
import { computed, reactive } from 'vue';
import { PlugZap, Save } from '@lucide/vue';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Input } from '../../ui/input';
import FacebookIcon from '../../icons/FacebookIcon.vue';
import type { IntegrationSettings } from '../../../stores/crmDashboard';

const props = defineProps<{ settings: IntegrationSettings['facebook'] | null; busy: boolean }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();

/**
 * Full browser navigation (not fetch) -- Meta's consent dialog and the
 * redirect chain back have to happen in the actual address bar, an XHR
 * response can't drive that. See MetaOAuthController::facebookStart().
 */
const connectUrl = computed(() => `/api/oauth/facebook/start?tenant_id=${encodeURIComponent(store.tenant?.slug ?? '')}`);

const form = reactive({ pageAccessToken: '', pageId: props.settings?.page_id ?? '' });
const testing = computed(() => store.busy);

async function save(): Promise<void> {
    await store.updateIntegrationSettings({
        facebook: {
            page_access_token: form.pageAccessToken || undefined,
            page_id: form.pageId || undefined,
        },
    });
    form.pageAccessToken = '';
}

async function testConnection(): Promise<void> {
    await store.testIntegrationConnection({ provider: 'facebook' });
}
</script>

<template>
    <form class="space-y-3" @submit.prevent="save">
        <Button as="a" :href="connectUrl" size="sm" variant="primary" class="w-full">
            <FacebookIcon class="h-4 w-4" /> Подключить через Facebook
        </Button>
        <p class="text-center text-xs ui-subtle">или вставьте токен вручную —</p>

        <label class="block text-sm">
            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Page access token</span>
            <Input v-model="form.pageAccessToken" type="password" placeholder="Токен страницы Facebook" autocomplete="new-password" />
            <span v-if="settings?.page_access_token_mask" class="mt-1 block break-all text-xs ui-subtle">Текущий: {{ settings.page_access_token_mask }}</span>
        </label>
        <label class="block text-sm">
            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Page ID</span>
            <Input v-model="form.pageId" placeholder="ID страницы Facebook" />
        </label>
        <p class="text-xs ui-subtle">Данные из Meta for Developers → Messenger → Page. Прямое подключение, без единого инбокса.</p>
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
