<script setup lang="ts">
import { computed } from 'vue';
import { Calendar, ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

export type DateRangeGranularity = 'day' | 'week' | 'month';

const props = defineProps<{ granularity: DateRangeGranularity; anchor: string }>();
const emit = defineEmits<{ 'update:granularity': [DateRangeGranularity]; 'update:anchor': [string] }>();

const granularityLabels: Record<DateRangeGranularity, string> = { day: 'День', week: 'Неделя', month: 'Месяц' };

const label = computed(() => {
    const date = new Date(props.anchor);
    if (props.granularity === 'day') return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }).format(date);
    if (props.granularity === 'month') return new Intl.DateTimeFormat('ru-RU', { month: 'long', year: 'numeric' }).format(date);

    const start = new Date(date);
    start.setDate(start.getDate() - start.getDay() + (start.getDay() === 0 ? -6 : 1));
    const end = new Date(start);
    end.setDate(end.getDate() + 6);

    return `${new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short' }).format(start)} – ${new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short' }).format(end)}`;
});

function step(direction: 1 | -1): void {
    const date = new Date(props.anchor);
    if (props.granularity === 'day') date.setDate(date.getDate() + direction);
    else if (props.granularity === 'week') date.setDate(date.getDate() + direction * 7);
    else date.setMonth(date.getMonth() + direction);

    emit('update:anchor', date.toISOString().slice(0, 10));
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Select :model-value="granularity" @update:model-value="(v) => $emit('update:granularity', v as DateRangeGranularity)">
            <SelectTrigger class="h-9 w-32"><SelectValue /></SelectTrigger>
            <SelectContent>
                <SelectItem v-for="(l, key) in granularityLabels" :key="key" :value="key">{{ l }}</SelectItem>
            </SelectContent>
        </Select>
        <div class="flex items-center gap-1 rounded-lg border px-1 border-border">
            <Button variant="ghost" size="icon-sm" @click="step(-1)"><ChevronLeft class="h-4 w-4" /></Button>
            <span class="flex min-w-40 items-center justify-center gap-1.5 px-1 text-sm font-medium ui-text">
                <Calendar class="h-3.5 w-3.5 ui-subtle" />{{ label }}
            </span>
            <Button variant="ghost" size="icon-sm" @click="step(1)"><ChevronRight class="h-4 w-4" /></Button>
        </div>
    </div>
</template>
