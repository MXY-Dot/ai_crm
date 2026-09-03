<script setup lang="ts" generic="T extends { starts_at: string }">
// The "smart" piece -- everything domain-specific (matching real slot
// objects, disambiguating same-time slots from different resources) lives
// here; AnalogClockFace/Hand/SlotDots stay pure presentation. Generic over
// T so both NewBookingDialog's {employee_id, employee_name, ...} slots and
// NewTableReservationDialog's {resource_id, resource_name, capacity, ...}
// slots use the exact same component -- callers just supply how to label a
// slot via `resourceLabel`.
import { computed, ref, watch } from 'vue';
import { useLocaleStore } from '../../../../stores/locale';
import AnalogClockFace from './AnalogClockFace.vue';
import AnalogClockHand from './AnalogClockHand.vue';
import AnalogClockSlotDots, { type ClockPoint } from './AnalogClockSlotDots.vue';
import { angleForTime, formatHourMinute, timeKey } from './analogClockMath';

const props = defineProps<{
    slots: T[];
    modelValue: T | null;
    loading?: boolean;
    resourceLabel: (slot: T) => string;
}>();
const emit = defineEmits<{ 'update:modelValue': [T | null] }>();
const locale = useLocaleStore();

const selectedTimeKey = ref<string | null>(null);

watch(() => props.modelValue, (value) => {
    selectedTimeKey.value = value ? timeKey(value.starts_at) : null;
}, { immediate: true });

// Several resources (tables, specialists) can offer the exact same
// time-of-day -- the clock face can only show one point for that time, so
// slots are grouped by it; picking a point with more than one candidate
// needs an explicit second choice below instead of guessing which one.
const groupedByTime = computed(() => {
    const map = new Map<string, T[]>();
    for (const slot of props.slots) {
        const key = timeKey(slot.starts_at);
        const group = map.get(key);
        if (group) group.push(slot);
        else map.set(key, [slot]);
    }
    return map;
});

const points = computed<ClockPoint[]>(() => Array.from(groupedByTime.value.entries()).map(([key, group]) => {
    const d = new Date(group[0].starts_at);
    return { key, angle: angleForTime(d.getHours(), d.getMinutes()), selected: key === selectedTimeKey.value };
}));

const selectedAngle = computed(() => points.value.find((p) => p.selected)?.angle ?? null);

const candidatesForSelectedTime = computed(() => (
    selectedTimeKey.value ? groupedByTime.value.get(selectedTimeKey.value) ?? [] : []
));

function selectTime(key: string): void {
    selectedTimeKey.value = key;
    const group = groupedByTime.value.get(key) ?? [];
    emit('update:modelValue', group.length === 1 ? group[0] : null);
}
</script>

<template>
    <div class="flex flex-col items-center gap-3">
        <p v-if="loading" class="text-xs ui-subtle">{{ locale.t('calendar.loading') }}</p>
        <p v-else-if="! slots.length" class="text-xs ui-subtle">{{ locale.t('booking.noSlots') }}</p>
        <template v-else>
            <div class="w-full max-w-[13rem]">
                <AnalogClockFace>
                    <AnalogClockHand :angle="selectedAngle" />
                    <AnalogClockSlotDots :points="points" @select="selectTime" />
                </AnalogClockFace>
            </div>

            <div v-if="candidatesForSelectedTime.length > 1" class="flex flex-wrap justify-center gap-1.5">
                <button
                    v-for="slot in candidatesForSelectedTime" :key="resourceLabel(slot)"
                    type="button"
                    class="rounded-md border px-2 py-1 text-xs font-medium transition"
                    :class="modelValue === slot ? 'border-primary bg-primary/10 text-primary' : 'border-border ui-text hover:border-primary/30'"
                    @click="emit('update:modelValue', slot)"
                >{{ resourceLabel(slot) }}</button>
            </div>

            <p v-if="modelValue" class="text-xs font-semibold tabular-nums ui-text">
                {{ formatHourMinute(modelValue.starts_at) }} · {{ resourceLabel(modelValue) }}
            </p>
        </template>
    </div>
</template>
