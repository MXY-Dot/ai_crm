<script setup lang="ts">
import type { Component } from 'vue';
import { TrendingDown, TrendingUp } from '@lucide/vue';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '../../ui/tooltip';

withDefaults(defineProps<{
    label: string;
    value: string | number;
    icon?: Component;
    delta?: string;
    trend?: 'up' | 'down';
    highlight?: boolean;
    hint?: string;
}>(), {
    trend: 'up',
});
</script>

<template>
    <article
        v-if="! hint"
        class="flex h-32 flex-col justify-between rounded-xl border bg-card p-4"
        :class="highlight ? 'border-primary' : 'border-border'"
    >
        <div class="flex items-start justify-between gap-2">
            <span class="text-[11px] font-semibold uppercase tracking-wider ui-subtle">{{ label }}</span>
            <component :is="icon" v-if="icon" class="h-4 w-4" :class="highlight ? 'text-primary' : 'ui-subtle'" />
        </div>
        <div class="flex items-baseline gap-2">
            <span class="font-display text-3xl font-bold ui-text">{{ value }}</span>
            <span v-if="delta" class="flex items-center gap-0.5 text-xs font-medium" :class="trend === 'down' ? 'text-destructive' : 'text-primary'">
                <component :is="trend === 'down' ? TrendingDown : TrendingUp" class="h-3.5 w-3.5" />{{ delta }}
            </span>
        </div>
    </article>
    <TooltipProvider v-else :delay-duration="100">
        <Tooltip>
            <TooltipTrigger as-child>
                <article
                    class="flex h-32 cursor-pointer flex-col justify-between rounded-xl border bg-card p-4"
                    :class="highlight ? 'border-primary' : 'border-border'"
                >
                    <div class="flex items-start justify-between gap-2">
                        <span class="text-[11px] font-semibold uppercase tracking-wider ui-subtle">{{ label }}</span>
                        <component :is="icon" v-if="icon" class="h-4 w-4" :class="highlight ? 'text-primary' : 'ui-subtle'" />
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="font-display text-3xl font-bold ui-text">{{ value }}</span>
                        <span v-if="delta" class="flex items-center gap-0.5 text-xs font-medium" :class="trend === 'down' ? 'text-destructive' : 'text-primary'">
                            <component :is="trend === 'down' ? TrendingDown : TrendingUp" class="h-3.5 w-3.5" />{{ delta }}
                        </span>
                    </div>
                </article>
            </TooltipTrigger>
            <TooltipContent>{{ hint }}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
