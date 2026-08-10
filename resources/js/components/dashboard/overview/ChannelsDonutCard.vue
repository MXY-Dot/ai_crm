<script setup lang="ts">
import { computed } from 'vue';
import type { Conversation } from '../../../stores/crmDashboard';
import { titleCase } from '../../../lib/format';

const props = defineProps<{ conversations: Conversation[] }>();
const channelColors: Record<string, string> = {
    telegram: 'var(--brand-telegram)',
    whatsapp: 'var(--brand-whatsapp)',
    instagram: 'var(--brand-instagram-to)',
    website: 'var(--brand-website)',
    web: 'var(--brand-website)',
};

const segments = computed(() => {
    const total = props.conversations.length;
    if (! total) return [];

    const counts = new Map<string, number>();
    for (const conversation of props.conversations) {
        const key = conversation.channel?.provider ?? 'other';
        counts.set(key, (counts.get(key) ?? 0) + 1);
    }

    return [...counts.entries()]
        .sort((a, b) => b[1] - a[1])
        .map(([provider, count]) => ({
            provider,
            count,
            percent: Math.round((count / total) * 100),
            color: channelColors[provider.toLowerCase()] ?? 'var(--muted-foreground)',
        }));
});

const gradient = computed(() => {
    let cursor = 0;

    return segments.value.map((segment) => {
        const start = cursor;
        cursor += segment.percent;

        return `${segment.color} ${start}% ${cursor}%`;
    }).join(', ') || 'var(--muted) 0% 100%';
});
</script>

<template>
    <div class="flex flex-col rounded-xl border p-5" style="border-color: var(--border); background: var(--card)">
        <h3 class="mb-6 font-display text-base font-semibold ui-text">Распределение каналов</h3>
        <div class="flex flex-1 flex-col items-center justify-center">
            <div class="relative h-40 w-40 rounded-full" :style="{ background: `conic-gradient(${gradient})` }">
                <div class="absolute inset-3 flex flex-col items-center justify-center rounded-full" style="background: var(--card)">
                    <span class="font-display text-2xl font-bold ui-text">{{ conversations.length }}</span>
                </div>
            </div>
            <div class="mt-6 w-full space-y-2">
                <div v-for="segment in segments" :key="segment.provider" class="flex items-center justify-between text-sm">
                    <div class="flex items-center gap-2">
                        <span class="h-3 w-3 rounded-full" :style="{ background: segment.color }" />
                        <span class="ui-text">{{ titleCase(segment.provider) }}</span>
                    </div>
                    <span class="font-mono text-xs ui-subtle">{{ segment.percent }}%</span>
                </div>
                <p v-if="! segments.length" class="text-sm ui-subtle">Нет данных по каналам</p>
            </div>
        </div>
    </div>
</template>
