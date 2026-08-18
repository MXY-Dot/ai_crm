<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { MicIcon, SquareIcon } from '@lucide/vue';
import { useChatStore } from '@/stores/chat';
import { Button } from '@/components/ui/button';

/**
 * @advanced-chat/components' composer has no recording capability (only
 * file-picker/`capture`) and no slot to add one to — this sits as an overlay
 * outside <AdvancedChat> and calls the store directly, bypassing the
 * library's own send button entirely. Same press-and-hold MediaRecorder
 * logic as the old ChatComposer.vue (see its startRecording()/stopRecording()).
 */
const props = defineProps<{ conversationId: number }>();
const chat = useChatStore();

const recording = ref(false);
const recordSeconds = ref(0);
const uploading = ref(false);
let mediaRecorder: MediaRecorder | null = null;
let recordedChunks: Blob[] = [];
let recordTimer: ReturnType<typeof setInterval> | null = null;
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

function onEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape' && recording.value) cancelRecording();
}

window.addEventListener('keydown', onEscape);

onBeforeUnmount(() => {
    window.removeEventListener('mouseup', endRecordPress);
    window.removeEventListener('touchend', endRecordPress);
    window.removeEventListener('touchcancel', endRecordPress);
    window.removeEventListener('keydown', onEscape);
    if (recording.value) cancelRecording();
});
</script>

<template>
    <div class="pointer-events-none absolute bottom-3 right-3 z-10 flex items-center gap-2">
        <div v-if="recording" class="pointer-events-auto flex items-center gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-1.5 text-xs text-destructive">
            <span class="h-2 w-2 animate-pulse rounded-full bg-destructive" />
            <span>Запись… {{ recordSeconds }}с</span>
            <button type="button" class="font-medium underline hover:no-underline" @click="cancelRecording">Отмена</button>
        </div>
        <Button
            type="button"
            variant="default"
            size="icon"
            class="pointer-events-auto rounded-full shadow-md select-none touch-none"
            :class="{ 'bg-destructive hover:bg-destructive/90': recording }"
            :disabled="uploading"
            :title="recording ? 'Отпустите, чтобы отправить (Esc — отмена)' : 'Удерживайте, чтобы записать голосовое'"
            @mousedown="beginRecordPress"
            @touchstart="beginRecordPress"
            @contextmenu.prevent
        >
            <SquareIcon v-if="recording" class="h-4 w-4" />
            <MicIcon v-else class="h-4 w-4" />
        </Button>
    </div>
</template>
