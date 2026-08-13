<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { PauseIcon, PlayIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{ src: string }>();

const BAR_COUNT = 42;

const audio = new Audio();
audio.preload = 'metadata';
audio.src = props.src;

const playing = ref(false);
const duration = ref(0);
const currentTime = ref(0);
const bars = ref<number[]>(Array.from({ length: BAR_COUNT }, () => 0.25));
const waveReady = ref(false);

/** Real amplitude data decoded from the actual audio file via the Web Audio API — not a fake/random waveform. */
async function loadWaveform(): Promise<void> {
    try {
        const response = await fetch(props.src);
        const arrayBuffer = await response.arrayBuffer();
        const AudioCtx = window.AudioContext ?? (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
        const audioCtx = new AudioCtx();
        const audioBuffer = await audioCtx.decodeAudioData(arrayBuffer);
        const raw = audioBuffer.getChannelData(0);
        const blockSize = Math.max(1, Math.floor(raw.length / BAR_COUNT));
        const peaks: number[] = [];

        for (let i = 0; i < BAR_COUNT; i++) {
            const start = i * blockSize;
            let sum = 0;
            for (let j = 0; j < blockSize; j++) sum += Math.abs(raw[start + j] ?? 0);
            peaks.push(sum / blockSize);
        }

        const max = Math.max(...peaks, 0.0001);
        bars.value = peaks.map((p) => Math.min(1, Math.max(0.12, p / max)));
        audioCtx.close();
    } catch {
        // Decoding can fail (unsupported codec, CORS) — fall back to a flat, honestly-non-representative bar row rather than blocking playback.
        bars.value = Array.from({ length: BAR_COUNT }, () => 0.3);
    } finally {
        waveReady.value = true;
    }
}

function onLoadedMetadata(): void {
    duration.value = Number.isFinite(audio.duration) ? audio.duration : 0;
}
function onTimeUpdate(): void {
    currentTime.value = audio.currentTime;
}
function onEnded(): void {
    playing.value = false;
    currentTime.value = 0;
}

function toggle(): void {
    if (playing.value) {
        audio.pause();
        playing.value = false;
        return;
    }

    audio.play();
    playing.value = true;
}

function seekTo(index: number): void {
    if (! duration.value) return;
    audio.currentTime = (index / BAR_COUNT) * duration.value;
    currentTime.value = audio.currentTime;
}

const progress = computed(() => (duration.value ? currentTime.value / duration.value : 0));
const filledBars = computed(() => Math.floor(progress.value * BAR_COUNT));

function formatTime(seconds: number): string {
    const total = Math.max(0, Math.round(seconds));
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

onMounted(() => {
    audio.addEventListener('loadedmetadata', onLoadedMetadata);
    audio.addEventListener('timeupdate', onTimeUpdate);
    audio.addEventListener('ended', onEnded);
    loadWaveform();
});

onBeforeUnmount(() => {
    audio.pause();
    audio.removeEventListener('loadedmetadata', onLoadedMetadata);
    audio.removeEventListener('timeupdate', onTimeUpdate);
    audio.removeEventListener('ended', onEnded);
});

watch(() => props.src, (value) => {
    audio.pause();
    playing.value = false;
    currentTime.value = 0;
    audio.src = value;
    waveReady.value = false;
    loadWaveform();
});
</script>

<template>
    <div class="flex items-center gap-2">
        <Button type="button" size="icon" variant="ghost" class="size-8 shrink-0 rounded-full bg-current/10 hover:bg-current/20" :aria-label="playing ? 'Пауза' : 'Воспроизвести'" @click="toggle">
            <PauseIcon v-if="playing" class="size-3.5" />
            <PlayIcon v-else class="size-3.5" />
        </Button>

        <div class="flex h-8 flex-1 items-center gap-[2px]" :class="{ 'animate-pulse': ! waveReady }">
            <button
                v-for="(bar, index) in bars"
                :key="index"
                type="button"
                class="w-[3px] shrink-0 rounded-full transition-colors"
                :class="index < filledBars ? 'bg-current' : 'bg-current/30'"
                :style="{ height: `${Math.round(bar * 100)}%` }"
                :aria-label="`Перейти к ${formatTime((index / BAR_COUNT) * duration)}`"
                @click="seekTo(index)"
            />
        </div>

        <span class="w-9 shrink-0 text-right font-mono text-[11px] opacity-70">{{ formatTime(playing || currentTime ? currentTime : duration) }}</span>
    </div>
</template>
