<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { BotIcon, CopyIcon, FileTextIcon, MoreHorizontalIcon, PencilIcon, ReplyIcon, RotateCcwIcon, Trash2Icon, XIcon } from '@lucide/vue';
import { useChatStore } from '@/stores/chat';
import { useMessageScroller } from '@/components/ui/message-scroller';
import type { ChatMessage } from '@/lib/chat/types';
import { Attachment, AttachmentContent, AttachmentDescription, AttachmentMedia, AttachmentTitle, AttachmentTrigger } from '@/components/ui/attachment';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import { Button } from '@/components/ui/button';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuSeparator, ContextMenuTrigger } from '@/components/ui/context-menu';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Message, MessageAvatar, MessageContent, MessageFooter, MessageHeader } from '@/components/ui/message';
import { Spinner } from '@/components/ui/spinner';
import ImageLightbox from './ImageLightbox.vue';
import VideoPlayer from './VideoPlayer.vue';
import VoicePlayer from './VoicePlayer.vue';

const props = defineProps<{ message: ChatMessage; showHeader: boolean; showAvatar: boolean }>();
const chat = useChatStore();
const { scrollToEnd, scrollToMessage } = useMessageScroller();

// A newly-mounted bubble with status 'sending' is always this operator's own
// just-sent optimistic message (see chat.ts sendMessage()) — always snap to the
// bottom for it, regardless of where the thread was scrolled to. The scroller's
// own auto-scroll only follows the bottom if the reader was already there; this
// is the "I just took an action, always show me what I sent" case on top of that.
onMounted(() => {
    if (props.message.sender_type === 'operator' && props.message.status === 'sending') {
        nextTick(() => scrollToEnd());
    }
});

// Only genuinely fresh messages (just sent/received) get the entrance
// animation — history loaded by scrolling up mounts these same components too,
// but with old sent_at timestamps, so this naturally excludes them.
const isFresh = Boolean(props.message.sent_at && Date.now() - new Date(props.message.sent_at).getTime() < 10000);

const isMine = computed(() => props.message.sender_type === 'operator');
const isAi = computed(() => props.message.sender_type === 'ai');
const align = computed<'start' | 'end'>(() => (isMine.value || isAi.value ? 'end' : 'start'));
const isDeleted = computed(() => Boolean(props.message.deleted_at));
const attachment = computed(() => props.message.meta?.attachment ?? null);
// Real tap buttons this message went out with on Telegram/WhatsApp (see
// ChatButtons::withAssistantOption()) -- rendered read-only here so an
// operator reading the transcript can see what the customer was actually
// offered/tapped, not just the shortened intro text (the enumerated list
// is deliberately NOT repeated in the message body once buttons exist —
// see ChatButtons::shortenForButtons()).
const buttons = computed(() => props.message.meta?.buttons ?? null);

const bodyIsAttachmentPlaceholder = computed(() => {
    if (! attachment.value) return false;
    return /^(📷|🎤|🎥|📎)/.test(props.message.body);
});

const bubbleVariant = computed(() => {
    if (isDeleted.value) return 'ghost';
    if (props.message.status === 'failed') return 'destructive';
    if (isAi.value) return 'tinted';
    if (isMine.value) return 'default';
    return 'muted';
});

const editing = ref(false);
const editValue = ref('');

function startEdit(): void {
    editValue.value = props.message.body;
    editing.value = true;
}

function cancelEdit(): void {
    editing.value = false;
}

async function saveEdit(): Promise<void> {
    const body = editValue.value.trim();
    if (! body) return;
    editing.value = false;
    await chat.editMessage(props.message.conversation_id, props.message.id, body);
}

async function copyBody(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.message.body);
        toast.success('Скопировано');
    } catch {
        toast.error('Не удалось скопировать');
    }
}

function reply(): void {
    chat.setReplyTarget(props.message);
}

async function remove(): Promise<void> {
    await chat.deleteMessage(props.message.conversation_id, props.message.id);
}

function jumpToReplied(): void {
    if (props.message.reply_to) scrollToMessage(String(props.message.reply_to.id));
}

function retry(): void {
    if (props.message.clientId) chat.retrySend(props.message.conversation_id, props.message.clientId);
}

function discard(): void {
    if (props.message.clientId) chat.discardFailed(props.message.conversation_id, props.message.clientId);
}

function formatTime(value: string | null): string {
    return value ? new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '';
}
</script>

<template>
    <Message :align="align" class="group/item" :class="{ 'animate-fade-up': isFresh }">
        <MessageAvatar v-if="showAvatar">
            <Avatar class="size-7">
                <AvatarImage v-if="! isAi && ! isMine && chat.activeConversation?.customer?.avatar_url" :src="chat.activeConversation.customer.avatar_url" alt="" />
                <AvatarFallback class="text-xs font-semibold" :class="isAi ? 'bg-accent text-accent-foreground' : 'bg-primary/10 text-primary'">
                    <BotIcon v-if="isAi" class="h-3.5 w-3.5" />
                    <template v-else>{{ (message.sender_name ?? '?')[0]?.toUpperCase() }}</template>
                </AvatarFallback>
            </Avatar>
        </MessageAvatar>
        <MessageAvatar v-else />

        <MessageContent>
            <MessageHeader v-if="showHeader && message.sender_name">{{ message.sender_name }}</MessageHeader>

            <div class="flex items-center gap-1.5" :class="align === 'end' ? 'flex-row-reverse' : ''">
                <ContextMenu>
                <ContextMenuTrigger as-child>
                <Bubble :variant="bubbleVariant" :align="align">
                    <BubbleContent>
                        <button
                            v-if="message.reply_to"
                            type="button"
                            class="mb-1.5 block w-full rounded-md border-l-2 px-2 py-1 text-left text-xs text-inherit opacity-80 transition hover:opacity-100 border-current/40 bg-black/5 dark:bg-black/30 dark:text-white dark:opacity-90 dark:hover:opacity-100"
                            @click="jumpToReplied"
                        >
                            <span class="block font-semibold">{{ message.reply_to.sender_name ?? 'Сообщение' }}</span>
                            <span class="line-clamp-1">{{ message.reply_to.deleted_at ? 'Сообщение удалено' : message.reply_to.body }}</span>
                        </button>

                        <p v-if="isDeleted" class="italic ui-subtle">Сообщение удалено</p>

                        <form v-else-if="editing" class="flex items-center gap-2" @submit.prevent="saveEdit">
                            <Input v-model="editValue" autofocus class="h-8 text-sm" @keydown.esc="cancelEdit" />
                            <Button size="icon-xs" variant="ghost" type="submit" aria-label="Сохранить"><PencilIcon class="h-3.5 w-3.5" /></Button>
                            <Button size="icon-xs" variant="ghost" type="button" aria-label="Отмена" @click="cancelEdit"><XIcon class="h-3.5 w-3.5" /></Button>
                        </form>

                        <template v-else>
                            <p v-if="message.body && ! bodyIsAttachmentPlaceholder" class="whitespace-pre-line">{{ message.body }}</p>

                            <div v-if="buttons?.length" class="mt-1.5 flex flex-col gap-1">
                                <span
                                    v-for="btn in buttons"
                                    :key="btn.id"
                                    class="inline-flex items-center gap-1 rounded-md border px-2 py-1 text-left text-[11px] font-medium leading-tight border-current/25 bg-black/5 dark:bg-black/25"
                                >
                                    <span v-if="btn.id !== 'assistant'" class="shrink-0 font-semibold">{{ btn.id }}.</span>
                                    <span>{{ btn.title }}<template v-if="btn.description"> — {{ btn.description }}</template></span>
                                </span>
                            </div>

                            <ImageLightbox
                                v-if="attachment?.type === 'photo'"
                                :src="attachment.url"
                                :alt="attachment.filename ?? 'Фото'"
                                class="mt-1 max-w-56 overflow-hidden rounded-lg"
                            >
                                <img :src="attachment.url" :alt="attachment.filename ?? 'Фото'" class="max-h-72 w-full rounded-lg object-cover transition hover:brightness-95">
                            </ImageLightbox>

                            <Attachment v-else-if="attachment?.type === 'document'" size="sm" class="mt-1 bg-transparent">
                                <AttachmentMedia><FileTextIcon /></AttachmentMedia>
                                <AttachmentContent>
                                    <AttachmentTitle>{{ attachment.filename ?? 'Файл' }}</AttachmentTitle>
                                    <AttachmentDescription>{{ attachment.mime ?? 'Документ' }}</AttachmentDescription>
                                </AttachmentContent>
                                <AttachmentTrigger as-child>
                                    <a :href="attachment.url" target="_blank" rel="noreferrer" :aria-label="`Открыть ${attachment.filename ?? 'файл'}`" />
                                </AttachmentTrigger>
                            </Attachment>

                            <div v-else-if="attachment?.type === 'voice'" class="mt-1 w-56 max-w-full rounded-lg p-1.5">
                                <VoicePlayer :src="attachment.url" />
                            </div>

                            <VideoPlayer v-else-if="attachment?.type === 'video'" :src="attachment.url" />
                        </template>
                    </BubbleContent>
                </Bubble>
                </ContextMenuTrigger>
                <ContextMenuContent v-if="! isDeleted && message.status !== 'sending' && message.status !== 'failed' && ! editing" :align="align === 'end' ? 'end' : 'start'">
                    <ContextMenuItem @select="reply"><ReplyIcon class="h-4 w-4" />Ответить</ContextMenuItem>
                    <ContextMenuItem v-if="message.body" @select="copyBody"><CopyIcon class="h-4 w-4" />Копировать</ContextMenuItem>
                    <template v-if="isMine">
                        <ContextMenuSeparator />
                        <ContextMenuItem @select="startEdit"><PencilIcon class="h-4 w-4" />Редактировать</ContextMenuItem>
                        <ContextMenuItem variant="destructive" @select="remove"><Trash2Icon class="h-4 w-4" />Удалить</ContextMenuItem>
                    </template>
                </ContextMenuContent>
                </ContextMenu>

                <DropdownMenu v-if="! isDeleted && message.status !== 'sending' && message.status !== 'failed' && ! editing">
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" size="icon-xs" class="opacity-0 transition group-hover/item:opacity-100" aria-label="Действия с сообщением">
                            <MoreHorizontalIcon class="h-3.5 w-3.5" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent :align="align === 'end' ? 'end' : 'start'">
                        <DropdownMenuItem @select="reply"><ReplyIcon class="h-4 w-4" />Ответить</DropdownMenuItem>
                        <DropdownMenuItem v-if="message.body" @select="copyBody"><CopyIcon class="h-4 w-4" />Копировать</DropdownMenuItem>
                        <template v-if="isMine">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem @select="startEdit"><PencilIcon class="h-4 w-4" />Редактировать</DropdownMenuItem>
                            <DropdownMenuItem variant="destructive" @select="remove"><Trash2Icon class="h-4 w-4" />Удалить</DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <MessageFooter>
                <span v-if="message.status === 'sending'" class="flex items-center gap-1"><Spinner class="size-3" />Отправка…</span>
                <span v-else-if="message.status === 'failed'" class="flex items-center gap-1.5 text-destructive">
                    Не отправлено
                    <Button size="icon-xs" variant="ghost" title="Повторить" aria-label="Повторить" @click="retry"><RotateCcwIcon class="h-3 w-3" /></Button>
                    <Button size="icon-xs" variant="ghost" title="Удалить" aria-label="Удалить" @click="discard"><XIcon class="h-3 w-3" /></Button>
                </span>
                <span v-else>
                    {{ formatTime(message.sent_at) }}
                    <span v-if="message.edited_at"> · изменено</span>
                </span>
            </MessageFooter>
        </MessageContent>
    </Message>
</template>
