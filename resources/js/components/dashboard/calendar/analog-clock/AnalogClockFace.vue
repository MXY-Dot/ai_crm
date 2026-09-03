<script setup lang="ts">
// Pure dial background -- circle, minute ticks, hour numerals 1-12. Knows
// nothing about slots, hands or selection; AnalogTimePicker layers
// AnalogClockHand and AnalogClockSlotDots inside it via the default slot, in
// the same SVG coordinate space.
import { CLOCK_CENTER, CLOCK_RADIUS, CLOCK_SIZE, hourAngle, pointOnCircle } from './analogClockMath';

function tickLine(minuteOfHour: number): { x1: number; y1: number; x2: number; y2: number } {
    const angle = minuteOfHour * 6 - 90; // 60 marks around the circle, 6deg apart
    const isHourMark = minuteOfHour % 5 === 0;
    const outer = pointOnCircle(angle, CLOCK_RADIUS);
    const inner = pointOnCircle(angle, CLOCK_RADIUS - (isHourMark ? 10 : 5));
    return { x1: inner.x, y1: inner.y, x2: outer.x, y2: outer.y };
}

function labelPoint(hour: number): { x: number; y: number } {
    return pointOnCircle(hourAngle(hour, 0), CLOCK_RADIUS - 20);
}
</script>

<template>
    <svg :viewBox="`0 0 ${CLOCK_SIZE} ${CLOCK_SIZE}`" class="h-full w-full">
        <circle :cx="CLOCK_CENTER" :cy="CLOCK_CENTER" :r="CLOCK_RADIUS" class="fill-muted stroke-border" stroke-width="1.5" />
        <line
            v-for="mark in 60" :key="mark"
            v-bind="tickLine(mark - 1)"
            class="stroke-border"
            :stroke-width="(mark - 1) % 5 === 0 ? 1.5 : 1"
        />
        <text
            v-for="hour in 12" :key="`label-${hour}`"
            :x="labelPoint(hour).x" :y="labelPoint(hour).y"
            text-anchor="middle" dominant-baseline="middle"
            class="fill-current text-[10px] font-medium ui-subtle"
        >{{ hour }}</text>
        <slot />
        <circle :cx="CLOCK_CENTER" :cy="CLOCK_CENTER" r="3" class="fill-primary" />
    </svg>
</template>
