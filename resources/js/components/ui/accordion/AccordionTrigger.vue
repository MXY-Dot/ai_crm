<script setup lang="ts">
import type { AccordionTriggerProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import { ChevronDown } from '@lucide/vue'
import { AccordionHeader, AccordionTrigger, useForwardProps } from 'reka-ui'
import { cn } from '@/lib/utils'

const props = defineProps</* @vue-ignore */ AccordionTriggerProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = reactiveOmit(props, 'class')
const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <AccordionHeader class="flex">
    <AccordionTrigger
      data-slot="accordion-trigger"
      v-bind="forwardedProps"
      :class="cn(
        'flex flex-1 items-center justify-between gap-4 text-left text-sm font-medium outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 [&[data-state=open]>svg]:rotate-180',
        props.class,
      )"
    >
      <slot />
      <ChevronDown class="h-4 w-4 shrink-0 ui-subtle transition-transform duration-200" />
    </AccordionTrigger>
  </AccordionHeader>
</template>
