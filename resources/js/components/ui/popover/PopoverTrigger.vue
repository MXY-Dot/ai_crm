<script setup lang="ts">
import type { PopoverTriggerProps } from 'reka-ui'
import { PopoverTrigger } from 'reka-ui'

const props = defineProps</* @vue-ignore */ PopoverTriggerProps>()
</script>

<template>
  <PopoverTrigger
    data-slot="popover-trigger"
    v-bind="props"
  >
    <slot />
  </PopoverTrigger>
</template>
