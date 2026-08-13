<script setup lang="ts">
import { ref } from 'vue';
import { FileText, Mic, Paperclip, Send, Square, X } from '@lucide/vue';
import { useLocaleStore } from '../../../stores/locale';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import type { MessageAttachment } from '../../../stores/crmDashboard';
import { Button } from '../../ui/button';
import { Textarea } from '../../ui/textarea';

const props = defineProps<{ body: string; busy: boolean; canReply: boolean; conversationId?: number | null; allowAttachments?: boolean }>();
const emit = defineEmits<{ 'update:body': [value: string]; send: [attachment?: MessageAttachment | null] }>();
const locale = useLocaleStore();
const store = useCrmDashboardStore();

const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const pendingAttachment = ref<MessageAttachment | null>(null);
const recording = ref(false);
const recordSeconds = ref(0);
let mediaRecorder: MediaRecorder | null = null;
let recordedChunks: Blob[] = [];
let recordTimer: ReturnType<typeof setInterval> | null = null;

function submit(): void {
    if (props.busy || (! props.body.trim() && ! pendingAttachment.value)) return;
    emit('send', pendingAttachment.value);
    pendingAttachment.value = null;
}

function onEnterKey(event: KeyboardEvent): void {
    if (event.shiftKey) return;
    event.preventDefault();
    submit();
}

function autoResize(event: Event): void {
    const el = event.target as HTMLTextAreaElement;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 128) + 'px';
}

function pickFile(): void {
    fileInput.value?.click();
}

async function onFileChosen(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];
    (event.target as HTMLInputElement).value = '';
    if (! file || ! props.conversationId) return;

    const type = file.type.startsWith('image/') ? 'photo' : 'document';
    uploading.value = true;
    try {
        pendingAttachment.value = await store.uploadConversationAttachment(props.conversationId, file, type);
    } finally {
        uploading.value = false;
    }
}

function clearAttachment(): void {
    pendingAttachment.value = null;
}

async function toggleRecording(): Promise<void> {
    if (recording.value) {
        mediaRecorder?.stop();
        return;
    }

    if (! props.conversationId) return;

    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    recordedChunks = [];
    mediaRecorder = new MediaRecorder(stream);
    mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) recordedChunks.push(event.data);
    };
    mediaRecorder.onstop = async () => {
        stream.getTracks().forEach((track) => track.stop());
        recording.value = false;
        if (recordTimer) { clearInterval(recordTimer); recordTimer = null; }

        const blob = new Blob(recordedChunks, { type: mediaRecorder?.mimeType || 'audio/webm' });
        if (blob.size === 0 || ! props.conversationId) return;

        const file = new File([blob], 'voice-message.webm', { type: blob.type });
        uploading.value = true;
        try {
            pendingAttachment.value = await store.uploadConversationAttachment(props.conversationId, file, 'voice');
        } finally {
            uploading.value = false;
        }
    };

    mediaRecorder.start();
    recording.value = true;
    recordSeconds.value = 0;
    recordTimer = setInterval(() => { recordSeconds.value += 1; }, 1000);
}
</script>

<template>
    <form v-if="canReply" class="shrink-0 border-t p-3 border-border bg-card" @submit.prevent="submit">
        <div v-if="pendingAttachment" class="mb-2 flex items-center gap-2 rounded-lg border px-3 py-2 text-xs border-border bg-muted">
            <FileText class="h-4 w-4 shrink-0 ui-subtle" />
            <span class="min-w-0 flex-1 truncate ui-text">{{ pendingAttachment.filename ?? (pendingAttachment.type === 'voice' ? 'Голосовое сообщение' : pendingAttachment.type === 'photo' ? 'Фото' : 'Файл') }}</span>
            <button type="button" class="shrink-0 text-destructive" @click="clearAttachment"><X class="h-4 w-4" /></button>
        </div>
        <div v-if="recording" class="mb-2 flex items-center gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive">
            <span class="h-2 w-2 animate-pulse rounded-full bg-destructive" />
            Запись голосового... {{ recordSeconds }}с
        </div>
        <div class="flex items-end gap-2 rounded-xl border p-1 transition focus-within:border-primary border-border">
            <template v-if="allowAttachments">
                <input ref="fileInput" type="file" class="hidden" @change="onFileChosen">
                <Button type="button" variant="ghost" size="icon" class="mb-1 h-9 w-9 shrink-0" :disabled="busy || uploading || recording" title="Прикрепить файл" @click="pickFile">
                    <Paperclip class="h-4 w-4" />
                </Button>
                <Button type="button" variant="ghost" size="icon" class="mb-1 h-9 w-9 shrink-0" :class="{ 'text-destructive': recording }" :disabled="busy || uploading" :title="recording ? 'Остановить запись' : 'Записать голосовое'" @click="toggleRecording">
                    <Square v-if="recording" class="h-4 w-4" />
                    <Mic v-else class="h-4 w-4" />
                </Button>
            </template>
            <Textarea
                :model-value="body"
                class="max-h-32 min-h-11 flex-1 resize-none border-none bg-transparent shadow-none focus-visible:ring-0"
                :placeholder="locale.t('inbox.replyPlaceholder')"
                maxlength="4000"
                rows="1"
                @update:model-value="$emit('update:body', String($event))"
                @keydown.enter="onEnterKey"
                @input="autoResize"
            />
            <Button
                class="mb-1 h-9 w-9 shrink-0"
                variant="primary"
                size="icon"
                type="submit"
                :disabled="busy || uploading || (!body.trim() && !pendingAttachment)"
                :title="locale.t('inbox.sendReply')"
            >
                <Send class="h-4 w-4" />
            </Button>
        </div>
    </form>

    <div v-else class="border-t p-4 text-sm leading-6 ui-subtle border-border bg-card">
        {{ locale.t('inbox.unlinkedConversation') }}
    </div>
</template>
