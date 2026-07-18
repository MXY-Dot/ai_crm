<script setup lang="ts">
import { computed } from 'vue';
import { Bot } from '@lucide/vue';
import type { Message } from '../../../stores/crmDashboard';
import { messageAlignClass, messageBubbleClass } from './inboxUi';

const props = defineProps<{ message: Message }>();
defineEmits<{ useDraft: [body: string]; sendDraft: [body: string] }>();

const senderLabel = computed(() => {
    if (props.message.sender_type === 'ai') return 'AI auto-reply';
    if (props.message.sender_type === 'operator') return props.message.sender_name ? `Operator: ${props.message.sender_name}` : 'Operator';
    return props.message.sender_name ?? 'Customer';
});
</script>

<template>
    <div class="flex w-full" :class="messageAlignClass(message.sender_type)">
        <div class="max-w-[78%] rounded-2xl px-4 py-3 text-sm leading-6" :class="messageBubbleClass(message.sender_type)">
            <p class="mb-1 flex items-center gap-2 text-[11px] font-semibold uppercase text-inherit opacity-60">
                <Bot v-if="message.sender_type === 'ai'" class="h-3 w-3" />
                {{ senderLabel }}
            </p>
            <p class="whitespace-pre-wrap break-words">{{ message.body }}</p>
        </div>
    </div>
</template>