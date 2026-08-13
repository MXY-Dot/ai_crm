<script setup lang="ts">
import { computed } from 'vue';
import { VisArea, VisAxis, VisXYContainer } from '@unovis/vue';
import type { Conversation } from '../../../stores/crmDashboard';
import { ChartContainer, ChartCrosshair, ChartTooltip, ChartTooltipContent, componentToString, type ChartConfig } from '../../ui/chart';

const props = defineProps<{ conversations: Conversation[] }>();

type Day = { label: string; fullLabel: string; count: number };

const days = computed<Day[]>(() => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return Array.from({ length: 7 }, (_, index) => {
        const date = new Date(today);
        date.setDate(date.getDate() - (6 - index));

        const count = props.conversations.filter((conversation) => {
            if (! conversation.last_message_at) return false;
            const stamp = new Date(conversation.last_message_at);

            return stamp.getFullYear() === date.getFullYear()
                && stamp.getMonth() === date.getMonth()
                && stamp.getDate() === date.getDate();
        }).length;

        return {
            label: new Intl.DateTimeFormat('ru-RU', { weekday: 'short' }).format(date),
            fullLabel: new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' }).format(date),
            count,
        };
    });
});

const chartConfig: ChartConfig = {
    count: { label: 'Диалоги', color: 'var(--primary)' },
};
</script>

<template>
    <div class="rounded-xl border p-5 lg:col-span-2 border-border bg-card">
        <div class="mb-6 flex items-center justify-between">
            <h3 class="font-display text-base font-semibold ui-text">График диалогов</h3>
            <span class="text-xs ui-subtle">Последние 7 дней</span>
        </div>
        <ChartContainer :config="chartConfig" class="h-[220px] w-full">
            <VisXYContainer :data="days">
                <VisArea
                    :x="(d: Day, i: number) => i"
                    :y="(d: Day) => d.count"
                    :color="chartConfig.count.color"
                    :opacity="0.15"
                    :line="true"
                    :line-width="2.5"
                    :line-color="chartConfig.count.color"
                />
                <VisAxis
                    type="x"
                    :x="(d: Day, i: number) => i"
                    :tick-line="false"
                    :domain-line="false"
                    :grid-line="false"
                    :tick-format="(i: number) => days[i]?.label ?? ''"
                />
                <VisAxis type="y" :tick-format="() => ''" :tick-line="false" :domain-line="false" :grid-line="true" />
                <ChartTooltip />
                <ChartCrosshair :template="componentToString(chartConfig, ChartTooltipContent)" :color="[chartConfig.count.color]" />
            </VisXYContainer>
        </ChartContainer>
    </div>
</template>
