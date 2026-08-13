<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import type { Message } from '../../../stores/crmDashboard';
import ChatMessageBubble from './ChatMessageBubble.vue';

const props = defineProps<{ messages: Message[] }>();

const scrollEl = ref<HTMLDivElement | null>(null);

type ThreadItem = { message: Message; showHeader: boolean; dateLabel: string | null };

const items = computed<ThreadItem[]>(() => {
    let lastSender: string | null = null;
    let lastTime = 0;
    let lastDay = '';

    return props.messages.map((message): ThreadItem => {
        const sentAt = message.sent_at ? new Date(message.sent_at) : null;
        const day = sentAt ? sentAt.toDateString() : '';
        const dateLabel = day && day !== lastDay
            ? new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' }).format(sentAt as Date)
            : null;

        const gapMs = sentAt ? sentAt.getTime() - lastTime : Infinity;
        const showHeader = message.sender_type !== lastSender || gapMs > 5 * 60 * 1000 || dateLabel !== null;

        lastSender = message.sender_type;
        lastTime = sentAt ? sentAt.getTime() : lastTime;
        if (day) lastDay = day;

        return { message, showHeader, dateLabel };
    });
});

function scrollToBottom(): void {
    if (scrollEl.value) scrollEl.value.scrollTop = scrollEl.value.scrollHeight;
}

watch(() => props.messages.length, async () => {
    await nextTick();
    scrollToBottom();
}, { immediate: true });

watch(() => props.messages[0]?.conversation_id, async () => {
    await nextTick();
    scrollToBottom();
});
</script>

<template>
    <div ref="scrollEl" class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto px-5 py-5 bg-background">
        <template v-for="item in items" :key="item.message.id">
            <div v-if="item.dateLabel" class="my-2 flex items-center justify-center">
                <span class="rounded-full bg-muted px-3 py-1 text-[11px] font-medium ui-subtle">{{ item.dateLabel }}</span>
            </div>
            <ChatMessageBubble :message="item.message" :show-header="item.showHeader" :class="item.showHeader ? 'mt-2' : 'mt-0.5'" />
        </template>
        <p v-if="! messages.length" class="m-auto text-sm ui-subtle">Сообщений пока нет.</p>
    </div>
</template>
