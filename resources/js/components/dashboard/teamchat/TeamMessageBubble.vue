<script setup lang="ts">
// Ported from ChatMessageItem.vue -- same reply/copy/edit/delete affordances,
// same attachment rendering (photo lightbox, document card, voice player),
// wired to teamChat.ts instead of chat.ts. No status/retry/discard (team
// messages send synchronously over our own API, not best-effort out to an
// external channel, so there's no "failed to deliver" state to recover from).
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { CopyIcon, FileTextIcon, MoreHorizontalIcon, PencilIcon, ReplyIcon, Trash2Icon, XIcon } from '@lucide/vue';
import { useTeamChatStore, type TeamMessage } from '@/stores/teamChat';
import { useCrmDashboardStore } from '@/stores/crmDashboard';
import { useLocaleStore } from '@/stores/locale';
import { useMessageScroller } from '@/components/ui/message-scroller';
import { Attachment, AttachmentContent, AttachmentDescription, AttachmentMedia, AttachmentTitle, AttachmentTrigger } from '@/components/ui/attachment';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import { Button } from '@/components/ui/button';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuSeparator, ContextMenuTrigger } from '@/components/ui/context-menu';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Message, MessageAvatar, MessageContent, MessageFooter, MessageHeader } from '@/components/ui/message';
import ImageLightbox from '../chat/ImageLightbox.vue';
import VoicePlayer from '../chat/VoicePlayer.vue';

const props = defineProps<{ message: TeamMessage; showHeader: boolean; showAvatar: boolean; otherName: string }>();

const dashboard = useCrmDashboardStore();
const team = useTeamChatStore();
const locale = useLocaleStore();
const { scrollToMessage } = useMessageScroller();

const isMine = computed(() => props.message.sender_id === dashboard.user?.id);
const align = computed<'start' | 'end'>(() => (isMine.value ? 'end' : 'start'));
const isDeleted = computed(() => Boolean(props.message.deleted_at));
const attachment = computed(() => props.message.meta?.attachment ?? null);

const bodyIsAttachmentPlaceholder = computed(() => {
    if (! attachment.value) return false;
    return /^(📷|🎤|📎)/.test(props.message.body);
});

const bubbleVariant = computed(() => (isDeleted.value ? 'ghost' : isMine.value ? 'default' : 'muted'));

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
    await team.editMessage(props.message.id, body);
}

async function copyBody(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.message.body);
        toast.success(locale.t('teamChat.copied'));
    } catch {
        toast.error(locale.t('teamChat.copyFailed'));
    }
}

function reply(): void {
    team.setReplyTarget(props.message);
}

async function remove(): Promise<void> {
    await team.deleteMessage(props.message.id);
}

function jumpToReplied(): void {
    if (props.message.reply_to) scrollToMessage(String(props.message.reply_to.id));
}

function initials(name: string): string {
    return name.slice(0, 2).toUpperCase();
}

function formatTime(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}
</script>

<template>
    <Message :align="align" class="group/item">
        <MessageAvatar v-if="showAvatar">
            <Avatar class="size-7">
                <AvatarFallback class="text-xs font-semibold bg-primary/10 text-primary">
                    {{ initials(isMine ? (dashboard.user?.name ?? '?') : otherName) }}
                </AvatarFallback>
            </Avatar>
        </MessageAvatar>
        <MessageAvatar v-else />

        <MessageContent>
            <MessageHeader v-if="showHeader && ! isMine">{{ otherName }}</MessageHeader>

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
                            <span class="block font-semibold">{{ message.reply_to.sender?.name ?? locale.t('teamChat.replyLabel') }}</span>
                            <span class="line-clamp-1">{{ message.reply_to.deleted_at ? locale.t('teamChat.messageDeleted') : message.reply_to.body }}</span>
                        </button>

                        <p v-if="isDeleted" class="italic ui-subtle">{{ locale.t('teamChat.messageDeleted') }}</p>

                        <form v-else-if="editing" class="flex items-center gap-2" @submit.prevent="saveEdit">
                            <Input v-model="editValue" autofocus class="h-8 text-sm" @keydown.esc="cancelEdit" />
                            <Button size="icon-xs" variant="ghost" type="submit" :aria-label="locale.t('teamChat.save')"><PencilIcon class="h-3.5 w-3.5" /></Button>
                            <Button size="icon-xs" variant="ghost" type="button" :aria-label="locale.t('teamChat.cancel')" @click="cancelEdit"><XIcon class="h-3.5 w-3.5" /></Button>
                        </form>

                        <template v-else>
                            <p v-if="message.body && ! bodyIsAttachmentPlaceholder" class="whitespace-pre-line">{{ message.body }}</p>

                            <ImageLightbox
                                v-if="attachment?.type === 'photo'"
                                :src="attachment.url"
                                :alt="attachment.filename ?? locale.t('teamChat.photo')"
                                class="mt-1 max-w-56 overflow-hidden rounded-lg"
                            >
                                <img :src="attachment.url" :alt="attachment.filename ?? locale.t('teamChat.photo')" class="max-h-72 w-full rounded-lg object-cover transition hover:brightness-95">
                            </ImageLightbox>

                            <Attachment v-else-if="attachment?.type === 'document'" size="sm" class="mt-1 bg-transparent">
                                <AttachmentMedia><FileTextIcon /></AttachmentMedia>
                                <AttachmentContent>
                                    <AttachmentTitle>{{ attachment.filename ?? locale.t('teamChat.file') }}</AttachmentTitle>
                                    <AttachmentDescription>{{ attachment.mime ?? locale.t('teamChat.file') }}</AttachmentDescription>
                                </AttachmentContent>
                                <AttachmentTrigger as-child>
                                    <a :href="attachment.url" target="_blank" rel="noreferrer" :aria-label="`${locale.t('teamChat.open')} ${attachment.filename ?? locale.t('teamChat.file')}`" />
                                </AttachmentTrigger>
                            </Attachment>

                            <div v-else-if="attachment?.type === 'voice'" class="mt-1 w-56 max-w-full rounded-lg p-1.5">
                                <VoicePlayer :src="attachment.url" />
                            </div>
                        </template>
                    </BubbleContent>
                </Bubble>
                </ContextMenuTrigger>
                <ContextMenuContent v-if="! isDeleted && ! editing" :align="align === 'end' ? 'end' : 'start'">
                    <ContextMenuItem @select="reply"><ReplyIcon class="h-4 w-4" />{{ locale.t('teamChat.reply') }}</ContextMenuItem>
                    <ContextMenuItem v-if="message.body" @select="copyBody"><CopyIcon class="h-4 w-4" />{{ locale.t('teamChat.copy') }}</ContextMenuItem>
                    <template v-if="isMine">
                        <ContextMenuSeparator />
                        <ContextMenuItem @select="startEdit"><PencilIcon class="h-4 w-4" />{{ locale.t('teamChat.edit') }}</ContextMenuItem>
                        <ContextMenuItem variant="destructive" @select="remove"><Trash2Icon class="h-4 w-4" />{{ locale.t('teamChat.delete') }}</ContextMenuItem>
                    </template>
                </ContextMenuContent>
                </ContextMenu>

                <DropdownMenu v-if="! isDeleted && ! editing">
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" size="icon-xs" class="opacity-0 transition group-hover/item:opacity-100" :aria-label="locale.t('teamChat.messageActions')">
                            <MoreHorizontalIcon class="h-3.5 w-3.5" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent :align="align === 'end' ? 'end' : 'start'">
                        <DropdownMenuItem @select="reply"><ReplyIcon class="h-4 w-4" />{{ locale.t('teamChat.reply') }}</DropdownMenuItem>
                        <DropdownMenuItem v-if="message.body" @select="copyBody"><CopyIcon class="h-4 w-4" />{{ locale.t('teamChat.copy') }}</DropdownMenuItem>
                        <template v-if="isMine">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem @select="startEdit"><PencilIcon class="h-4 w-4" />{{ locale.t('teamChat.edit') }}</DropdownMenuItem>
                            <DropdownMenuItem variant="destructive" @select="remove"><Trash2Icon class="h-4 w-4" />{{ locale.t('teamChat.delete') }}</DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <MessageFooter>
                {{ formatTime(message.created_at) }}
                <span v-if="message.edited_at"> · {{ locale.t('teamChat.edited') }}</span>
            </MessageFooter>
        </MessageContent>
    </Message>
</template>
