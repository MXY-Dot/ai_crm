<script setup lang="ts">
import { onMounted, reactive } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { Save } from '@lucide/vue';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Input } from '../../ui/input';

const store = useCrmDashboardStore();
const { integrationSettings, busy } = storeToRefs(store);

const form = reactive({ token: '', webhookSecret: '', baseUrl: '' });

onMounted(async () => {
    if (! integrationSettings.value) await store.loadIntegrationSettings();
});

async function save(): Promise<void> {
    await store.updateIntegrationSettings({
        alif: {
            token: form.token || undefined,
            webhook_secret: form.webhookSecret || undefined,
            base_url: form.baseUrl || undefined,
        },
    });
    form.token = '';
    form.webhookSecret = '';
}
</script>

<template>
    <Card title="Оплата онлайн (Alif Pay)">
        <p class="mb-4 text-sm ui-subtle">
            Приём предоплаты картой через шлюз вместо ручной проверки скриншота. <b>Черновая интеграция</b> — построена
            по публичной документации Alif Pay для Узбекистана (docs.alifpay.uz), поскольку Alif Bank Таджикистан
            выдаёт доступ к своему API только после заключения договора с их бизнес-отделом. Прежде чем это заработает
            по-настоящему, нужно получить у Alif Bank Таджикистан реальный адрес API, токен и секрет вебхука — и,
            возможно, поправить <code>base_url</code> ниже.
        </p>
        <form class="grid max-w-md gap-3" @submit.prevent="save">
            <label class="block text-sm">
                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Token</span>
                <Input v-model="form.token" type="password" placeholder="Токен из личного кабинета Alif" autocomplete="new-password" />
                <span v-if="integrationSettings?.alif?.token_mask" class="mt-1 block break-all text-xs ui-subtle">Текущий: {{ integrationSettings.alif.token_mask }}</span>
            </label>
            <label class="block text-sm">
                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Секрет для проверки вебхука</span>
                <Input v-model="form.webhookSecret" type="password" placeholder="Секретный ключ подписи" autocomplete="new-password" />
                <span v-if="integrationSettings?.alif?.webhook_secret_configured" class="mt-1 block text-xs ui-subtle">{{ integrationSettings.alif.webhook_secret_mask }}</span>
            </label>
            <label class="block text-sm">
                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Base URL (необязательно)</span>
                <Input v-model="form.baseUrl" placeholder="Оставьте пустым, пока не получите реальный от Alif" />
                <span v-if="integrationSettings?.alif?.base_url" class="mt-1 block break-all text-xs ui-subtle">Сейчас: {{ integrationSettings.alif.base_url }}</span>
            </label>
            <p v-if="integrationSettings?.alif?.webhook_url_example" class="text-xs ui-subtle">
                Пример адреса вебхука (создаётся индивидуально на каждый счёт, это лишь для справки):
                <span class="block break-all font-mono">{{ integrationSettings.alif.webhook_url_example }}</span>
            </p>
            <Button size="sm" variant="primary" type="submit" class="w-fit" :disabled="busy">
                <Save class="h-4 w-4" />{{ busy ? 'Сохранение…' : 'Сохранить' }}
            </Button>
        </form>
    </Card>
</template>
