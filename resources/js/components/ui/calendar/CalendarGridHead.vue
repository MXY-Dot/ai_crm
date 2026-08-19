<script lang="ts" setup>
import type { CalendarGridHeadProps } from 'reka-ui'
import { CalendarGridHead } from 'reka-ui'

const props = defineProps</* @vue-ignore */ CalendarGridHeadProps>()
</script>

<template>
  <CalendarGridHead v-bind="props">
    <slot />
  </CalendarGridHead>
</template>
