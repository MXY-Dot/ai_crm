<script setup lang="ts">
// Pure dial background -- the circle, hour ticks and numerals. Knows nothing
// about slots or selection; AnalogTimePicker layers AnalogClockHand and
// AnalogClockSlotDots inside it via the default slot, in the same SVG
// coordinate space.
import { angleForTime, CLOCK_CENTER, CLOCK_RADIUS, CLOCK_SIZE, pointOnCircle } from './analogClockMath';

const HOUR_LABELS = [0, 4, 8, 12, 16, 20];

function tickLine(hour: number): { x1: number; y1: number; x2: number; y2: number } {
    const angle = angleForTime(hour, 0);
    const isMajor = hour % 4 === 0;
    const outer = pointOnCircle(angle, CLOCK_RADIUS);
    const inner = pointOnCircle(angle, CLOCK_RADIUS - (isMajor ? 10 : 5));
    return { x1: inner.x, y1: inner.y, x2: outer.x, y2: outer.y };
}

function labelPoint(hour: number): { x: number; y: number } {
    return pointOnCircle(angleForTime(hour, 0), CLOCK_RADIUS - 20);
}
</script>

<template>
    <svg :viewBox="`0 0 ${CLOCK_SIZE} ${CLOCK_SIZE}`" class="h-full w-full">
        <circle :cx="CLOCK_CENTER" :cy="CLOCK_CENTER" :r="CLOCK_RADIUS" class="fill-muted stroke-border" stroke-width="1.5" />
        <line
            v-for="hour in 24" :key="hour"
            v-bind="tickLine(hour - 1)"
            class="stroke-border"
            :stroke-width="(hour - 1) % 4 === 0 ? 1.5 : 1"
        />
        <text
            v-for="hour in HOUR_LABELS" :key="`label-${hour}`"
            :x="labelPoint(hour).x" :y="labelPoint(hour).y"
            text-anchor="middle" dominant-baseline="middle"
            class="fill-current text-[9px] font-medium ui-subtle"
        >{{ hour === 0 ? '24' : hour }}</text>
        <circle :cx="CLOCK_CENTER" :cy="CLOCK_CENTER" r="2.5" class="fill-border" />
        <slot />
    </svg>
</template>
