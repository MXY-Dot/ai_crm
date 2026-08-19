<script lang="ts" setup>
import type { CalendarGridBodyProps } from 'reka-ui'
import { CalendarGridBody } from 'reka-ui'

const props = defineProps</* @vue-ignore */ CalendarGridBodyProps>()
</script>

<template>
  <CalendarGridBody v-bind="props">
    <slot />
  </CalendarGridBody>
</template>
