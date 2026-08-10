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
  tone?: 'neutral' | 'green' | 'amber' | 'red' | 'blue' | 'telegram' | 'whatsapp' | 'instagram'
  class?: HTMLAttributes['class']
}>()

const delegatedProps = reactiveOmit(props, 'class', 'tone')
const toneClass = {
  neutral: 'border-transparent bg-secondary text-secondary-foreground font-semibold',
  green: 'border-transparent bg-emerald-600 text-white font-semibold',
  amber: 'border-transparent bg-amber-500 text-white font-semibold',
  red: 'border-transparent bg-destructive text-white font-semibold',
  blue: 'border-transparent bg-sky-600 text-white font-semibold',
  telegram: 'border-transparent bg-[#26A5E4] text-white font-semibold',
  whatsapp: 'border-transparent bg-[#25D366] text-white font-semibold',
  instagram: 'border-transparent bg-gradient-to-tr from-[#f09433] via-[#dc2743] to-[#bc1888] text-white font-semibold',
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
