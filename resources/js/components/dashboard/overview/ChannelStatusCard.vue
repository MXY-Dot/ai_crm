<script setup lang="ts">
import { Globe2, MessageCircle, MessagesSquare, Send } from '@lucide/vue';
import type { Channel } from '../../../stores/crmDashboard';

defineProps<{ channels: Channel[] }>();
const channelIcons: Record<string, unknown> = { telegram: Send, whatsapp: MessageCircle, website: Globe2, web: Globe2 };

function isHealthy(status: string): boolean {
    return status === 'connected' || status === 'active';
}
</script>

<template>
    <div class="flex flex-col rounded-xl border p-5" style="border-color: var(--border); background: var(--card)">
        <h3 class="mb-4 font-display text-base font-semibold ui-text">Работоспособность каналов</h3>
        <div class="space-y-3">
            <div v-for="channel in channels" :key="channel.id" class="flex items-center justify-between text-sm">
                <span class="flex items-center gap-2 ui-text">
                    <component :is="channelIcons[channel.provider] ?? MessagesSquare" class="h-4 w-4 ui-subtle" />
                    {{ channel.name }}
                </span>
                <span class="flex items-center gap-1.5 text-xs font-medium" :class="isHealthy(channel.status) ? 'text-primary' : 'text-destructive'">
                    <span class="h-2 w-2 rounded-full" :class="isHealthy(channel.status) ? 'bg-primary' : 'animate-pulse bg-destructive'" />
                    {{ isHealthy(channel.status) ? 'OK' : channel.status }}
                </span>
            </div>
            <p v-if="! channels.length" class="text-sm ui-subtle">Каналы ещё не подключены</p>
        </div>
    </div>
</template>
