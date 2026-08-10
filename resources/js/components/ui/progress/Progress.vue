<script setup lang="ts">
import type { ProgressRootProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import {
  ProgressIndicator,
  ProgressRoot,
} from 'reka-ui'
import { cn } from '@/lib/utils'

const props = withDefaults(
  defineProps</* @vue-ignore */ ProgressRootProps & { class?: HTMLAttributes['class'] }>(),
  {
    modelValue: 0,
  },
)

const delegatedProps = reactiveOmit(props, 'class')
</script>

<template>
  <ProgressRoot
    data-slot="progress"
    v-bind="delegatedProps"
    :class="
      cn(
        'bg-muted border border-border h-2.5 w-full overflow-hidden rounded-full relative',
        props.class,
      )
    "
  >
    <ProgressIndicator
      data-slot="progress-indicator"
      class="bg-primary block h-full rounded-full transition-[width]"
      :style="{ width: `${Math.max(0, Math.min(100, props.modelValue ?? 0))}%` }"
    />
  </ProgressRoot>
</template>
