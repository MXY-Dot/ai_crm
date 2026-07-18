<script setup lang="ts">
import type { Conversation } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Badge } from '../../ui/badge';
import { badgeTone } from './inboxUi';

defineProps<{ conversations: Conversation[]; selectedId: number | null }>();
defineEmits<{ select: [id: number] }>();
const locale = useLocaleStore();
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3">
            <button
                v-for="conversation in conversations"
                :key="conversation.id"
                class="w-full rounded-md border p-3 text-left transition hover:border-emerald-300/50 hover:bg-white/[0.04]"
                :class="selectedId === conversation.id ? 'border-emerald-300/40 bg-emerald-300/10' : 'border-white/10 bg-white/[0.02]'"
                @click="$emit('select', conversation.id)"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-medium text-white">{{ conversation.subject }}</p>
                        <p class="mt-1 truncate text-xs text-zinc-500">
                            {{ conversation.customer?.name ?? locale.t('common.unknown') }} - {{ conversation.channel?.name }}
                        </p>
                    </div>
                    <Badge :tone="badgeTone(conversation.status)">{{ conversation.status }}</Badge>
                </div>
                <p class="mt-3 line-clamp-2 text-sm leading-5 text-zinc-400">{{ conversation.ai_summary }}</p>
            </button>
        </div>
    </div>
</template>