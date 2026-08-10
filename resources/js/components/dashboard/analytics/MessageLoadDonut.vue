<script setup lang="ts">
import { computed } from 'vue';
import type { Message } from '../../../stores/crmDashboard';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '../../ui/tooltip';

const props = defineProps<{ messages: Message[] }>();

const groups = computed(() => {
    const total = props.messages.length;
    const ai = props.messages.filter((message) => message.sender_type === 'ai').length;
    const operator = props.messages.filter((message) => message.sender_type === 'operator').length;

    return {
        total,
        ai,
        operator,
        aiPercent: total ? Math.round((ai / total) * 100) : 0,
        operatorPercent: total ? Math.round((operator / total) * 100) : 0,
    };
});

const gradient = computed(() => `var(--primary) 0% ${groups.value.aiPercent}%, var(--muted) ${groups.value.aiPercent}% 100%`);
</script>

<template>
    <div class="flex flex-col rounded-xl border p-5 border-border bg-card">
        <div class="mb-6">
            <h3 class="font-display text-base font-semibold ui-text">AI vs Операторы</h3>
            <p class="text-sm ui-subtle">Распределение ответов</p>
        </div>
        <div class="flex flex-1 items-center justify-center">
            <TooltipProvider :delay-duration="100">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <div class="relative h-40 w-40 cursor-pointer rounded-full transition hover:opacity-90" :style="{ background: `conic-gradient(${gradient})` }">
                            <div class="absolute inset-3 flex flex-col items-center justify-center rounded-full bg-card">
                                <span class="font-display text-2xl font-bold ui-text">{{ groups.aiPercent }}%</span>
                                <span class="text-[10px] font-semibold uppercase ui-subtle">AI</span>
                            </div>
                        </div>
                    </TooltipTrigger>
                    <TooltipContent>AI: {{ groups.ai }} ({{ groups.aiPercent }}%) &middot; Операторы: {{ groups.operator }} ({{ groups.operatorPercent }}%)</TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>
        <div class="mt-6 space-y-2 text-sm">
            <div class="flex items-center justify-between">
                <span class="flex items-center gap-2 ui-text"><span class="h-3 w-3 rounded-full bg-primary" />AI Ассистент</span>
                <span class="font-mono ui-subtle">{{ groups.ai }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="flex items-center gap-2 ui-text"><span class="h-3 w-3 rounded-full bg-muted-foreground" />Операторы</span>
                <span class="font-mono ui-subtle">{{ groups.operator }}</span>
            </div>
        </div>
    </div>
</template>
