<script setup lang="ts">
import { Calendar, GitCompareArrows } from '@lucide/vue';
import { Input } from '../../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';

export type DatePreset = 'today' | 'yesterday' | '7d' | '30d' | 'this_week' | 'last_week' | 'this_month' | 'last_month' | 'custom';

const props = defineProps<{ preset: DatePreset; from: string; to: string; compare: boolean }>();
defineEmits<{
    'update:preset': [DatePreset];
    'update:from': [string];
    'update:to': [string];
    'update:compare': [boolean];
}>();

const PRESET_LABELS: Record<DatePreset, string> = {
    today: 'Сегодня',
    yesterday: 'Вчера',
    '7d': 'Последние 7 дней',
    '30d': 'Последние 30 дней',
    this_week: 'Эта неделя',
    last_week: 'Прошлая неделя',
    this_month: 'Этот месяц',
    last_month: 'Прошлый месяц',
    custom: 'Свой период',
};
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Select :model-value="preset" @update:model-value="(v) => $emit('update:preset', v as DatePreset)">
            <SelectTrigger class="h-9 w-44"><Calendar class="h-3.5 w-3.5 shrink-0 ui-subtle" /><SelectValue /></SelectTrigger>
            <SelectContent>
                <SelectItem v-for="(label, key) in PRESET_LABELS" :key="key" :value="key">{{ label }}</SelectItem>
            </SelectContent>
        </Select>

        <template v-if="preset === 'custom'">
            <Input type="date" class="h-9 w-[8.5rem]" :model-value="from" @update:model-value="(v) => $emit('update:from', String(v))" />
            <span class="text-xs ui-subtle">—</span>
            <Input type="date" class="h-9 w-[8.5rem]" :model-value="to" @update:model-value="(v) => $emit('update:to', String(v))" />
        </template>

        <button
            type="button"
            class="flex items-center gap-1.5 whitespace-nowrap rounded-lg border px-3 py-1.5 text-xs font-medium transition"
            :class="compare ? 'border-primary/50 bg-primary/10 text-primary' : 'border-border ui-subtle hover:bg-muted'"
            @click="$emit('update:compare', ! compare)"
        >
            <GitCompareArrows class="h-3.5 w-3.5" />Сравнить с прошлым периодом
        </button>
    </div>
</template>
