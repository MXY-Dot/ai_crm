<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { MicIcon, PaperclipIcon, SendIcon, SquareIcon, XIcon } from '@lucide/vue';
import { useChatStore } from '@/stores/chat';
import type { PendingAttachment } from '@/lib/chat/types';
import { Attachment, AttachmentAction, AttachmentActions, AttachmentContent, AttachmentDescription, AttachmentMedia, AttachmentTitle } from '@/components/ui/attachment';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import EmojiPicker from './EmojiPicker.vue';

const props = defineProps<{ conversationId: number; canReply: boolean }>();
const chat = useChatStore();

const body = ref('');
const fileInput = ref<HTMLInputElement | null>(null);
const dragActive = ref(false);
const dragCounter = ref(0);

const pendingAttachment = ref<PendingAttachment | null>(null);
const uploading = ref(false);
const sending = ref(false);

const recording = ref(false);
const recordSeconds = ref(0);
let mediaRecorder: MediaRecorder | null = null;
let recordedChunks: Blob[] = [];
let recordTimer: ReturnType<typeof setInterval> | null = null;

const replyTarget = computed(() => chat.replyTarget);
const canSend = computed(() => ! sending.value && ! uploading.value && (body.value.trim() !== '' || pendingAttachment.value !== null));

async function submit(): Promise<void> {
    if (! canSend.value) return;

    const text = body.value.trim();
    const attachment = pendingAttachment.value;
    body.value = '';
    pendingAttachment.value = null;
    sending.value = true;
    try {
        await chat.sendMessage(props.conversationId, text, attachment);
    } finally {
        sending.value = false;
    }
}

function onEnterKey(event: KeyboardEvent): void {
    if (event.shiftKey || event.isComposing) return;
    event.preventDefault();
    submit();
}

/** Escape backs out of whatever's in progress, most disruptive first: an in-flight recording, then a staged attachment, then a reply-to. */
function onEscape(): void {
    if (recording.value) {
        cancelRecording();
    } else if (pendingAttachment.value) {
        clearAttachment();
    } else if (replyTarget.value) {
        cancelReply();
    }
}

/** Pasting a screenshot/image directly into the composer stages it exactly like a drag-and-drop or file-picker attachment. */
async function onPaste(event: ClipboardEvent): Promise<void> {
    const items = event.clipboardData?.items;
    if (! items) return;

    const imageItem = Array.from(items).find((item) => item.type.startsWith('image/'));
    if (! imageItem) return;

    const file = imageItem.getAsFile();
    if (! file) return;

    event.preventDefault();
    await stageAttachment(file);
}

/**
 * Reads the DOM node straight off the native `input` event instead of a
 * template ref — `<script setup>` components are closed by default, so a ref on
 * the wrapped shadcn `Textarea` can't reach its inner `<textarea>` element.
 * `field-sizing: content` in Textarea's own base styles already auto-grows it
 * in current Chromium/Edge; this is the JS fallback for browsers without that
 * CSS property yet (same approach already used in the Inbox reply composer).
 */
function onInput(event: Event): void {
    const el = event.target as HTMLTextAreaElement;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 160) + 'px';
    chat.sendTyping(props.conversationId);
}

function insertEmoji(emoji: string): void {
    body.value += emoji;
}

function cancelReply(): void {
    chat.setReplyTarget(null);
}

function pickFiles(): void {
    fileInput.value?.click();
}

async function onFilesChosen(event: Event): Promise<void> {
    const files = Array.from((event.target as HTMLInputElement).files ?? []);
    (event.target as HTMLInputElement).value = '';
    await handleFiles(files);
}

/**
 * A single dropped/picked file is staged in the composer so the operator can add
 * a caption before sending (matches the backend's one-attachment-per-message
 * shape). Multiple files at once are sent immediately, one message each, since
 * there's no natural single caption for a batch.
 */
async function handleFiles(files: File[]): Promise<void> {
    if (! files.length) return;

    if (files.length === 1) {
        await stageAttachment(files[0]);
        return;
    }

    for (const file of files) {
        const attachment = await uploadFile(file);
        if (attachment) await chat.sendMessage(props.conversationId, '', attachment);
    }
}

async function stageAttachment(file: File): Promise<void> {
    const attachment = await uploadFile(file);
    if (attachment) pendingAttachment.value = attachment;
}

async function uploadFile(file: File): Promise<PendingAttachment | null> {
    const type = file.type.startsWith('image/') ? 'photo' : 'document';
    uploading.value = true;
    try {
        return await chat.uploadAttachment(props.conversationId, file, type);
    } finally {
        uploading.value = false;
    }
}

function clearAttachment(): void {
    pendingAttachment.value = null;
}

function attachmentLabel(attachment: PendingAttachment): string {
    if (attachment.filename) return attachment.filename;
    return attachment.type === 'voice' ? 'Голосовое сообщение' : attachment.type === 'photo' ? 'Фото' : 'Файл';
}

function onDragEnter(event: DragEvent): void {
    if (! event.dataTransfer?.types.includes('Files')) return;
    dragCounter.value += 1;
    dragActive.value = true;
}

function onDragLeave(): void {
    dragCounter.value = Math.max(0, dragCounter.value - 1);
    if (dragCounter.value === 0) dragActive.value = false;
}

async function onDrop(event: DragEvent): Promise<void> {
    dragActive.value = false;
    dragCounter.value = 0;
    const files = Array.from(event.dataTransfer?.files ?? []);
    await handleFiles(files);
}

let recordingCancelled = false;
const MIN_RECORDING_SECONDS = 1;

/**
 * Press-and-hold, matching Telegram: mousedown/touchstart starts recording,
 * releasing sends immediately (no separate "attach then click send" step) —
 * see beginRecordPress()/endRecordPress() below for the press handlers, and
 * cancelRecording() for the escape hatch (Escape key or the ✕ shown while
 * recording), which discards instead of sending.
 */
async function startRecording(): Promise<void> {
    if (recording.value) return;

    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    recordedChunks = [];
    recordingCancelled = false;
    mediaRecorder = new MediaRecorder(stream);
    mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) recordedChunks.push(event.data);
    };
    mediaRecorder.onstop = async () => {
        stream.getTracks().forEach((track) => track.stop());
        recording.value = false;
        if (recordTimer) { clearInterval(recordTimer); recordTimer = null; }

        if (recordingCancelled || recordSeconds.value < MIN_RECORDING_SECONDS) {
            recordedChunks = [];
            return;
        }

        const blob = new Blob(recordedChunks, { type: mediaRecorder?.mimeType || 'audio/webm' });
        if (blob.size === 0) return;

        const file = new File([blob], 'voice-message.webm', { type: blob.type });
        uploading.value = true;
        try {
            const attachment = await chat.uploadAttachment(props.conversationId, file, 'voice');
            if (attachment) await chat.sendMessage(props.conversationId, '', attachment);
        } finally {
            uploading.value = false;
        }
    };

    mediaRecorder.start();
    recording.value = true;
    recordSeconds.value = 0;
    recordTimer = setInterval(() => { recordSeconds.value += 1; }, 1000);
}

function stopRecording(cancel: boolean): void {
    if (! recording.value || ! mediaRecorder) return;
    recordingCancelled = cancel;
    mediaRecorder.stop();
}

function cancelRecording(): void {
    stopRecording(true);
}

function beginRecordPress(event: MouseEvent | TouchEvent): void {
    event.preventDefault();
    startRecording();
    window.addEventListener('mouseup', endRecordPress);
    window.addEventListener('touchend', endRecordPress);
    window.addEventListener('touchcancel', endRecordPress);
}

function endRecordPress(): void {
    window.removeEventListener('mouseup', endRecordPress);
    window.removeEventListener('touchend', endRecordPress);
    window.removeEventListener('touchcancel', endRecordPress);
    stopRecording(false);
}

onBeforeUnmount(() => {
    window.removeEventListener('mouseup', endRecordPress);
    window.removeEventListener('touchend', endRecordPress);
    window.removeEventListener('touchcancel', endRecordPress);
});

watch(() => props.conversationId, () => {
    body.value = '';
    pendingAttachment.value = null;
});

/** Lets a parent (e.g. "use this AI draft as my reply") fill the composer without lifting `body` state out of this component. */
defineExpose({
    insertText(text: string): void {
        body.value = text;
    },
});
</script>

<template>
    <form
        v-if="canReply"
        class="relative shrink-0 border-t p-3 border-border bg-card"
        @submit.prevent="submit"
        @dragenter.prevent="onDragEnter"
        @dragover.prevent
        @dragleave.prevent="onDragLeave"
        @drop.prevent="onDrop"
    >
        <div v-if="dragActive" class="absolute inset-0 z-10 grid place-items-center rounded-lg border-2 border-dashed m-2 border-primary bg-primary/5 text-sm font-medium text-primary">
            Отпустите файл, чтобы прикрепить
        </div>

        <div v-if="replyTarget" class="mb-2 flex items-center gap-2 rounded-lg border-l-2 border-primary bg-muted px-3 py-1.5 text-xs">
            <div class="min-w-0 flex-1">
                <span class="block font-semibold ui-text">{{ replyTarget.sender_name ?? 'Ответ на сообщение' }}</span>
                <span class="line-clamp-1 ui-subtle">{{ replyTarget.body }}</span>
            </div>
            <button type="button" class="shrink-0 ui-subtle hover:text-destructive" @click="cancelReply"><XIcon class="h-4 w-4" /></button>
        </div>

        <Attachment v-if="pendingAttachment" :state="uploading ? 'uploading' : 'done'" size="sm" class="mb-2 w-full">
            <AttachmentMedia :variant="pendingAttachment.type === 'photo' ? 'image' : 'icon'">
                <img v-if="pendingAttachment.type === 'photo'" :src="pendingAttachment.url" alt="">
                <MicIcon v-else-if="pendingAttachment.type === 'voice'" />
                <PaperclipIcon v-else />
            </AttachmentMedia>
            <AttachmentContent>
                <AttachmentTitle>{{ attachmentLabel(pendingAttachment) }}</AttachmentTitle>
                <AttachmentDescription>{{ uploading ? 'Загрузка…' : 'Готово к отправке' }}</AttachmentDescription>
            </AttachmentContent>
            <AttachmentActions>
                <AttachmentAction aria-label="Убрать вложение" @click="clearAttachment"><XIcon /></AttachmentAction>
            </AttachmentActions>
        </Attachment>

        <div v-if="recording" class="mb-2 flex items-center gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive">
            <span class="h-2 w-2 animate-pulse rounded-full bg-destructive" />
            <span class="flex-1">Запись голосового… {{ recordSeconds }}с — отпустите, чтобы отправить</span>
            <button type="button" class="shrink-0 font-medium underline hover:no-underline" @click="cancelRecording">Отмена</button>
        </div>

        <div class="flex items-end gap-1 rounded-xl border p-1 transition focus-within:border-primary border-border">
            <input ref="fileInput" type="file" multiple class="hidden" @change="onFilesChosen">
            <Button type="button" variant="ghost" size="icon" class="mb-1 shrink-0" :disabled="sending || uploading || recording" title="Прикрепить файл" @click="pickFiles">
                <PaperclipIcon class="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="mb-1 shrink-0 select-none touch-none"
                :class="{ 'text-destructive': recording }"
                :disabled="sending || uploading"
                :title="recording ? 'Отпустите, чтобы отправить (Esc — отмена)' : 'Удерживайте, чтобы записать голосовое'"
                @mousedown="beginRecordPress"
                @touchstart="beginRecordPress"
                @contextmenu.prevent
            >
                <SquareIcon v-if="recording" class="h-4 w-4" />
                <MicIcon v-else class="h-4 w-4" />
            </Button>
            <EmojiPicker @pick="insertEmoji" />

            <Textarea
                v-model="body"
                class="max-h-40 min-h-9 flex-1 resize-none border-none bg-transparent py-2 shadow-none focus-visible:ring-0"
                placeholder="Напишите сообщение..."
                maxlength="4000"
                rows="1"
                @keydown.enter="onEnterKey"
                @keydown.esc="onEscape"
                @input="onInput"
                @paste="onPaste"
            />

            <Button class="mb-1 shrink-0" variant="primary" size="icon" type="submit" :disabled="! canSend" title="Отправить (Enter)">
                <SendIcon class="h-4 w-4" />
            </Button>
        </div>
    </form>

    <div v-else class="border-t p-4 text-sm leading-6 ui-subtle border-border bg-card">
        Диалог не привязан к внешнему каналу — отправка недоступна.
    </div>
</template>
