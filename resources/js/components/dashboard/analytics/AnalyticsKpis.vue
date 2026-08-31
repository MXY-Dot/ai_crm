<script setup lang="ts">
import { Bot, CheckSquare, MessageSquare, Repeat, Sparkles, UserPlus, Users, Zap } from '@lucide/vue';
import StatTile from '../visual/StatTile.vue';

export type PeriodKpisFull = {
    messages: number;
    conversations: number;
    total_leads: number;
    conversion_rate: number;
    ai_runs: number;
    avg_confidence: number;
    avg_latency_ms: number;
    ai_replacement_rate: number;
    unique_customers: number;
    new_customers: number;
    repeat_customers: number;
    handed_to_operator: number;
    fully_ai_handled: number;
    avg_messages_per_conversation: number;
    active_conversations: number;
};

// Real numbers from AnalyticsSnapshot::kpis() -- the same aggregate the period-
// comparison panel and the weekly AI report already build on, not a second,
// narrower computation over whatever raw rows happened to be loaded. Found live
// that this tile used to compute its own 4 stats client-side from raw props and
// silently ignored the selected date range entirely.
const props = defineProps<{ kpis: PeriodKpisFull | null; openTasksCount: number }>();
</script>

<template>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <StatTile label="Обращения" :value="props.kpis?.conversations ?? 0" :delta="`${props.kpis?.active_conversations ?? 0} активных сейчас`" :icon="MessageSquare" hint="Диалоги, начатые за выбранный период" />
        <StatTile label="Автоматизация (AI)" :value="`${props.kpis ? Math.round((props.kpis.fully_ai_handled / Math.max(props.kpis.conversations, 1)) * 100) : 0}%`" :icon="Bot" highlight :hint="`${props.kpis?.fully_ai_handled ?? 0} диалогов полностью обработаны AI, ${props.kpis?.handed_to_operator ?? 0} переданы оператору`" />
        <StatTile label="Средняя уверенность AI" :value="`${props.kpis?.avg_confidence ?? 0}%`" :icon="Sparkles" :hint="`${props.kpis?.ai_runs ?? 0} запусков AI за период`" />
        <StatTile label="Открытые задачи" :value="openTasksCount" :icon="CheckSquare" hint="Задачи со статусом «Открыта» или «В работе»" />
        <StatTile label="Уникальные клиенты" :value="props.kpis?.unique_customers ?? 0" :icon="Users" hint="Разные клиенты, написавшие за период" />
        <StatTile label="Новые клиенты" :value="props.kpis?.new_customers ?? 0" :icon="UserPlus" hint="Впервые зарегистрированы за период" />
        <StatTile label="Повторные клиенты" :value="props.kpis?.repeat_customers ?? 0" :icon="Repeat" hint="Обращались более одного раза" />
        <StatTile label="Сообщений на диалог" :value="props.kpis?.avg_messages_per_conversation ?? 0" :icon="Zap" :hint="`Среднее время ответа AI: ${props.kpis?.avg_latency_ms ?? 0} мс`" />
    </div>
</template>
