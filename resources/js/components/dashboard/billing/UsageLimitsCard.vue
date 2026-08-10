<script setup lang="ts">
import { computed } from 'vue';
import type { Plan } from '../../../lib/plans';
import { Progress } from '../../ui/progress';

const props = defineProps<{ plan: Plan; conversationsCount: number; aiAgentsCount: number }>();

function percent(used: number, limit: number | null): number {
    if (limit === null) return 0;

    return Math.min(100, Math.round((used / limit) * 100));
}

const conversationsLabel = computed(() => (props.plan.conversationsLimit === null
    ? `${props.conversationsCount} / безлимит`
    : `${props.conversationsCount} / ${props.plan.conversationsLimit}`));

const agentsLabel = computed(() => (props.plan.aiAgentsLimit === null
    ? `${props.aiAgentsCount} / безлимит`
    : `${props.aiAgentsCount} / ${props.plan.aiAgentsLimit}`));
</script>

<template>
    <div class="rounded-xl border p-6" style="border-color: var(--border); background: var(--card)">
        <h2 class="mb-4 text-[11px] font-semibold uppercase tracking-wider ui-subtle">Использование лимитов</h2>
        <div class="space-y-5">
            <div>
                <div class="mb-1 flex items-end justify-between">
                    <span class="text-sm font-semibold ui-text">Диалоги</span>
                    <span class="font-mono text-xs ui-subtle">{{ conversationsLabel }}</span>
                </div>
                <Progress :model-value="percent(conversationsCount, plan.conversationsLimit)" />
            </div>
            <div>
                <div class="mb-1 flex items-end justify-between">
                    <span class="text-sm font-semibold ui-text">AI-ассистенты</span>
                    <span class="font-mono text-xs ui-subtle">{{ agentsLabel }}</span>
                </div>
                <Progress :model-value="percent(aiAgentsCount, plan.aiAgentsLimit)" />
            </div>
        </div>
    </div>
</template>
