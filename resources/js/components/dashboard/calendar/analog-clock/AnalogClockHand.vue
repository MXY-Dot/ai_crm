<script setup lang="ts">
// Pure presentational -- one hand pointing at `angle`. Reused twice by
// AnalogTimePicker (once for the hour hand, once for the minute hand, with
// different length/thickness) so there is no separate hour/minute file.
import { CLOCK_CENTER, CLOCK_RADIUS, pointOnCircle } from './analogClockMath';

const props = withDefaults(defineProps<{ angle: number; length?: number; thickness?: number }>(), {
    length: 0.6,
    thickness: 3,
});

const tip = () => pointOnCircle(props.angle, CLOCK_RADIUS * props.length);
</script>

<template>
    <line
        :x1="CLOCK_CENTER" :y1="CLOCK_CENTER"
        :x2="tip().x" :y2="tip().y"
        class="stroke-primary" :stroke-width="thickness" stroke-linecap="round"
    />
</template>
