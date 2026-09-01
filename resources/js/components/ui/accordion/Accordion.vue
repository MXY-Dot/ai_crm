<script setup lang="ts">
import type { AccordionRootProps } from 'reka-ui'
import { AccordionRoot, useForwardPropsEmits } from 'reka-ui'

const props = defineProps</* @vue-ignore */ AccordionRootProps>()
const emits = defineEmits()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <AccordionRoot data-slot="accordion" v-bind="forwarded">
    <slot />
  </AccordionRoot>
</template>
