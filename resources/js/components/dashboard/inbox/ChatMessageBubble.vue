<script setup lang="ts">
import { computed } from 'vue';
import { Bot, FileText, User } from '@lucide/vue';
import type { Message } from '../../../stores/crmDashboard';

const props = withDefaults(defineProps<{ message: Message; showHeader?: boolean }>(), { showHeader: true });

const isCustomer = computed(() => props.message.sender_type === 'customer');
const senderLabel = computed(() => {
    if (props.message.sender_type === 'ai') return 'WERO AI';
    if (props.message.sender_type === 'operator') return props.message.sender_name ?? 'Оператор';

    return props.message.sender_name ?? 'Клиент';
});
const initial = computed(() => senderLabel.value.trim().charAt(0).toUpperCase() || '?');
const timeLabel = computed(() => (props.message.sent_at
    ? new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(new Date(props.message.sent_at))
    : ''));
const bubbleClass = computed(() => {
    if (isCustomer.value) return 'rounded-bl-sm border border-border bg-card text-foreground';
    if (props.message.sender_type === 'ai') return 'rounded-br-sm bg-primary/12 text-foreground';

    return 'rounded-br-sm bg-primary text-primary-foreground';
});
const avatarClass = computed(() => {
    if (isCustomer.value) return 'bg-muted ui-subtle';
    if (props.message.sender_type === 'ai') return 'bg-primary/15 text-primary';

    return 'bg-primary text-primary-foreground';
});
const attachment = computed(() => props.message.meta?.attachment ?? null);
const bodyIsAttachmentLabel = computed(() => Boolean(attachment.value) && /^(📷|🎤|📎)/.test(props.message.body));
</script>

<template>
    <div class="flex w-full items-end gap-2" :class="isCustomer ? 'flex-row' : 'flex-row-reverse'">
        <div class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-[11px] font-semibold" :class="[avatarClass, showHeader ? '' : 'invisible']">
            <Bot v-if="message.sender_type === 'ai'" class="h-3.5 w-3.5" />
            <User v-else-if="message.sender_type === 'operator'" class="h-3.5 w-3.5" />
            <span v-else>{{ initial }}</span>
        </div>
        <div class="flex min-w-0 max-w-[78%] flex-col gap-1" :class="isCustomer ? 'items-start' : 'items-end'">
            <span v-if="showHeader && ! isCustomer" class="mr-1 text-[10px] font-semibold uppercase ui-subtle">{{ senderLabel }}</span>
            <div class="min-w-0 rounded-2xl px-4 py-2.5 text-sm leading-6" :class="bubbleClass">
                <img v-if="attachment?.type === 'photo'" :src="attachment.url" class="mb-2 max-h-64 w-full rounded-lg object-cover" loading="lazy">
                <audio v-else-if="attachment?.type === 'voice'" :src="attachment.url" controls class="mb-2 h-10 max-w-full" />
                <a
                    v-else-if="attachment?.type === 'document'"
                    :href="attachment.url"
                    target="_blank"
                    rel="noopener"
                    class="mb-2 flex items-center gap-2 rounded-lg border px-3 py-2 text-xs border-border/60 hover:bg-black/5 dark:hover:bg-white/5"
                >
                    <FileText class="h-4 w-4 shrink-0" />
                    <span class="min-w-0 flex-1 truncate">{{ attachment.filename ?? 'Файл' }}</span>
                </a>
                <p v-if="!bodyIsAttachmentLabel" class="whitespace-pre-wrap break-words">{{ message.body }}</p>
                <p class="mt-1 text-right text-[10px] opacity-70">{{ timeLabel }}</p>
            </div>
        </div>
    </div>
</template>
