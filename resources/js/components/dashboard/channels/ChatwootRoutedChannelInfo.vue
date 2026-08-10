<script setup lang="ts">
import { Copy, ExternalLink } from '@lucide/vue';
import type { IntegrationSettings } from '../../../stores/crmDashboard';

const props = defineProps<{ chatwoot: IntegrationSettings['chatwoot'] | null; description: string }>();

async function copyWebhook(): Promise<void> {
    if (! props.chatwoot?.webhook_url) return;
    await navigator.clipboard.writeText(props.chatwoot.webhook_url);
}
</script>

<template>
    <div class="space-y-3 text-sm">
        <p class="ui-subtle">{{ description }}</p>
        <div v-if="chatwoot?.webhook_url" class="rounded-lg border p-3" style="border-color: var(--border); background: var(--muted)">
            <span class="mb-1 block text-[11px] font-semibold uppercase ui-subtle">Webhook URL</span>
            <p class="break-all font-mono text-xs ui-text">{{ chatwoot.webhook_url }}</p>
            <button type="button" class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline" @click="copyWebhook">
                <Copy class="h-3.5 w-3.5" /> Скопировать
            </button>
        </div>
        <a v-if="chatwoot?.url" class="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline" :href="chatwoot.url" target="_blank" rel="noopener">
            <ExternalLink class="h-3.5 w-3.5" /> Настроить в панели каналов
        </a>
    </div>
</template>
