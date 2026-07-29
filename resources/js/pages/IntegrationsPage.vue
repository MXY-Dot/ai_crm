<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, onMounted } from 'vue';
import { storeToRefs } from 'pinia';
import { Bot, CheckCircle2, Globe2, MessageCircle, Send } from '@lucide/vue';
import IntegrationSettingsPanel from '../components/dashboard/IntegrationSettingsPanel.vue';
import { Badge } from '../components/ui/badge';
import { Card } from '../components/ui/card';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const store = useCrmDashboardStore();
const { channels, conversations, integrationSettings } = storeToRefs(store);

const specs = [
    { key: 'telegram', name: 'Telegram', icon: Send },
    { key: 'website', name: 'Website widget', icon: Globe2 },
    { key: 'dify', name: 'Dify AI', icon: Bot },
    { key: 'chatwoot', name: 'Chatwoot', icon: MessageCircle },
] as const;

const cards = computed(() => specs.map((spec) => {
    const channel = channels.value.find((item) => item.provider.toLowerCase().includes(spec.key));
    const conversationsCount = conversations.value.filter((item) => item.channel?.provider.toLowerCase().includes(spec.key)).length;
    const configured = spec.key === 'dify'
        ? integrationSettings.value?.dify.api_key_configured
        : spec.key === 'chatwoot'
            ? integrationSettings.value?.chatwoot.api_token_configured
            : spec.key === 'telegram'
                ? integrationSettings.value?.telegram?.bot_token_configured || Boolean(channel)
                : Boolean(channel);

    return { ...spec, channel, conversationsCount, configured: Boolean(configured) };
}));

const connectedCount = computed(() => cards.value.filter((card) => card.configured).length);

onMounted(async () => {
    if (! integrationSettings.value) await store.loadIntegrationSettings();
});

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-6">
        <Card title="Интеграции" subtitle="Настройки и статус рабочих каналов.">
            <template #actions>
                <Badge :tone="connectedCount >= 3 ? 'green' : 'amber'">{{ connectedCount }}/{{ cards.length }} активно</Badge>
            </template>
            <p class="flex items-center gap-2 text-sm text-emerald-400"><CheckCircle2 class="h-4 w-4" /> Dify и Chatwoot берут URL из .env, ключи задаются для workspace.</p>
        </Card>

        <IntegrationSettingsPanel />

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <article v-for="card in cards" :key="card.key" class="rounded-md border p-5 ui-surface">
                <div class="flex items-start justify-between gap-3">
                    <component :is="card.icon" class="h-6 w-6 text-blue-400" />
                    <Badge :tone="card.configured ? 'green' : 'amber'">{{ card.configured ? 'подключено' : 'не подключено' }}</Badge>
                </div>
                <h3 class="mt-4 font-semibold ui-text">{{ card.name }}</h3>
                <p class="mt-1 text-xs ui-subtle">{{ card.conversationsCount }} диалогов</p>
                <p v-if="card.channel" class="mt-2 text-xs ui-subtle">{{ card.channel.name }} - {{ card.channel.status }}</p>
            </article>
        </div>
    </section>
</template>