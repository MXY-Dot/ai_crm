<script setup lang="ts">
import { computed } from 'vue';
import { Bot, CheckSquare, MessageSquare, Target } from '@lucide/vue';
import StatTile from '../visual/StatTile.vue';
import type { AiRun, Conversation, Lead, Task } from '../../../stores/crmDashboard';

const props = defineProps<{
    conversations: Conversation[];
    leads: Lead[];
    openTasks: Task[];
    aiRuns: AiRun[];
}>();

const activeStatuses = new Set(['open', 'pending', 'pending_operator']);
const activeConversations = computed(() => props.conversations.filter((item) => activeStatuses.has(item.status)));
const aiShare = computed(() => (props.conversations.length
    ? Math.round((props.aiRuns.length / props.conversations.length) * 100)
    : 0));
const wonLeads = computed(() => props.leads.filter((lead) => lead.status === 'won'));
const conversionRate = computed(() => (props.leads.length
    ? ((wonLeads.value.length / props.leads.length) * 100).toFixed(1)
    : '0.0'));
</script>

<template>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <StatTile label="Диалоги" :value="conversations.length" :delta="`${activeConversations.length} активных`" :icon="MessageSquare" />
        <StatTile label="Доля ответов AI" :value="`${aiShare}%`" :delta="`${aiRuns.length} запусков`" :icon="Bot" highlight />
        <StatTile label="Открытые задачи" :value="openTasks.length" :icon="CheckSquare" />
        <StatTile label="Конверсия в лид" :value="`${conversionRate}%`" :delta="`${wonLeads.length} из ${leads.length}`" :icon="Target" />
    </div>
</template>
