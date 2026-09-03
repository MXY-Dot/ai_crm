<script setup lang="ts">
// Pure presentational -- just draws a hand pointing at `angle`, or nothing
// when no time is selected yet. Has no idea what a "slot" is.
import { CLOCK_CENTER, CLOCK_RADIUS, pointOnCircle } from './analogClockMath';

const props = defineProps<{ angle: number | null }>();

const tip = () => pointOnCircle(props.angle ?? 0, CLOCK_RADIUS * 0.72);
</script>

<template>
    <g v-if="angle !== null">
        <line
            :x1="CLOCK_CENTER" :y1="CLOCK_CENTER"
            :x2="tip().x" :y2="tip().y"
            class="stroke-primary" stroke-width="2.5" stroke-linecap="round"
        />
        <circle :cx="CLOCK_CENTER" :cy="CLOCK_CENTER" r="4" class="fill-primary" />
    </g>
</template>
