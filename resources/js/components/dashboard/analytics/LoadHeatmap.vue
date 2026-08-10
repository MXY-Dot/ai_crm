<script setup lang="ts">
import { computed } from 'vue';
import type { Message } from '../../../stores/crmDashboard';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '../../ui/tooltip';

const props = defineProps<{ messages: Message[] }>();
const dayLabels = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
const partLabels = ['Ночь', 'Утро', 'День', 'Вечер'];

function dayPart(hour: number): number {
    if (hour < 6) return 0;
    if (hour < 12) return 1;
    if (hour < 18) return 2;

    return 3;
}

const grid = computed(() => {
    const counts = Array.from({ length: 4 }, () => Array(7).fill(0));

    for (const message of props.messages) {
        if (! message.sent_at) continue;
        const date = new Date(message.sent_at);
        const weekday = (date.getDay() + 6) % 7;
        counts[dayPart(date.getHours())][weekday] += 1;
    }

    const max = Math.max(1, ...counts.flat());

    return counts.map((row) => row.map((count) => ({ count, intensity: count / max })));
});
</script>

<template>
    <div class="rounded-xl border p-5 border-border bg-card">
        <div class="mb-6">
            <h3 class="font-display text-base font-semibold ui-text">Тепловая карта нагрузки</h3>
            <p class="text-sm ui-subtle">Сообщения по дням недели и времени суток</p>
        </div>
        <div class="min-w-[420px] overflow-x-auto">
            <div class="grid grid-cols-8 gap-1">
                <div />
                <div v-for="day in dayLabels" :key="day" class="text-center text-[10px] font-semibold uppercase ui-subtle">{{ day }}</div>
            </div>
            <TooltipProvider :delay-duration="100">
                <div v-for="(row, rowIndex) in grid" :key="rowIndex" class="mt-1 grid grid-cols-8 items-center gap-1">
                    <div class="pr-2 text-right font-mono text-[10px] ui-subtle">{{ partLabels[rowIndex] }}</div>
                    <Tooltip v-for="(cell, cellIndex) in row" :key="cellIndex">
                        <TooltipTrigger as-child>
                            <div
                                class="h-8 cursor-pointer rounded transition hover:ring-2 hover:ring-primary/50"
                                :style="{ background: `color-mix(in srgb, var(--primary) ${Math.round(cell.intensity * 90)}%, var(--muted))` }"
                            />
                        </TooltipTrigger>
                        <TooltipContent>{{ dayLabels[cellIndex] }}, {{ partLabels[rowIndex] }}: {{ cell.count }} сообщений</TooltipContent>
                    </Tooltip>
                </div>
            </TooltipProvider>
        </div>
    </div>
</template>
