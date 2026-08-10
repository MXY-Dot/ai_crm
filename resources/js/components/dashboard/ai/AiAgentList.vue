<script setup lang="ts">
import { computed } from 'vue';
import CreateAgentDialog from './CreateAgentDialog.vue';
import type { AiAgent, AiRun } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';

const props = defineProps<{ agents: AiAgent[]; aiRuns: AiRun[]; selectedId: number | null }>();
defineEmits<{ select: [id: number] }>();
const locale = useLocaleStore();

function stats(agent: AiAgent) {
    const runs = props.aiRuns.filter((run) => run.agent?.id === agent.id);
    const avgConfidence = runs.length ? Math.round(runs.reduce((sum, run) => sum + run.confidence, 0) / runs.length) : null;

    return { count: runs.length, avgConfidence };
}

const statusTone: Record<string, string> = {
    active: 'text-primary',
    paused: 'ui-subtle',
    disabled: 'text-destructive',
};
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between">
            <h2 class="font-display text-base font-semibold ui-text">Ассистенты</h2>
            <CreateAgentDialog />
        </div>
        <div class="divide-y overflow-hidden rounded-xl border" style="border-color: var(--border); background: var(--card)">
            <button
                v-for="agent in agents"
                :key="agent.id"
                class="w-full border-l-4 p-4 text-left transition"
                :class="selectedId === agent.id ? 'border-primary' : 'border-transparent hover:bg-muted'"
                :style="{ background: selectedId === agent.id ? 'var(--muted)' : undefined }"
                @click="$emit('select', agent.id)"
            >
                <div class="mb-2 flex items-start justify-between gap-2">
                    <h3 class="text-sm font-semibold ui-text">{{ agent.name }}</h3>
                    <span class="rounded px-2 py-0.5 text-[10px] font-semibold" :class="statusTone[agent.status] ?? 'ui-subtle'" style="background: var(--muted)">{{ locale.t('ai.status.' + agent.status) }}</span>
                </div>
                <p class="mb-2 text-xs ui-subtle">{{ agent.provider }}</p>
                <div class="flex justify-between font-mono text-xs ui-subtle">
                    <span>{{ stats(agent).count }} запусков</span>
                    <span v-if="stats(agent).avgConfidence !== null" class="text-primary">{{ stats(agent).avgConfidence }}% conf.</span>
                </div>
            </button>
            <p v-if="! agents.length" class="p-6 text-center text-sm ui-subtle">Нет ассистентов</p>
        </div>
    </div>
</template>
