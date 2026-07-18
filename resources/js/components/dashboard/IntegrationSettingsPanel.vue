<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Bot, KeyRound, PlugZap, Save, Send } from '@lucide/vue';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Badge } from '../ui/badge';
import { Button } from '../ui/button';
import { Card } from '../ui/card';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { integrationSettings, busy, error } = storeToRefs(store);

const form = reactive({
    difyApiKey: '',
    difyTimeout: 12,
    handoffThreshold: 70,
    chatwootAccountId: null as number | null,
    chatwootApiToken: '',
    chatwootSecret: '',
    chatwootAutoReply: false,
    telegramBotToken: '',
    telegramSecret: '',
    telegramAutoReply: false,
});

const testingProvider = ref<'dify' | 'chatwoot' | null>(null);
const connectionTest = ref<{ ok: boolean; message: string; status: string } | null>(null);
const difyConfigured = computed(() => integrationSettings.value?.dify.api_key_configured ?? false);
const chatwootConfigured = computed(() => integrationSettings.value?.chatwoot.api_token_configured ?? false);
const telegramConfigured = computed(() => integrationSettings.value?.telegram?.bot_token_configured ?? false);

function syncForm(): void {
    const settings = integrationSettings.value;
    if (! settings) return;

    form.difyApiKey = '';
    form.difyTimeout = settings.dify.timeout ?? 12;
    form.handoffThreshold = settings.dify.handoff_threshold ?? 70;
    form.chatwootAccountId = settings.chatwoot.account_id ? Number(settings.chatwoot.account_id) : null;
    form.chatwootApiToken = '';
    form.chatwootSecret = '';
    form.chatwootAutoReply = settings.chatwoot.auto_reply_enabled ?? false;
    form.telegramBotToken = '';
    form.telegramSecret = '';
    form.telegramAutoReply = settings.telegram?.auto_reply_enabled ?? false;
}

async function testConnection(provider: 'dify' | 'chatwoot'): Promise<void> {
    testingProvider.value = provider;
    connectionTest.value = null;

    try {
        const result = await store.testIntegrationConnection({
            provider,
            dify: provider === 'dify' ? {
                api_key: form.difyApiKey || undefined,
                timeout: Number(form.difyTimeout),
            } : undefined,
            chatwoot: provider === 'chatwoot' ? {
                account_id: form.chatwootAccountId || null,
                api_token: form.chatwootApiToken || undefined,
                webhook_secret: form.chatwootSecret || undefined,
                auto_reply_enabled: form.chatwootAutoReply,
            } : undefined,
        });
        connectionTest.value = { ok: result.ok, message: result.message, status: result.status };
    } catch (caught) {
        connectionTest.value = {
            ok: false,
            message: caught instanceof Error ? caught.message : 'Connection test failed',
            status: 'failed',
        };
    } finally {
        testingProvider.value = null;
    }
}

async function save(): Promise<void> {
    await store.updateIntegrationSettings({
        dify: {
            api_key: form.difyApiKey || undefined,
            timeout: Number(form.difyTimeout),
            handoff_threshold: Number(form.handoffThreshold),
        },
        chatwoot: {
            account_id: form.chatwootAccountId || null,
            api_token: form.chatwootApiToken || undefined,
            webhook_secret: form.chatwootSecret || undefined,
        },
        telegram: {
            bot_token: form.telegramBotToken || undefined,
            webhook_secret: form.telegramSecret || undefined,
            auto_reply_enabled: form.telegramAutoReply,
        },
    });

    syncForm();
}

onMounted(async () => {
    await store.loadIntegrationSettings();
    syncForm();
});
</script>

<template>
    <Card :title="locale.t('settings.title')" :subtitle="locale.t('settings.formSubtitle')">
        <form class="space-y-5" @submit.prevent="save">
            <div class="grid gap-4 xl:grid-cols-3">
                <fieldset class="space-y-3 rounded-md border border-white/10 bg-white/[0.03] p-4">
                    <legend class="flex items-center gap-2 px-1 text-sm font-medium text-white"><Bot class="h-4 w-4 text-emerald-300" /> Dify</legend>
                    <Badge :tone="difyConfigured ? 'green' : 'amber'">{{ difyConfigured ? locale.t('settings.configured') : locale.t('settings.notConfigured') }}</Badge>
                    <p class="break-all text-xs text-zinc-500">{{ integrationSettings?.dify.url || 'DIFY_API_URL' }}</p>

                    <label class="block text-sm text-zinc-300">
                        <span class="mb-1 block text-xs uppercase text-zinc-500">{{ locale.t('settings.difyApiKey') }}</span>
                        <div class="relative">
                            <KeyRound class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                            <input v-model="form.difyApiKey" type="password" :placeholder="locale.t('settings.keepSecretPlaceholder')" class="h-10 w-full rounded-md border border-white/10 bg-zinc-950 pl-9 pr-3 text-sm text-white outline-none focus:border-emerald-300" autocomplete="new-password" />
                        </div>
                        <span v-if="integrationSettings?.dify.api_key_mask" class="mt-1 block text-xs text-zinc-500">{{ integrationSettings.dify.api_key_mask }}</span>
                    </label>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block text-sm text-zinc-300">
                            <span class="mb-1 block text-xs uppercase text-zinc-500">{{ locale.t('settings.timeout') }}</span>
                            <input v-model.number="form.difyTimeout" type="number" min="3" max="60" class="h-10 w-full rounded-md border border-white/10 bg-zinc-950 px-3 text-sm text-white outline-none focus:border-emerald-300" />
                        </label>
                        <label class="block text-sm text-zinc-300">
                            <span class="mb-1 block text-xs uppercase text-zinc-500">{{ locale.t('settings.handoffThreshold') }}</span>
                            <input v-model.number="form.handoffThreshold" type="number" min="1" max="100" class="h-10 w-full rounded-md border border-white/10 bg-zinc-950 px-3 text-sm text-white outline-none focus:border-emerald-300" />
                        </label>
                    </div>

                    <Button size="sm" variant="secondary" :disabled="testingProvider !== null" @click="testConnection('dify')">
                        <PlugZap class="h-4 w-4" /> {{ testingProvider === 'dify' ? locale.t('settings.testing') : locale.t('settings.testDify') }}
                    </Button>
                </fieldset>

                <fieldset class="space-y-3 rounded-md border border-white/10 bg-white/[0.03] p-4">
                    <legend class="flex items-center gap-2 px-1 text-sm font-medium text-white"><Send class="h-4 w-4 text-emerald-300" /> Chatwoot</legend>
                    <Badge :tone="chatwootConfigured ? 'green' : 'amber'">{{ chatwootConfigured ? locale.t('settings.configured') : locale.t('settings.notConfigured') }}</Badge>
                    <p class="break-all text-xs text-zinc-500">{{ integrationSettings?.chatwoot.url || 'CHATWOOT_URL' }}</p>

                    <label class="block text-sm text-zinc-300">
                        <span class="mb-1 block text-xs uppercase text-zinc-500">{{ locale.t('settings.accountId') }}</span>
                        <input v-model.number="form.chatwootAccountId" type="number" min="1" class="h-10 w-full rounded-md border border-white/10 bg-zinc-950 px-3 text-sm text-white outline-none focus:border-emerald-300" />
                    </label>
                    <label class="block text-sm text-zinc-300">
                        <span class="mb-1 block text-xs uppercase text-zinc-500">{{ locale.t('settings.chatwootApiToken') }}</span>
                        <input v-model="form.chatwootApiToken" type="password" :placeholder="locale.t('settings.keepSecretPlaceholder')" class="h-10 w-full rounded-md border border-white/10 bg-zinc-950 px-3 text-sm text-white outline-none focus:border-emerald-300" autocomplete="new-password" />
                        <span v-if="integrationSettings?.chatwoot.api_token_mask" class="mt-1 block text-xs text-zinc-500">{{ integrationSettings.chatwoot.api_token_mask }}</span>
                    </label>
                    <label class="block text-sm text-zinc-300">
                        <span class="mb-1 block text-xs uppercase text-zinc-500">{{ locale.t('settings.webhookSecret') }}</span>
                        <input v-model="form.chatwootSecret" type="password" :placeholder="locale.t('settings.keepSecretPlaceholder')" class="h-10 w-full rounded-md border border-white/10 bg-zinc-950 px-3 text-sm text-white outline-none focus:border-emerald-300" autocomplete="new-password" />
                    </label>
                    <p class="break-all text-xs text-zinc-500">{{ integrationSettings?.chatwoot.webhook_url }}</p>
                    <label class="flex items-center gap-3 rounded-md border border-white/10 bg-zinc-950 px-3 py-2 text-sm text-zinc-200">
                        <input v-model="form.chatwootAutoReply" type="checkbox" class="h-4 w-4 accent-emerald-400" />
                        <span>AI auto-reply in Chatwoot</span>
                    </label>

                    <Button size="sm" variant="secondary" :disabled="testingProvider !== null" @click="testConnection('chatwoot')">
                        <PlugZap class="h-4 w-4" /> {{ testingProvider === 'chatwoot' ? locale.t('settings.testing') : locale.t('settings.testChatwoot') }}
                    </Button>
                </fieldset>

                <fieldset class="space-y-3 rounded-md border border-white/10 bg-white/[0.03] p-4">
                    <legend class="flex items-center gap-2 px-1 text-sm font-medium text-white"><Send class="h-4 w-4 text-emerald-300" /> Telegram</legend>
                    <Badge :tone="telegramConfigured ? 'green' : 'amber'">{{ telegramConfigured ? locale.t('settings.configured') : locale.t('settings.notConfigured') }}</Badge>

                    <label class="block text-sm text-zinc-300">
                        <span class="mb-1 block text-xs uppercase text-zinc-500">Bot token</span>
                        <input v-model="form.telegramBotToken" type="password" :placeholder="locale.t('settings.keepSecretPlaceholder')" class="h-10 w-full rounded-md border border-white/10 bg-zinc-950 px-3 text-sm text-white outline-none focus:border-emerald-300" autocomplete="new-password" />
                        <span v-if="integrationSettings?.telegram?.bot_token_mask" class="mt-1 block text-xs text-zinc-500">{{ integrationSettings.telegram?.bot_token_mask }}</span>
                    </label>
                    <label class="block text-sm text-zinc-300">
                        <span class="mb-1 block text-xs uppercase text-zinc-500">{{ locale.t('settings.webhookSecret') }}</span>
                        <input v-model="form.telegramSecret" type="password" :placeholder="locale.t('settings.keepSecretPlaceholder')" class="h-10 w-full rounded-md border border-white/10 bg-zinc-950 px-3 text-sm text-white outline-none focus:border-emerald-300" autocomplete="new-password" />
                    </label>
                    <p class="break-all text-xs text-zinc-500">{{ integrationSettings?.telegram?.webhook_url }}</p>
                    <label class="flex items-center gap-3 rounded-md border border-white/10 bg-zinc-950 px-3 py-2 text-sm text-zinc-200">
                        <input v-model="form.telegramAutoReply" type="checkbox" class="h-4 w-4 accent-emerald-400" />
                        <span>AI auto-reply in Telegram</span>
                    </label>
                </fieldset>
            </div>

            <p v-if="connectionTest" class="rounded-md border px-3 py-2 text-sm" :class="connectionTest.ok ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-100' : 'border-red-400/30 bg-red-400/10 text-red-100'">
                {{ connectionTest.message }}
            </p>
            <p v-if="error" class="rounded-md border border-red-400/30 bg-red-400/10 px-3 py-2 text-sm text-red-100">{{ error }}</p>

            <div class="flex justify-end">
                <Button variant="primary" type="submit" :disabled="busy">
                    <Save class="h-4 w-4" /> {{ busy ? locale.t('common.waiting') : locale.t('settings.save') }}
                </Button>
            </div>
        </form>
    </Card>
</template>