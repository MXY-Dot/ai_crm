<script setup lang="ts">
import { computed } from 'vue';
import { Lightbulb } from '@lucide/vue';
import type { Conversation } from '../../../stores/crmDashboard';
import { titleCase } from '../../../lib/format';
import { Progress } from '../../ui/progress';

const props = defineProps<{ conversations: Conversation[] }>();

const rows = computed(() => {
    const total = props.conversations.length;
    const counts = new Map<string, number>();
    for (const conversation of props.conversations) {
        counts.set(conversation.priority, (counts.get(conversation.priority) ?? 0) + 1);
    }

    return [...counts.entries()]
        .sort((a, b) => b[1] - a[1])
        .map(([priority, count]) => ({ priority, count, percent: total ? Math.round((count / total) * 100) : 0 }));
});
</script>

<template>
    <div class="rounded-xl border p-5" style="border-color: var(--border); background: var(--card)">
        <div class="mb-6">
            <h3 class="font-display text-base font-semibold ui-text">Приоритеты диалогов</h3>
            <p class="text-sm ui-subtle">Распределение по срочности</p>
        </div>
        <div class="space-y-5">
            <div v-for="row in rows" :key="row.priority">
                <div class="mb-1 flex justify-between text-sm">
                    <span class="ui-text">{{ titleCase(row.priority) }}</span>
                    <span class="font-mono ui-subtle">{{ row.percent }}%</span>
                </div>
                <Progress :model-value="row.percent" />
            </div>
            <p v-if="! rows.length" class="text-sm ui-subtle">Нет данных по диалогам</p>
        </div>
        <div class="mt-6 flex gap-3 rounded-lg border p-3 text-sm" style="border-color: var(--border); background: var(--muted)">
            <Lightbulb class="h-4 w-4 shrink-0 text-primary" />
            <span class="ui-subtle">Данные считаются напрямую из реальных диалогов вашего workspace.</span>
        </div>
    </div>
</template>
