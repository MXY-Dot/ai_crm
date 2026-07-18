<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Bot, CheckCircle2, Copy, ExternalLink, PlugZap, Send, Sparkles } from '@lucide/vue';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Badge } from '../ui/badge';
import { Button } from '../ui/button';
import { Card } from '../ui/card';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { conversations, integrationSettings, messages } = storeToRefs(store);
const testing = ref<'dify' | 'chatwoot' | null>(null);
const result = ref<{ ok: boolean; message: string } | null>(null);

const difyOk = computed(() => integrationSettings.value?.dify.api_key_configured ?? false);
const chatwootOk = computed(() => integrationSettings.value?.chatwoot.api_token_configured ?? false);
const webhookOk = computed(() => Boolean(integrationSettings.value?.chatwoot.webhook_url));
const aiDraftOk = computed(() => messages.value.some((message) => message.sender_type === 'ai'));
const replyOk = computed(() => conversations.value.some((conversation) => Boolean(conversation.external_id)));
const readyCount = computed(() => [difyOk.value, chatwootOk.value, webhookOk.value, aiDraftOk.value, replyOk.value].filter(Boolean).length);
const percent = computed(() => Math.round((readyCount.value / 5) * 100));
const chatwootUrl = computed(() => integrationSettings.value?.chatwoot.url || 'http://127.0.0.1:3000');
const difyUrl = computed(() => (integrationSettings.value?.dify.url || 'http://127.0.0.1:8080').replace(/\/v1\/?$/, ''));
const webhookUrl = computed(() => integrationSettings.value?.chatwoot.webhook_url ?? '');
const checks = computed(() => [
    { key: 'dify', ok: difyOk.value, icon: Bot },
    { key: 'chatwoot', ok: chatwootOk.value, icon: Send },
    { key: 'webhook', ok: webhookOk.value, icon: Copy },
    { key: 'draft', ok: aiDraftOk.value, icon: Sparkles },
    { key: 'reply', ok: replyOk.value, icon: CheckCircle2 },
]);

async function test(provider: 'dify' | 'chatwoot'): Promise<void> {
    testing.value = provider;
    result.value = null;
    try {
        const response = await store.testIntegrationConnection({ provider });
        result.value = { ok: response.ok, message: response.message };
    } catch (caught) {
        result.value = { ok: false, message: caught instanceof Error ? caught.message : 'Connection test failed' };
    } finally {
        testing.value = null;
    }
}

async function copyWebhook(): Promise<void> {
    if (! webhookUrl.value) return;
    await navigator.clipboard.writeText(webhookUrl.value);
    result.value = { ok: true, message: locale.t('health.copied') };
}

onMounted(async () => {
    if (! integrationSettings.value) await store.loadIntegrationSettings();
});
</script>

<template>
    <Card :title="locale.t('health.title')" :subtitle="locale.t('health.subtitle')">
        <template #actions>
            <Badge :tone="percent >= 80 ? 'green' : percent >= 50 ? 'amber' : 'red'">{{ percent }}%</Badge>
        </template>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <article v-for="check in checks" :key="check.key" class="rounded-md border border-white/10 bg-white/[0.03] p-3">
                <component :is="check.icon" class="h-4 w-4" :class="check.ok ? 'text-emerald-300' : 'text-amber-300'" />
                <p class="mt-3 text-sm font-medium text-white">{{ locale.t(`health.${check.key}.title`) }}</p>
                <p class="mt-1 text-xs leading-5 text-zinc-400">{{ locale.t(`health.${check.key}.${check.ok ? 'ok' : 'todo'}`) }}</p>
            </article>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <Button size="sm" :disabled="testing !== null" @click="test('dify')"><PlugZap class="h-4 w-4" />{{ testing === 'dify' ? locale.t('settings.testing') : locale.t('settings.testDify') }}</Button>
            <Button size="sm" :disabled="testing !== null" @click="test('chatwoot')"><PlugZap class="h-4 w-4" />{{ testing === 'chatwoot' ? locale.t('settings.testing') : locale.t('settings.testChatwoot') }}</Button>
            <Button size="sm" :disabled="!webhookUrl" @click="copyWebhook"><Copy class="h-4 w-4" />{{ locale.t('health.copyWebhook') }}</Button>
            <a class="inline-flex h-8 min-w-0 items-center gap-2 rounded-md border border-white/10 bg-white/5 px-3 text-sm font-medium text-zinc-100 hover:bg-white/10" :href="chatwootUrl" target="_blank"><ExternalLink class="h-4 w-4" />Chatwoot</a>
            <a class="inline-flex h-8 min-w-0 items-center gap-2 rounded-md border border-white/10 bg-white/5 px-3 text-sm font-medium text-zinc-100 hover:bg-white/10" :href="difyUrl" target="_blank"><ExternalLink class="h-4 w-4" />Dify</a>
        </div>

        <p v-if="result" class="mt-4 rounded-md border px-3 py-2 text-sm" :class="result.ok ? 'border-emerald-300/30 bg-emerald-300/10 text-emerald-100' : 'border-red-300/30 bg-red-300/10 text-red-100'">{{ result.message }}</p>
    </Card>
</template>