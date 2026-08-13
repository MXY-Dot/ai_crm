<script setup lang="ts">
import { computed } from 'vue';
import { BotIcon } from '@lucide/vue';
import { useChatStore } from '@/stores/chat';
import type { ChatMessage } from '@/lib/chat/types';
import {
    MessageScroller,
    MessageScrollerButton,
    MessageScrollerContent,
    MessageScrollerItem,
    MessageScrollerProvider,
    MessageScrollerViewport,
} from '@/components/ui/message-scroller';
import { Marker, MarkerContent } from '@/components/ui/marker';
import { MessageGroup } from '@/components/ui/message';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import ChatLoadOlderWatcher from './ChatLoadOlderWatcher.vue';
import ChatMessageItem from './ChatMessageItem.vue';

const chat = useChatStore();

type ThreadRow =
    | { kind: 'date'; key: string; label: string }
    | { kind: 'group'; key: string; messages: ChatMessage[] };

const GROUP_WINDOW_MS = 5 * 60 * 1000;

function dateLabel(value: string): string {
    const date = new Date(value);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (date.toDateString() === today.toDateString()) return 'Сегодня';
    if (date.toDateString() === yesterday.toDateString()) return 'Вчера';

    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: date.getFullYear() !== today.getFullYear() ? 'numeric' : undefined }).format(date);
}

const rows = computed<ThreadRow[]>(() => {
    const result: ThreadRow[] = [];
    let lastDate: string | null = null;
    let currentGroup: ChatMessage[] = [];
    let lastSender: string | null = null;
    let lastTime = 0;

    function flushGroup(): void {
        if (currentGroup.length) result.push({ kind: 'group', key: `group-${currentGroup[0].id}`, messages: currentGroup });
        currentGroup = [];
    }

    for (const message of chat.activeMessages) {
        const sentAt = message.sent_at ?? new Date().toISOString();
        const day = new Date(sentAt).toDateString();

        if (day !== lastDate) {
            flushGroup();
            result.push({ kind: 'date', key: `date-${day}`, label: dateLabel(sentAt) });
            lastDate = day;
            lastSender = null;
        }

        const time = new Date(sentAt).getTime();
        const sameSender = message.sender_type === lastSender;
        const withinWindow = time - lastTime < GROUP_WINDOW_MS;

        if (! sameSender || ! withinWindow) flushGroup();

        currentGroup.push(message);
        lastSender = message.sender_type;
        lastTime = time;
    }

    flushGroup();

    return result;
});

const conversationId = computed(() => chat.activeConversationId);
const loading = computed(() => (conversationId.value ? chat.messagesLoading[conversationId.value] : false));
const loadingOlder = computed(() => (conversationId.value ? chat.loadingOlder[conversationId.value] : false));

function onLoadOlder(): void {
    if (conversationId.value) chat.loadOlderMessages(conversationId.value);
}
</script>

<template>
    <div v-if="loading" class="flex flex-1 flex-col justify-end gap-4 p-4">
        <Skeleton v-for="i in 5" :key="i" class="h-12 w-2/3 rounded-2xl" :class="i % 2 ? 'self-start' : 'self-end'" />
    </div>

    <MessageScrollerProvider :key="conversationId" v-else auto-scroll default-scroll-position="end" class="min-h-0 flex-1">
        <ChatLoadOlderWatcher :conversation-id="conversationId" @load-older="onLoadOlder" />
        <MessageScroller class="h-full">
            <MessageScrollerViewport>
                <div v-if="loadingOlder" class="flex justify-center py-2"><Spinner class="size-4 ui-subtle" /></div>

                <MessageScrollerContent class="px-4 py-4">
                    <template v-for="row in rows" :key="row.key">
                        <Marker v-if="row.kind === 'date'" variant="separator" class="my-2">
                            <MarkerContent>{{ row.label }}</MarkerContent>
                        </Marker>

                        <MessageScrollerItem v-else :message-id="String(row.messages[0].id)" scroll-anchor>
                            <MessageGroup>
                                <ChatMessageItem
                                    v-for="(message, index) in row.messages"
                                    :key="message.clientId ?? message.id"
                                    :message="message"
                                    :show-header="index === 0 && message.sender_type !== 'operator'"
                                    :show-avatar="index === row.messages.length - 1"
                                />
                            </MessageGroup>
                        </MessageScrollerItem>
                    </template>

                    <Marker v-if="chat.activeAiGenerating" role="status" class="mt-1">
                        <MarkerContent class="shimmer">
                            <BotIcon class="mr-1 inline size-3.5 align-[-2px]" />WERO AI генерирует ответ…
                        </MarkerContent>
                    </Marker>

                    <Marker v-else-if="chat.activeTypers.length" role="status" class="mt-1">
                        <MarkerContent class="shimmer">
                            {{ chat.activeTypers.length === 1 ? `${chat.activeTypers[0].name} печатает…` : 'Несколько операторов печатают…' }}
                        </MarkerContent>
                    </Marker>

                    <p v-if="! rows.length" class="py-10 text-center text-sm ui-subtle">Сообщений пока нет</p>
                </MessageScrollerContent>
            </MessageScrollerViewport>
            <MessageScrollerButton direction="end" />
        </MessageScroller>
    </MessageScrollerProvider>
</template>
