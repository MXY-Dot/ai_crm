<script setup lang="ts">
import { VisArea, VisXYContainer } from '@unovis/vue';
import { ChartContainer, ChartCrosshair, ChartTooltip, ChartTooltipContent, componentToString, type ChartConfig } from '../../ui/chart';

type DayPoint = { date: string; label: string; requests: number };

defineProps<{ daily: DayPoint[] }>();

const chartConfig: ChartConfig = {
    requests: { label: 'Запросы', color: 'var(--primary)' },
};
</script>

<template>
    <ChartContainer :config="chartConfig" class="h-16 w-full">
        <VisXYContainer :data="daily">
            <VisArea
                :x="(d: DayPoint, i: number) => i"
                :y="(d: DayPoint) => d.requests"
                :color="chartConfig.requests.color"
                :opacity="0.15"
                :line="true"
                :line-width="2"
                :line-color="chartConfig.requests.color"
            />
            <ChartTooltip />
            <ChartCrosshair :template="componentToString(chartConfig, ChartTooltipContent)" :color="[chartConfig.requests.color]" />
        </VisXYContainer>
    </ChartContainer>
</template>
