<script setup lang="ts">
import type { Message } from '../../../stores/crmDashboard';
import ChatMessageBubble from './ChatMessageBubble.vue';

defineProps<{ messages: Message[] }>();
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col-reverse gap-3 overflow-y-auto px-5 py-5" style="background: var(--background)">
        <ChatMessageBubble v-for="message in [...messages].reverse()" :key="message.id" :message="message" />
    </div>
</template>
