<script setup lang="ts" generic="T extends { starts_at: string }">
// The domain-aware piece -- everything about matching real availability
// slots to a draggable 12-hour clock face lives here. AnalogClockFace/Hand/
// SlotDots stay pure presentation. Generic over T so both NewBookingDialog's
// {employee_id, employee_name, ...} slots and NewTableReservationDialog's
// {resource_id, resource_name, capacity, ...} slots use the exact same
// component -- callers just supply how to label a slot via `resourceLabel`.
import { computed, ref, watch } from 'vue';
import { useLocaleStore } from '../../../../stores/locale';
import { ContextMenu, ContextMenuContent, ContextMenuTrigger } from '../../../ui/context-menu';
import AnalogClockFace from './AnalogClockFace.vue';
import AnalogClockHand from './AnalogClockHand.vue';
import AnalogClockSlotDots, { type ClockPoint } from './AnalogClockSlotDots.vue';
import { angularDistance, formatHourMinute, hourAngle, minuteAngle, timeKey } from './analogClockMath';

const props = defineProps<{
    slots: T[];
    modelValue: T | null;
    loading?: boolean;
    resourceLabel: (slot: T) => string;
}>();
const emit = defineEmits<{ 'update:modelValue': [T | null] }>();
const locale = useLocaleStore();

const hour24 = ref(9);
const minute = ref(0);
const isPM = ref(false);
const dragging = ref(false);
const faceEl = ref<HTMLElement | null>(null);

// Every real slot grouped by exact "H:M" -- the source of truth for which
// AM/PM half has anything in it, and for matching a typed time (native
// <input type="time"> is always 24-hour, so there's no AM/PM ambiguity there
// at all, unlike a raw click on the 12-hour face).
const allByTime = computed(() => {
    const map = new Map<string, T[]>();
    for (const slot of props.slots) {
        const key = timeKey(slot.starts_at);
        const group = map.get(key);
        if (group) group.push(slot);
        else map.set(key, [slot]);
    }
    return map;
});

const hasMorning = computed(() => [...allByTime.value.keys()].some((key) => Number(key.split(':')[0]) < 12));
const hasAfternoon = computed(() => [...allByTime.value.keys()].some((key) => Number(key.split(':')[0]) >= 12));
const showHalfToggle = computed(() => hasMorning.value && hasAfternoon.value);

// A 12-hour face can't show e.g. 08:00 and 20:00 as different points, so
// only the currently active half's slots ever become clickable/draggable
// points -- switching AM/PM is a separate, explicit choice below rather than
// something a drag could stumble into.
const pointsByTime = computed(() => {
    const map = new Map<string, T[]>();
    for (const [key, group] of allByTime.value) {
        if ((Number(key.split(':')[0]) >= 12) === isPM.value) map.set(key, group);
    }
    return map;
});

const points = computed<ClockPoint[]>(() => Array.from(pointsByTime.value.entries()).map(([key, group]) => {
    const [h, m] = key.split(':').map(Number);
    return { key, angle: hourAngle(h, m), selected: h === hour24.value && m === minute.value };
}));

const candidatesForSelected = computed(() => allByTime.value.get(`${hour24.value}:${minute.value}`) ?? []);

function syncFromSlot(slot: T): void {
    const d = new Date(slot.starts_at);
    hour24.value = d.getHours();
    minute.value = d.getMinutes();
    isPM.value = d.getHours() >= 12;
}

// A hand is visible the moment slots exist, defaulting to the earliest one
// -- not only after the user makes their first click, so there's always
// something concrete on the face to look at and drag from.
watch(() => props.slots, (list) => {
    if (list.length) syncFromSlot(props.modelValue ?? list[0]);
}, { immediate: true });

watch(() => props.modelValue, (value) => { if (value) syncFromSlot(value); });

function applyKey(key: string): void {
    const [h, m] = key.split(':').map(Number);
    hour24.value = h;
    minute.value = m;
    const group = allByTime.value.get(key) ?? [];
    emit('update:modelValue', group.length === 1 ? group[0] : null);
}

function selectHalf(pm: boolean): void {
    isPM.value = pm;
    const firstInHalf = points.value[0];
    if (firstInHalf) applyKey(firstInHalf.key);
}

function angleFromPointer(event: PointerEvent): number | null {
    if (! faceEl.value) return null;
    const rect = faceEl.value.getBoundingClientRect();
    const dx = event.clientX - (rect.left + rect.width / 2);
    const dy = event.clientY - (rect.top + rect.height / 2);
    return (Math.atan2(dy, dx) * 180) / Math.PI;
}

// The heart of "drag anywhere but only ever land on a real slot": find the
// point whose angle the pointer is currently closest to and adopt its exact
// time, rather than computing an arbitrary time from the raw angle.
function snapToNearestPoint(event: PointerEvent): void {
    const angle = angleFromPointer(event);
    if (angle === null || ! points.value.length) return;

    let nearest = points.value[0];
    let nearestDiff = angularDistance(angle, nearest.angle);
    for (const point of points.value.slice(1)) {
        const diff = angularDistance(angle, point.angle);
        if (diff < nearestDiff) {
            nearest = point;
            nearestDiff = diff;
        }
    }
    applyKey(nearest.key);
}

function onPointerDown(event: PointerEvent): void {
    if (! points.value.length) return;
    dragging.value = true;
    (event.currentTarget as Element).setPointerCapture?.(event.pointerId);
    snapToNearestPoint(event);
}

function onPointerMove(event: PointerEvent): void {
    if (dragging.value) snapToNearestPoint(event);
}

function onPointerUp(): void {
    dragging.value = false;
}

const hourHandAngle = computed(() => hourAngle(hour24.value, minute.value));
const minuteHandAngle = computed(() => minuteAngle(minute.value));

// The context-menu's typed-time fallback: land on an exact match if one
// exists, otherwise the closest real slot across the whole day (searching
// both halves, since a typed 24-hour value has no AM/PM ambiguity to begin
// with) -- never silently accept a time nothing backs.
const timeInput = computed({
    get: () => `${String(hour24.value).padStart(2, '0')}:${String(minute.value).padStart(2, '0')}`,
    set: (value: string) => {
        const [h, m] = value.split(':').map(Number);
        if (Number.isNaN(h) || Number.isNaN(m)) return;

        const exactKey = `${h}:${m}`;
        if (allByTime.value.has(exactKey)) {
            isPM.value = h >= 12;
            applyKey(exactKey);
            return;
        }

        let nearestKey: string | null = null;
        let nearestDiff = Infinity;
        for (const key of allByTime.value.keys()) {
            const [kh, km] = key.split(':').map(Number);
            const diff = Math.abs(kh * 60 + km - (h * 60 + m));
            if (diff < nearestDiff) {
                nearestDiff = diff;
                nearestKey = key;
            }
        }
        if (nearestKey) {
            isPM.value = Number(nearestKey.split(':')[0]) >= 12;
            applyKey(nearestKey);
        }
    },
});
</script>

<template>
    <div class="flex flex-col items-center gap-3">
        <p v-if="loading" class="text-xs ui-subtle">{{ locale.t('calendar.loading') }}</p>
        <p v-else-if="! slots.length" class="text-xs ui-subtle">{{ locale.t('booking.noSlots') }}</p>
        <template v-else>
            <div v-if="showHalfToggle" class="flex gap-1 rounded-lg border border-border p-0.5 text-xs font-semibold">
                <button
                    type="button" class="rounded-md px-2.5 py-1 transition"
                    :class="! isPM ? 'bg-primary text-primary-foreground' : 'ui-subtle hover:bg-muted'"
                    @click="selectHalf(false)"
                >AM</button>
                <button
                    type="button" class="rounded-md px-2.5 py-1 transition"
                    :class="isPM ? 'bg-primary text-primary-foreground' : 'ui-subtle hover:bg-muted'"
                    @click="selectHalf(true)"
                >PM</button>
            </div>

            <ContextMenu>
                <ContextMenuTrigger as-child>
                    <div
                        ref="faceEl"
                        class="w-full max-w-[13rem] cursor-grab touch-none select-none active:cursor-grabbing"
                        @pointerdown="onPointerDown"
                        @pointermove="onPointerMove"
                        @pointerup="onPointerUp"
                        @pointerleave="onPointerUp"
                    >
                        <AnalogClockFace>
                            <AnalogClockHand :angle="hourHandAngle" :length="0.55" :thickness="3.5" />
                            <AnalogClockHand :angle="minuteHandAngle" :length="0.82" :thickness="2" />
                            <AnalogClockSlotDots :points="points" />
                        </AnalogClockFace>
                    </div>
                </ContextMenuTrigger>
                <ContextMenuContent class="w-48 p-3">
                    <p class="mb-2 text-xs font-medium ui-subtle">{{ locale.t('calendar.enterTimeManually') }}</p>
                    <input
                        v-model="timeInput"
                        type="time" step="900"
                        class="w-full rounded-md border border-border bg-background px-2 py-1.5 text-sm ui-text"
                    />
                </ContextMenuContent>
            </ContextMenu>

            <div v-if="candidatesForSelected.length > 1" class="flex flex-wrap justify-center gap-1.5">
                <button
                    v-for="slot in candidatesForSelected" :key="resourceLabel(slot)"
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
