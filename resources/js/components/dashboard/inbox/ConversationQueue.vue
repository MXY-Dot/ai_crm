<script setup lang="ts">
import { computed, ref } from 'vue';
import { Globe2, MessageCircle, Send } from '@lucide/vue';
import type { Conversation } from '../../../stores/crmDashboard';
import { Avatar, AvatarFallback } from '../../ui/avatar';
import { useLocaleStore } from '../../../stores/locale';

const props = defineProps<{ conversations: Conversation[]; selectedId: number | null }>();
defineEmits<{ select: [id: number] }>();
const locale = useLocaleStore();

const tab = ref<'all' | 'new' | 'active'>('all');
const channelIcons: Record<string, unknown> = { telegram: Send, whatsapp: MessageCircle, website: Globe2, web: Globe2 };

const filtered = computed(() => props.conversations.filter((item) => {
    if (tab.value === 'new') return item.status === 'pending';
    if (tab.value === 'active') return item.status === 'open' || item.status === 'pending_operator';

    return true;
}));

function initials(name: string): string {
    return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

function timeLabel(value: string | null): string {
    if (! value) return '';

    return new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex items-center gap-1 border-b px-3 py-2 text-xs font-semibold border-border">
            <button class="rounded-md px-2 py-1 transition" :class="tab === 'all' ? 'bg-muted ui-text' : 'ui-subtle hover:bg-muted'" @click="tab = 'all'">Все</button>
            <button class="rounded-md px-2 py-1 transition" :class="tab === 'new' ? 'bg-muted ui-text' : 'ui-subtle hover:bg-muted'" @click="tab = 'new'">Новые</button>
            <button class="rounded-md px-2 py-1 transition" :class="tab === 'active' ? 'bg-muted ui-text' : 'ui-subtle hover:bg-muted'" @click="tab = 'active'">В работе</button>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto">
            <button
                v-for="conversation in filtered"
                :key="conversation.id"
                class="flex w-full items-start gap-3 border-l-4 p-3 text-left transition"
                :class="selectedId === conversation.id ? 'border-primary bg-muted' : 'border-transparent hover:bg-muted'"
                @click="$emit('select', conversation.id)"
            >
                <span class="relative shrink-0">
                    <Avatar><AvatarFallback>{{ initials(conversation.customer?.name ?? '?') }}</AvatarFallback></Avatar>
                    <span class="absolute -bottom-1 -right-1 grid h-4 w-4 place-items-center rounded-full shadow-sm bg-card">
                        <component :is="channelIcons[conversation.channel?.provider ?? ''] ?? Send" class="h-2.5 w-2.5 text-primary" />
                    </span>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-baseline justify-between gap-2">
                        <span class="truncate text-sm font-semibold ui-text">{{ conversation.customer?.name ?? locale.t('common.unknown') }}</span>
                        <span class="shrink-0 font-mono text-[11px] ui-subtle">{{ timeLabel(conversation.last_message_at) }}</span>
                    </span>
                    <span class="mt-0.5 block truncate text-[13px] ui-subtle">{{ conversation.ai_summary ?? conversation.subject }}</span>
                </span>
            </button>
            <p v-if="! filtered.length" class="p-6 text-center text-sm ui-subtle">Нет диалогов в этой категории</p>
        </div>
    </div>
</template>
