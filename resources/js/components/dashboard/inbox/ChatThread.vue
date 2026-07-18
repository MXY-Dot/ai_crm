<script setup lang="ts">
import type { Message } from '../../../stores/crmDashboard';
import ChatMessageBubble from './ChatMessageBubble.vue';

defineProps<{ messages: Message[] }>();
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col-reverse gap-3 overflow-y-auto rounded-md bg-zinc-950/40 px-4 py-5">
        <ChatMessageBubble v-for="message in [...messages].reverse()" :key="message.id" :message="message" />
    </div>
</template>