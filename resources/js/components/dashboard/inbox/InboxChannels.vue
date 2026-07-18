<script setup lang="ts">
import { computed } from 'vue';
import { Copy, ExternalLink } from '@lucide/vue';
import type { Channel } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Badge } from '../../ui/badge';
import { badgeTone } from './inboxUi';

const props = defineProps<{ channels: Channel[] }>();
const locale = useLocaleStore();
const crmWebhookUrl = computed(() => `${window.location.origin}/api/chatwoot/webhook?tenant_slug=demo`);
const chatwootInboxUrl = 'http://127.0.0.1:3000/app/accounts/2/settings/inboxes';

function setupKey(provider: string): string {
    if (['telegram', 'whatsapp', 'website'].includes(provider)) return provider;
    return 'chatwoot';
}

async function copy(value: string): Promise<void> {
    await navigator.clipboard?.writeText(value);
}
</script>

<template>
    <div class="space-y-3">
        <article v-for="channel in props.channels" :key="channel.id" class="rounded-md border border-white/10 bg-white/[0.03] p-4">
            <div class="flex items-center justify-between gap-3">
                <p class="font-medium text-white">{{ channel.name }}</p>
                <Badge :tone="badgeTone(channel.status)">{{ channel.status }}</Badge>
            </div>
            <p class="mt-2 text-sm text-zinc-400">{{ locale.t(`inbox.channels.${channel.provider}`) }} - {{ locale.t('inbox.channelText') }}</p>

            <div class="mt-4 rounded-md border border-white/10 bg-zinc-950/40 p-3">
                <p class="text-sm font-medium text-white">{{ locale.t(`channelSetup.${setupKey(channel.provider)}.title`) }}</p>
                <p class="mt-1 text-sm leading-6 text-zinc-400">{{ locale.t(`channelSetup.${setupKey(channel.provider)}.text`) }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button class="inline-flex items-center gap-2 rounded-sm border border-white/10 px-2 py-1 text-xs text-zinc-300 hover:bg-white/10" @click="copy(crmWebhookUrl)"><Copy class="h-3.5 w-3.5" />{{ locale.t('channelSetup.copyWebhook') }}</button>
                    <a class="inline-flex items-center gap-2 rounded-sm border border-white/10 px-2 py-1 text-xs text-zinc-300 hover:bg-white/10" :href="chatwootInboxUrl" target="_blank"><ExternalLink class="h-3.5 w-3.5" />{{ locale.t('channelSetup.openChatwoot') }}</a>
                </div>
                <p class="mt-3 break-all text-xs text-zinc-500">{{ crmWebhookUrl }}</p>
            </div>
        </article>
    </div>
</template>