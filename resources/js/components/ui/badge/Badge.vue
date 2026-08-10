<script setup lang="ts">
import type { PrimitiveProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import type { BadgeVariants } from '.'
import { computed } from 'vue'
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
const TONE_CLASSES: Record<string, string> = {
  neutral: 'border-transparent bg-secondary text-secondary-foreground font-semibold',
  green: 'border-transparent bg-emerald-600/20 text-emerald-700 dark:text-emerald-300 font-semibold',
  amber: 'border-transparent bg-amber-600/20 text-amber-700 dark:text-amber-300 font-semibold',
  red: 'border-transparent bg-destructive/20 text-destructive font-semibold',
  blue: 'border-transparent bg-sky-600/20 text-sky-700 dark:text-sky-300 font-semibold',
  telegram: 'border-transparent bg-brand-telegram/20 text-brand-telegram font-semibold',
  whatsapp: 'border-transparent bg-brand-whatsapp/20 text-brand-whatsapp font-semibold',
  instagram: 'border-transparent bg-brand-instagram-to/20 text-brand-instagram-to font-semibold',
}
const toneClass = computed(() => (props.tone ? TONE_CLASSES[props.tone] : undefined))
</script>

<template>
  <Primitive
    data-slot="badge"
    :data-variant="variant"
    :class="cn(badgeVariants({ variant }), toneClass, props.class)"
    v-bind="delegatedProps"
  >
    <slot />
  </Primitive>
</template>
