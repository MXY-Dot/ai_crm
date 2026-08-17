<script setup lang="ts">
import { computed } from 'vue';
import { FlaskConical } from '@lucide/vue';
import type { AiAgent, AiRun } from '../../../stores/crmDashboard';
import { aiIntentLabels } from '../../../lib/statusLabels';

const props = defineProps<{ agent: AiAgent | null; aiRuns: AiRun[] }>();

const runs = computed(() => props.aiRuns
    .filter((run) => run.agent?.id === props.agent?.id)
    .slice(0, 8));

function confidenceTone(confidence: number): string {
    if (confidence >= 70) return 'text-primary';
    if (confidence >= 45) return 'text-amber-600 dark:text-amber-300';

    return 'text-destructive';
}

const sentimentEmoji: Record<string, string> = { positive: '🙂', negative: '🙁', neutral: '😐' };
</script>

<template>
    <div class="flex flex-col overflow-hidden rounded-xl border border-border bg-card">
        <div class="flex items-center gap-2 border-b p-4 border-border bg-muted">
            <FlaskConical class="h-4 w-4 text-primary" />
            <h2 class="font-display text-base font-semibold ui-text">Последние запуски</h2>
        </div>
        <div class="flex-1 space-y-3 overflow-y-auto p-4">
            <article v-for="run in runs" :key="run.id" class="rounded-lg border p-3 border-border bg-background">
                <div class="mb-1 flex items-center justify-between gap-2">
                    <span class="flex items-center gap-1.5 text-xs font-semibold ui-text">
                        <span v-if="run.payload?.detected_sentiment && run.payload.detected_sentiment !== 'neutral'" :title="run.payload.detected_sentiment">{{ sentimentEmoji[run.payload.detected_sentiment] }}</span>
                        {{ (run.intent && (aiIntentLabels[run.intent] ?? run.intent)) ?? run.conversation?.subject ?? 'AI-запуск' }}
                    </span>
                    <span class="font-mono text-[11px] font-semibold" :class="confidenceTone(run.confidence)">{{ run.confidence }}%</span>
                </div>
                <p class="text-xs leading-5 ui-subtle">{{ run.summary }}</p>
            </article>
            <p v-if="! runs.length" class="rounded-lg border border-dashed p-6 text-center text-sm ui-subtle border-border">
                {{ agent ? 'У этого ассистента ещё нет запусков' : 'Выберите ассистента' }}
            </p>
        </div>
    </div>
</template>
