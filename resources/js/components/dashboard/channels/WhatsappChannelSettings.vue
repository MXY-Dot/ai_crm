<script setup lang="ts">
import { computed, reactive } from 'vue';
import { PlugZap, Save } from '@lucide/vue';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Input } from '../../ui/input';
import type { IntegrationSettings } from '../../../stores/crmDashboard';

const props = defineProps<{ settings: IntegrationSettings['whatsapp'] | null; busy: boolean }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();

const form = reactive({ accessToken: '', phoneNumberId: props.settings?.phone_number_id ?? '', businessAccountId: props.settings?.business_account_id ?? '' });
const testing = computed(() => store.busy);

async function save(): Promise<void> {
    await store.updateIntegrationSettings({
        whatsapp: {
            access_token: form.accessToken || undefined,
            phone_number_id: form.phoneNumberId || undefined,
            business_account_id: form.businessAccountId || undefined,
        },
    });
    form.accessToken = '';
}

async function testConnection(): Promise<void> {
    await store.testIntegrationConnection({ provider: 'whatsapp' });
}
</script>

<template>
    <form class="space-y-3" @submit.prevent="save">
        <label class="block text-sm">
            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Access token</span>
            <Input v-model="form.accessToken" type="password" placeholder="Permanent token из Meta for Developers" autocomplete="new-password" />
            <span v-if="settings?.access_token_mask" class="mt-1 block text-xs ui-subtle">Текущий: {{ settings.access_token_mask }}</span>
        </label>
        <label class="block text-sm">
            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Phone number ID</span>
            <Input v-model="form.phoneNumberId" placeholder="ID номера в WhatsApp Cloud API" />
        </label>
        <label class="block text-sm">
            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Business account ID</span>
            <Input v-model="form.businessAccountId" placeholder="Необязательно" />
        </label>
        <p class="text-xs ui-subtle">Данные из Meta for Developers → WhatsApp → API Setup. Прямое подключение через WhatsApp Cloud API, без единого инбокса.</p>
        <div class="flex gap-2">
            <Button size="sm" variant="primary" type="submit" class="flex-1" :disabled="busy">
                <Save class="h-4 w-4" /> {{ busy ? locale.t('common.waiting') : locale.t('settings.save') }}
            </Button>
            <Button v-if="settings?.access_token_configured" size="sm" variant="outline" type="button" :disabled="testing" @click="testConnection">
                <PlugZap class="h-4 w-4" /> Проверить
            </Button>
        </div>
    </form>
</template>
