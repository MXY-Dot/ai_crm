<script setup lang="ts">
import { computed } from 'vue';
import { Bot, MessageCircle, MessageSquare, Sparkles } from '@lucide/vue';
import StatTile from '../visual/StatTile.vue';

export type PeriodKpis = { messages: number; conversations: number; ai_runs: number; avg_confidence: number };

const props = defineProps<{ current: PeriodKpis | null; previous: PeriodKpis | null }>();

type Delta = { text: string; trend: 'up' | 'down' } | undefined;

function delta(curr: number, prev: number): Delta {
    if (prev === 0) return curr > 0 ? { text: 'новое за период', trend: 'up' } : undefined;

    const percent = Math.round(((curr - prev) / prev) * 100);

    if (percent === 0) return undefined;

    return { text: `${percent > 0 ? '+' : ''}${percent}% к прошлому периоду`, trend: percent >= 0 ? 'up' : 'down' };
}

const messagesDelta = computed<Delta>(() => (props.current && props.previous ? delta(props.current.messages, props.previous.messages) : undefined));
const conversationsDelta = computed<Delta>(() => (props.current && props.previous ? delta(props.current.conversations, props.previous.conversations) : undefined));
const aiRunsDelta = computed<Delta>(() => (props.current && props.previous ? delta(props.current.ai_runs, props.previous.ai_runs) : undefined));
const confidenceDelta = computed<Delta>(() => (props.current && props.previous ? delta(props.current.avg_confidence, props.previous.avg_confidence) : undefined));
</script>

<template>
    <div v-if="current && previous" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4" data-tour="analytics-comparison">
        <StatTile label="Сообщений" :value="current.messages" :icon="MessageSquare" :delta="messagesDelta?.text" :trend="messagesDelta?.trend" />
        <StatTile label="Диалогов" :value="current.conversations" :icon="MessageCircle" :delta="conversationsDelta?.text" :trend="conversationsDelta?.trend" />
        <StatTile label="AI-запусков" :value="current.ai_runs" :icon="Bot" :delta="aiRunsDelta?.text" :trend="aiRunsDelta?.trend" />
        <StatTile label="Ср. уверенность AI" :value="`${current.avg_confidence}%`" :icon="Sparkles" :delta="confidenceDelta?.text" :trend="confidenceDelta?.trend" />
    </div>
</template>
