<script setup lang="ts">
import { computed } from 'vue';
import { Bot, CheckSquare, MessageSquare, Sparkles } from '@lucide/vue';
import StatTile from '../visual/StatTile.vue';
import type { AiRun, Conversation, Task } from '../../../stores/crmDashboard';

const props = defineProps<{ conversations: Conversation[]; openTasks: Task[]; aiRuns: AiRun[] }>();

const activeStatuses = new Set(['open', 'pending', 'pending_operator']);
const activeConversations = computed(() => props.conversations.filter((item) => activeStatuses.has(item.status)));
const aiShare = computed(() => (props.conversations.length
    ? Math.round((props.aiRuns.length / props.conversations.length) * 100)
    : 0));
const avgConfidence = computed(() => (props.aiRuns.length
    ? Math.round(props.aiRuns.reduce((sum, run) => sum + run.confidence, 0) / props.aiRuns.length)
    : 0));
</script>

<template>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <StatTile label="Всего обращений" :value="conversations.length" :delta="`${activeConversations.length} активных`" :icon="MessageSquare" hint="Все диалоги во всех каналах за всё время" />
        <StatTile label="Автоматизация (AI)" :value="`${aiShare}%`" :icon="Bot" highlight :hint="`${aiRuns.length} из ${conversations.length} диалогов обработаны AI хотя бы раз`" />
        <StatTile label="Средняя уверенность AI" :value="`${avgConfidence}%`" :icon="Sparkles" hint="Средняя уверенность AI по всем запускам" />
        <StatTile label="Открытые задачи" :value="openTasks.length" :icon="CheckSquare" hint="Задачи со статусом «Открыта» или «В работе»" />
    </div>
</template>
