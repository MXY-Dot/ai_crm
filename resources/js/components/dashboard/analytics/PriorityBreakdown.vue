<script setup lang="ts">
import { computed } from 'vue';
import { Lightbulb } from '@lucide/vue';
import type { Conversation } from '../../../stores/crmDashboard';
import { priorityLabels } from '../../../lib/statusLabels';
import { Progress } from '../../ui/progress';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '../../ui/tooltip';

const props = defineProps<{ conversations: Conversation[] }>();

const rows = computed(() => {
    const total = props.conversations.length;
    const counts = new Map<string, number>();
    for (const conversation of props.conversations) {
        counts.set(conversation.priority, (counts.get(conversation.priority) ?? 0) + 1);
    }

    return [...counts.entries()]
        .sort((a, b) => b[1] - a[1])
        .map(([priority, count]) => ({ priority, count, total, percent: total ? Math.round((count / total) * 100) : 0 }));
});
</script>

<template>
    <div class="rounded-xl border p-5 border-border bg-card">
        <div class="mb-6">
            <h3 class="font-display text-base font-semibold ui-text">Приоритеты диалогов</h3>
            <p class="text-sm ui-subtle">Распределение по срочности</p>
        </div>
        <TooltipProvider :delay-duration="100">
            <div class="space-y-5">
                <Tooltip v-for="row in rows" :key="row.priority">
                    <TooltipTrigger as-child>
                        <div class="cursor-pointer">
                            <div class="mb-1 flex justify-between text-sm">
                                <span class="ui-text">{{ priorityLabels[row.priority] ?? row.priority }}</span>
                                <span class="font-mono ui-subtle">{{ row.percent }}%</span>
                            </div>
                            <Progress :model-value="row.percent" />
                        </div>
                    </TooltipTrigger>
                    <TooltipContent>{{ row.count }} из {{ row.total }} диалогов</TooltipContent>
                </Tooltip>
                <p v-if="! rows.length" class="text-sm ui-subtle">Нет данных по диалогам</p>
            </div>
        </TooltipProvider>
        <div class="mt-6 flex gap-3 rounded-lg border p-3 text-sm border-border bg-muted">
            <Lightbulb class="h-4 w-4 shrink-0 text-primary" />
            <span class="ui-subtle">Данные считаются напрямую из реальных диалогов вашего workspace.</span>
        </div>
    </div>
</template>
