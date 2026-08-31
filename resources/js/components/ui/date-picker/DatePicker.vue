<script setup lang="ts">
import { computed } from 'vue';
import { type DateValue, getLocalTimeZone, parseDate } from '@internationalized/date';
import { CalendarIcon } from '@lucide/vue';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useLocaleStore } from '@/stores/locale';

/**
 * The standard shadcn-vue Date Picker composition (Button + Popover +
 * Calendar) -- same primitives ChatStickyDate.vue already uses inline for
 * the inbox date-jump pill, pulled out here as a shared field so every plain
 * "pick one date into a form" spot in the app uses this instead of a native
 * <input type="date"> (inconsistent browser chrome, no design-system
 * styling, no locale control).
 *
 * modelValue is a plain "YYYY-MM-DD" string -- matches every existing
 * call site (all were already string refs bound to a native date input),
 * so swapping this in is a drop-in v-model replacement, no caller changes
 * beyond the template tag itself.
 */
const props = withDefaults(defineProps<{ class?: string; disabled?: boolean }>(), {
    disabled: false,
});

const modelValue = defineModel<string>({ default: '' });

const locale = useLocaleStore();

const selected = computed<DateValue | undefined>(() => (modelValue.value ? parseDate(modelValue.value) : undefined));

const label = computed(() => {
    if (! selected.value) return locale.t('common.pickDate');

    return selected.value.toDate(getLocalTimeZone()).toLocaleDateString(locale.locale === 'ru' ? 'ru-RU' : 'en-US', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
});

function onSelect(date: DateValue | undefined): void {
    modelValue.value = date ? date.toString() : '';
}
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="outline"
                :disabled="props.disabled"
                :class="cn('justify-start gap-2 font-normal', !selected && 'text-muted-foreground', props.class)"
            >
                <CalendarIcon class="h-4 w-4 shrink-0" />
                <span class="truncate">{{ label }}</span>
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0" align="start">
            <Calendar :model-value="selected" @update:model-value="onSelect" />
        </PopoverContent>
    </Popover>
</template>
