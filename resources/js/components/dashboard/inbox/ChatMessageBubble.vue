<script setup lang="ts">
import { computed } from 'vue';
import { Bot } from '@lucide/vue';
import type { Message } from '../../../stores/crmDashboard';

const props = defineProps<{ message: Message }>();

const isCustomer = computed(() => props.message.sender_type === 'customer');
const senderLabel = computed(() => {
    if (props.message.sender_type === 'ai') return 'WERO AI';
    if (props.message.sender_type === 'operator') return props.message.sender_name ?? 'Оператор';

    return props.message.sender_name ?? 'Клиент';
});
const timeLabel = computed(() => (props.message.sent_at
    ? new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(new Date(props.message.sent_at))
    : ''));
const bubbleClass = computed(() => {
    if (isCustomer.value) return 'rounded-bl-sm border border-border bg-card text-foreground';
    if (props.message.sender_type === 'ai') return 'rounded-br-sm bg-primary/12 text-foreground';

    return 'rounded-br-sm bg-primary text-primary-foreground';
});
</script>

<template>
    <div class="flex w-full flex-col gap-1" :class="isCustomer ? 'items-start' : 'items-end'">
        <span v-if="! isCustomer" class="mr-1 flex items-center gap-1 text-[10px] font-semibold uppercase ui-subtle">
            <Bot v-if="message.sender_type === 'ai'" class="h-3 w-3 text-primary" />{{ senderLabel }}
        </span>
        <div
            class="max-w-[78%] rounded-2xl px-4 py-2.5 text-sm leading-6"
            :class="bubbleClass"
        >
            <p class="whitespace-pre-wrap break-words">{{ message.body }}</p>
            <p class="mt-1 text-right text-[10px] opacity-70">{{ timeLabel }}</p>
        </div>
    </div>
</template>
