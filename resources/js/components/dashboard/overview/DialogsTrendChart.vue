<script setup lang="ts">
import { computed } from 'vue';
import type { Conversation } from '../../../stores/crmDashboard';

const props = defineProps<{ conversations: Conversation[] }>();

const days = computed(() => {
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

        return { label: new Intl.DateTimeFormat('ru-RU', { weekday: 'short' }).format(date), count };
    });
});

const maxCount = computed(() => Math.max(1, ...days.value.map((day) => day.count)));

const points = computed(() => days.value.map((day, index) => {
    const x = (index / (days.value.length - 1)) * 100;
    const y = 100 - (day.count / maxCount.value) * 90;

    return `${x},${y}`;
}).join(' '));

const areaPoints = computed(() => `0,100 ${points.value} 100,100`);
</script>

<template>
    <div class="rounded-xl border p-5 lg:col-span-2" style="border-color: var(--border); background: var(--card)">
        <div class="mb-6 flex items-center justify-between">
            <h3 class="font-display text-base font-semibold ui-text">График диалогов</h3>
            <span class="text-xs ui-subtle">Последние 7 дней</span>
        </div>
        <div class="relative h-[220px] w-full">
            <svg class="h-full w-full overflow-visible" preserveAspectRatio="none" viewBox="0 0 100 100">
                <line v-for="line in [0, 25, 50, 75, 100]" :key="line" x1="0" :y1="line" x2="100" :y2="line" stroke="var(--border)" stroke-width="0.5" />
                <polygon :points="areaPoints" fill="var(--primary)" opacity="0.12" />
                <polyline :points="points" fill="none" stroke="var(--primary)" stroke-width="2.5" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />
            </svg>
        </div>
        <div class="mt-2 flex justify-between text-xs font-medium uppercase ui-subtle">
            <span v-for="day in days" :key="day.label">{{ day.label }}</span>
        </div>
    </div>
</template>
