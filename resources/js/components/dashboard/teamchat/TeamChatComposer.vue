<script setup lang="ts">
// Ported from ChatComposer.vue -- same attach/drag-drop/paste/voice-record/
// emoji behavior, wired to teamChat.ts instead of chat.ts. No typing
// indicator (that needs a broadcast channel this feature doesn't have and
// wasn't asked for) and no conversationId prop (team.activeUserId already
// says who this is for).
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { MicIcon, PaperclipIcon, SendIcon, SquareIcon, XIcon } from '@lucide/vue';
import { useTeamChatStore, type TeamMessageAttachment } from '@/stores/teamChat';
import { useLocaleStore } from '@/stores/locale';
import { Attachment, AttachmentAction, AttachmentActions, AttachmentContent, AttachmentDescription, AttachmentMedia, AttachmentTitle } from '@/components/ui/attachment';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import EmojiPicker from '../chat/EmojiPicker.vue';

const team = useTeamChatStore();
const locale = useLocaleStore();

const body = ref('');
const fileInput = ref<HTMLInputElement | null>(null);
const dragActive = ref(false);
const dragCounter = ref(0);

const pendingAttachment = ref<TeamMessageAttachment | null>(null);
const uploading = ref(false);

const recording = ref(false);
const recordSeconds = ref(0);
let mediaRecorder: MediaRecorder | null = null;
let recordedChunks: Blob[] = [];
let recordTimer: ReturnType<typeof setInterval> | null = null;

const replyTarget = computed(() => team.replyTarget);
const canSend = computed(() => ! team.sending && ! uploading.value && (body.value.trim() !== '' || pendingAttachment.value !== null));

async function submit(): Promise<void> {
    if (! canSend.value) return;

    const text = body.value.trim();
    const attachment = pendingAttachment.value;
    body.value = '';
    pendingAttachment.value = null;
    await team.send(text, attachment);
}

function onEnterKey(event: KeyboardEvent): void {
    if (event.shiftKey || event.isComposing) return;
    event.preventDefault();
    submit();
}

function onEscape(): void {
    if (recording.value) {
        cancelRecording();
    } else if (pendingAttachment.value) {
        clearAttachment();
    } else if (replyTarget.value) {
        team.cancelReply();
    }
}

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

function onInput(event: Event): void {
    const el = event.target as HTMLTextAreaElement;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 160) + 'px';
}

function insertEmoji(emoji: string): void {
    body.value += emoji;
}

function pickFiles(): void {
    fileInput.value?.click();
}

async function onFilesChosen(event: Event): Promise<void> {
    const files = Array.from((event.target as HTMLInputElement).files ?? []);
    (event.target as HTMLInputElement).value = '';
    await handleFiles(files);
}

async function handleFiles(files: File[]): Promise<void> {
    if (! files.length) return;

    if (files.length === 1) {
        await stageAttachment(files[0]);
        return;
    }

    for (const file of files) {
        const attachment = await uploadFile(file);
        if (attachment) await team.send('', attachment);
    }
}

async function stageAttachment(file: File): Promise<void> {
    const attachment = await uploadFile(file);
    if (attachment) pendingAttachment.value = attachment;
}

async function uploadFile(file: File): Promise<TeamMessageAttachment | null> {
    const type = file.type.startsWith('image/') ? 'photo' : 'document';
    uploading.value = true;
    try {
        return await team.uploadAttachment(file, type);
    } finally {
        uploading.value = false;
    }
}

function clearAttachment(): void {
    pendingAttachment.value = null;
}

function attachmentLabel(attachment: TeamMessageAttachment): string {
    if (attachment.filename) return attachment.filename;
    return attachment.type === 'voice' ? locale.t('teamChat.voiceMessage') : attachment.type === 'photo' ? locale.t('teamChat.photo') : locale.t('teamChat.file');
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
            const attachment = await team.uploadAttachment(file, 'voice');
            if (attachment) await team.send('', attachment);
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

watch(() => team.activeUserId, () => {
    body.value = '';
    pendingAttachment.value = null;
});
</script>

<template>
    <form
        class="relative shrink-0 border-t p-3 border-border bg-card"
        @submit.prevent="submit"
        @dragenter.prevent="onDragEnter"
        @dragover.prevent
        @dragleave.prevent="onDragLeave"
        @drop.prevent="onDrop"
    >
        <div v-if="dragActive" class="absolute inset-0 z-10 grid place-items-center rounded-lg border-2 border-dashed m-2 border-primary bg-primary/5 text-sm font-medium text-primary">
            {{ locale.t('teamChat.dropFile') }}
        </div>

        <div v-if="replyTarget" class="mb-2 flex items-center gap-2 rounded-lg border-l-2 border-primary bg-muted px-3 py-1.5 text-xs">
            <div class="min-w-0 flex-1">
                <span class="block font-semibold ui-text">{{ replyTarget.sender?.name ?? locale.t('teamChat.replyLabel') }}</span>
                <span class="line-clamp-1 ui-subtle">{{ replyTarget.deleted_at ? locale.t('teamChat.messageDeleted') : replyTarget.body }}</span>
            </div>
            <button type="button" class="shrink-0 ui-subtle hover:text-destructive" @click="team.cancelReply"><XIcon class="h-4 w-4" /></button>
        </div>

        <Attachment v-if="pendingAttachment" :state="uploading ? 'uploading' : 'done'" size="sm" class="mb-2 w-full">
            <AttachmentMedia :variant="pendingAttachment.type === 'photo' ? 'image' : 'icon'">
                <img v-if="pendingAttachment.type === 'photo'" :src="pendingAttachment.url" alt="">
                <MicIcon v-else-if="pendingAttachment.type === 'voice'" />
                <PaperclipIcon v-else />
            </AttachmentMedia>
            <AttachmentContent>
                <AttachmentTitle>{{ attachmentLabel(pendingAttachment) }}</AttachmentTitle>
                <AttachmentDescription>{{ uploading ? locale.t('teamChat.uploading') : locale.t('teamChat.readyToSend') }}</AttachmentDescription>
            </AttachmentContent>
            <AttachmentActions>
                <AttachmentAction :aria-label="locale.t('teamChat.removeAttachment')" @click="clearAttachment"><XIcon /></AttachmentAction>
            </AttachmentActions>
        </Attachment>

        <div v-if="recording" class="mb-2 flex items-center gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive">
            <span class="h-2 w-2 animate-pulse rounded-full bg-destructive" />
            <span class="flex-1">{{ locale.t('teamChat.recording') }} {{ recordSeconds }}с</span>
            <button type="button" class="shrink-0 font-medium underline hover:no-underline" @click="cancelRecording">{{ locale.t('teamChat.cancel') }}</button>
        </div>

        <div class="flex items-end gap-1 rounded-full border p-1 transition focus-within:border-primary border-border">
            <input ref="fileInput" type="file" multiple class="hidden" @change="onFilesChosen">
            <Button type="button" variant="ghost" size="icon" class="mb-1 shrink-0 rounded-full hover:bg-primary/10 hover:text-primary" :disabled="team.sending || uploading || recording" :title="locale.t('teamChat.attachFile')" @click="pickFiles">
                <PaperclipIcon class="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="mb-1 shrink-0 select-none touch-none rounded-full hover:bg-primary/10 hover:text-primary"
                :class="recording ? 'text-destructive animate-record-pulse' : ''"
                :disabled="team.sending || uploading"
                :title="recording ? locale.t('teamChat.releaseToSend') : locale.t('teamChat.holdToRecord')"
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
                :placeholder="locale.t('teamChat.placeholder')"
                maxlength="4000"
                rows="1"
                @keydown.enter="onEnterKey"
                @keydown.esc="onEscape"
                @input="onInput"
                @paste="onPaste"
            />

            <Button class="mb-1 shrink-0 rounded-full" variant="primary" size="icon" type="submit" :disabled="! canSend" :title="locale.t('teamChat.send')">
                <SendIcon class="h-4 w-4" />
            </Button>
        </div>
    </form>
</template>
