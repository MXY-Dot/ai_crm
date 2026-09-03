<script setup lang="ts">
// Was its own simpler scroll-to-bottom + ad-hoc date-pill implementation;
// now uses the same MessageScroller/Marker infrastructure ChatMessages.vue
// (customer Inbox) and TeamChatThread.vue (team chat) both use, so a support
// ticket thread genuinely matches rather than just reusing the bubble
// component. Same day-bucketing + consecutive-same-sender grouping algorithm
// as those two, ported to Message's sender_type shape.
import { computed } from 'vue';
import type { Message } from '../../../stores/crmDashboard';
import { Marker, MarkerContent } from '../../ui/marker';
import { MessageGroup } from '../../ui/message';
import {
    MessageScroller,
    MessageScrollerButton,
    MessageScrollerContent,
    MessageScrollerItem,
    MessageScrollerProvider,
    MessageScrollerViewport,
} from '../../ui/message-scroller';
import ChatMessageBubble from './ChatMessageBubble.vue';

const props = defineProps<{ messages: Message[] }>();

type ThreadRow =
    | { kind: 'date'; key: string; label: string }
    | { kind: 'group'; key: string; messages: Message[] };

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
    let currentGroup: Message[] = [];
    let lastSender: string | null = null;
    let lastTime = 0;

    function flushGroup(): void {
        if (currentGroup.length) result.push({ kind: 'group', key: `group-${currentGroup[0].id}`, messages: currentGroup });
        currentGroup = [];
    }

    for (const message of props.messages) {
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

// messages[0].id, not conversation_id -- ticketMessagesToChat() (this
// component's only two real callers, both support-ticket views) stamps every
// synthetic Message with conversation_id: 0, which would key every ticket's
// thread identically and stop the scroller from resetting when switching
// between tickets.
const threadKey = computed(() => props.messages[0]?.id ?? 'empty');
</script>

<template>
    <MessageScrollerProvider :key="threadKey" auto-scroll default-scroll-position="end" class="min-h-0 flex-1 bg-background">
        <MessageScroller class="h-full">
            <MessageScrollerViewport>
                <MessageScrollerContent class="px-4 py-4">
                    <template v-for="row in rows" :key="row.key">
                        <Marker v-if="row.kind === 'date'" variant="separator" class="my-2">
                            <MarkerContent>{{ row.label }}</MarkerContent>
                        </Marker>

                        <MessageScrollerItem v-else :message-id="String(row.messages[0].id)" scroll-anchor>
                            <MessageGroup>
                                <ChatMessageBubble
                                    v-for="(message, index) in row.messages" :key="message.id"
                                    :message="message"
                                    :show-header="index === 0 && message.sender_type !== 'operator'"
                                    :show-avatar="index === row.messages.length - 1"
                                />
                            </MessageGroup>
                        </MessageScrollerItem>
                    </template>

                    <p v-if="! rows.length" class="py-10 text-center text-sm ui-subtle">Сообщений пока нет.</p>
                </MessageScrollerContent>
            </MessageScrollerViewport>
            <MessageScrollerButton direction="end" />
        </MessageScroller>
    </MessageScrollerProvider>
</template>
