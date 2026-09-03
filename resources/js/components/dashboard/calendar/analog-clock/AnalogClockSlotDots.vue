<script setup lang="ts">
// Pure presentational -- draws one dot per available time. No click handling
// of its own: AnalogTimePicker's own pointerdown/pointermove on the whole
// face already finds the nearest dot (that's what makes both "click a dot"
// and "drag toward it" the same code path), so these stay pointer-events:none
// and never see real slot data, only precomputed {key, angle, selected}.
import { pointOnCircle } from './analogClockMath';

export type ClockPoint = { key: string; angle: number; selected: boolean };

defineProps<{ points: ClockPoint[] }>();
</script>

<template>
    <g>
        <circle
            v-for="point in points" :key="point.key"
            :cx="pointOnCircle(point.angle).x" :cy="pointOnCircle(point.angle).y"
            :r="point.selected ? 6 : 4"
            :stroke-width="point.selected ? 1.5 : 0"
            class="pointer-events-none transition-all"
            :class="point.selected ? 'fill-primary stroke-primary-foreground' : 'fill-primary/40'"
        />
    </g>
</template>
