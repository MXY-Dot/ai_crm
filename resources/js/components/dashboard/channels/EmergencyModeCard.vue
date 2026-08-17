<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Save, ShieldAlert } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useEmergencyStore } from '../../../stores/emergency';
import { Button } from '../../ui/button';
import { Input } from '../../ui/input';
import { Switch } from '../../ui/switch';
import { Textarea } from '../../ui/textarea';

/**
 * Sits inside a ChannelCard on IntegrationsPage.vue (ЭТАП 16.8/16.20) — not a
 * communication channel itself, but reuses the same card+dialog shell for visual
 * consistency. Talks directly to the emergency endpoints rather than going
 * through the big crmDashboard store's integration-settings flow, since this is
 * a separate settings surface (tenants.settings.emergency.*, not .integrations.*).
 */
const dashboard = useCrmDashboardStore();
const { tenant } = storeToRefs(dashboard);
const emergency = useEmergencyStore();

const form = reactive({ ru: '', tj: '', en: '', telegramChatId: '' });
const loading = ref(false);
const saving = ref(false);

async function load(): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    loading.value = true;
    try {
        const settings = await apiRequest<{ fallback_message: { ru: string; tj: string; en: string }; telegram_chat_id: string }>(
            '/api/emergency-settings',
            { tenant: slug },
        );
        form.ru = settings.fallback_message.ru;
        form.tj = settings.fallback_message.tj;
        form.en = settings.fallback_message.en;
        form.telegramChatId = settings.telegram_chat_id;
    } finally {
        loading.value = false;
    }
}

async function save(): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    saving.value = true;
    try {
        await apiRequest('/api/emergency-settings', {
            method: 'PATCH',
            tenant: slug,
            body: {
                fallback_message: { ru: form.ru, tj: form.tj, en: form.en },
                telegram_chat_id: form.telegramChatId,
            },
        });
    } finally {
        saving.value = false;
    }
}

async function toggleOverride(enabled: boolean): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    await apiRequest('/api/emergency/override', { method: 'PATCH', tenant: slug, body: { enabled } });
    await emergency.refresh();
}

const statusLabel = computed(() => (emergency.mode === 'emergency' ? 'AI недоступен' : 'Работает нормально'));

onMounted(load);
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs" :class="emergency.mode === 'emergency' ? 'border-destructive/30 bg-destructive/10 text-destructive' : 'border-primary/20 bg-primary/5 text-primary'">
            <ShieldAlert class="size-4 shrink-0" />
            <span class="font-medium">{{ statusLabel }}</span>
        </div>

        <label class="flex items-center justify-between gap-3 text-sm">
            <span>
                <span class="block font-medium ui-text">Ручной режим</span>
                <span class="block text-xs ui-subtle">Принудительно перевести всех клиентов на операторов</span>
            </span>
            <Switch :model-value="emergency.manualOverride" @update:model-value="toggleOverride" />
        </label>

        <form class="space-y-3" @submit.prevent="save">
            <label class="block text-sm">
                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Сообщение клиенту — русский</span>
                <Textarea v-model="form.ru" rows="2" placeholder="Ваше сообщение получили. Оператор скоро вам ответит." />
            </label>
            <label class="block text-sm">
                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Сообщение клиенту — тоҷикӣ</span>
                <Textarea v-model="form.tj" rows="2" placeholder="Паёми шуморо гирифтем. Оператор ба зудӣ ба шумо ҷавоб медиҳад." />
            </label>
            <label class="block text-sm">
                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Сообщение клиенту — English</span>
                <Textarea v-model="form.en" rows="2" placeholder="Thanks for your message. An operator will reply shortly." />
            </label>
            <label class="block text-sm">
                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Telegram-чат для алертов</span>
                <Input v-model="form.telegramChatId" placeholder="ID чата или группы" />
                <span class="mt-1 block text-xs ui-subtle">Куда придёт уведомление, если AI перестанет отвечать</span>
            </label>
            <Button size="sm" variant="primary" type="submit" class="w-full" :disabled="saving || loading">
                <Save class="h-4 w-4" /> {{ saving ? 'Сохраняем…' : 'Сохранить' }}
            </Button>
        </form>
    </div>
</template>
