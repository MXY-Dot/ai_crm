<script setup lang="ts">
import { computed } from 'vue';
import { Bot, FileText } from '@lucide/vue';
import type { Message as MessageType } from '../../../stores/crmDashboard';
import { Attachment, AttachmentContent, AttachmentDescription, AttachmentMedia, AttachmentTitle, AttachmentTrigger } from '../../ui/attachment';
import { Avatar, AvatarFallback } from '../../ui/avatar';
import { Bubble, BubbleContent } from '../../ui/bubble';
import { Message, MessageAvatar, MessageContent, MessageFooter, MessageHeader } from '../../ui/message';

// Same Bubble/Message building blocks as the real Inbox chat (ChatMessageItem.vue)
// so a support ticket thread looks identical — just without the reply/edit/delete
// menu, since ticket messages don't support any of that on the backend.
const props = withDefaults(defineProps<{ message: MessageType; showHeader?: boolean }>(), { showHeader: true });

const isMine = computed(() => props.message.sender_type === 'operator');
const isAi = computed(() => props.message.sender_type === 'ai');
const align = computed<'start' | 'end'>(() => (isMine.value || isAi.value ? 'end' : 'start'));
const bubbleVariant = computed(() => {
    if (isAi.value) return 'tinted';
    if (isMine.value) return 'default';
    return 'muted';
});
const senderLabel = computed(() => {
    if (isAi.value) return 'WERO AI';
    if (isMine.value) return props.message.sender_name ?? 'Оператор';

    return props.message.sender_name ?? 'Клиент';
});
const attachment = computed(() => props.message.meta?.attachment ?? null);
const bodyIsAttachmentLabel = computed(() => Boolean(attachment.value) && /^(📷|🎤|📎)/.test(props.message.body));
const timeLabel = computed(() => (props.message.sent_at
    ? new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(new Date(props.message.sent_at))
    : ''));
</script>

<template>
    <Message :align="align">
        <MessageAvatar v-if="showHeader">
            <Avatar class="size-7">
                <AvatarFallback class="text-xs font-semibold" :class="isAi ? 'bg-accent text-accent-foreground' : 'bg-primary/10 text-primary'">
                    <Bot v-if="isAi" class="h-3.5 w-3.5" />
                    <template v-else>{{ senderLabel.trim().charAt(0).toUpperCase() || '?' }}</template>
                </AvatarFallback>
            </Avatar>
        </MessageAvatar>
        <MessageAvatar v-else />

        <MessageContent>
            <MessageHeader v-if="showHeader && ! isMine">{{ senderLabel }}</MessageHeader>
            <Bubble :variant="bubbleVariant" :align="align">
                <BubbleContent>
                    <img v-if="attachment?.type === 'photo'" :src="attachment.url" class="mb-2 max-h-64 w-full rounded-lg object-cover" loading="lazy">
                    <audio v-else-if="attachment?.type === 'voice'" :src="attachment.url" controls class="mb-2 h-10 max-w-full" />
                    <Attachment v-else-if="attachment?.type === 'document'" size="sm" class="mb-2 bg-transparent">
                        <AttachmentMedia><FileText /></AttachmentMedia>
                        <AttachmentContent>
                            <AttachmentTitle>{{ attachment.filename ?? 'Файл' }}</AttachmentTitle>
                            <AttachmentDescription>Документ</AttachmentDescription>
                        </AttachmentContent>
                        <AttachmentTrigger as-child>
                            <a :href="attachment.url" target="_blank" rel="noreferrer" :aria-label="`Открыть ${attachment.filename ?? 'файл'}`" />
                        </AttachmentTrigger>
                    </Attachment>
                    <p v-if="! bodyIsAttachmentLabel" class="whitespace-pre-wrap break-words">{{ message.body }}</p>
                </BubbleContent>
            </Bubble>
            <MessageFooter>{{ timeLabel }}</MessageFooter>
        </MessageContent>
    </Message>
</template>
