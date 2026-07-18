<script setup lang="ts">
import type { PrimitiveProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import type { BadgeVariants } from '.'
import { reactiveOmit } from '@vueuse/core'
import { Primitive } from 'reka-ui'
import { cn } from '@/lib/utils'
import { badgeVariants } from '.'

const props = defineProps</* @vue-ignore */ PrimitiveProps & {
  variant?: BadgeVariants['variant']
  tone?: 'neutral' | 'green' | 'amber' | 'red' | 'blue'
  class?: HTMLAttributes['class']
}>()

const delegatedProps = reactiveOmit(props, 'class', 'tone')
const toneClass = {
  neutral: 'border-border bg-muted text-muted-foreground',
  green: 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
  amber: 'border-amber-500/25 bg-amber-500/10 text-amber-700 dark:text-amber-300',
  red: 'border-destructive/25 bg-destructive/10 text-destructive',
  blue: 'border-sky-500/25 bg-sky-500/10 text-sky-700 dark:text-sky-300',
}[props.tone || '']
</script>

<template>
  <Primitive
    data-slot="badge"
    :data-variant="variant"
    :class="cn(toneClass || badgeVariants({ variant }), props.class)"
    v-bind="delegatedProps"
  >
    <slot />
  </Primitive>
</template>
