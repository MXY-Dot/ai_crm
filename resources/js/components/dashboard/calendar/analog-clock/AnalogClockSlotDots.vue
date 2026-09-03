<script setup lang="ts">
// Pure presentational -- renders one clickable dot per available time and
// reports back only the clicked point's `key`. AnalogTimePicker owns turning
// that key back into real slot data; this component never sees slot objects.
import { CLOCK_RADIUS, pointOnCircle } from './analogClockMath';

export type ClockPoint = { key: string; angle: number; selected: boolean };

defineProps<{ points: ClockPoint[] }>();
const emit = defineEmits<{ select: [key: string] }>();
</script>

<template>
    <g>
        <circle
            v-for="point in points" :key="point.key"
            :cx="pointOnCircle(point.angle).x" :cy="pointOnCircle(point.angle).y"
            :r="point.selected ? 6 : 4.5"
            :stroke-width="point.selected ? 1.5 : 0"
            class="cursor-pointer transition-all"
            :class="point.selected ? 'fill-primary stroke-primary-foreground' : 'fill-primary/40 hover:fill-primary/70'"
            @click="emit('select', point.key)"
        />
    </g>
</template>
