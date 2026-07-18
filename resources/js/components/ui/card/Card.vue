<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = withDefaults(defineProps<{
  class?: HTMLAttributes['class']
  size?: 'default' | 'sm'
  title?: string
  subtitle?: string
}>(), {
  size: 'default',
})
</script>

<template>
  <div
    data-slot="card"
    :data-size="size"
    :class="cn('ring-foreground/10 bg-card text-card-foreground gap-4 overflow-hidden rounded-xl py-4 text-sm ring-1 has-data-[slot=card-footer]:pb-0 has-[>img:first-child]:pt-0 data-[size=sm]:gap-3 data-[size=sm]:py-3 data-[size=sm]:has-data-[slot=card-footer]:pb-0 *:[img:first-child]:rounded-t-xl *:[img:last-child]:rounded-b-xl group/card flex flex-col', props.class)"
  >
    <div v-if="title || subtitle || $slots.actions" class="flex items-start justify-between gap-4 border-b border-border px-4 pb-3">
      <div>
        <h2 v-if="title" class="text-base font-semibold text-card-foreground">{{ title }}</h2>
        <p v-if="subtitle" class="mt-1 text-sm text-muted-foreground">{{ subtitle }}</p>
      </div>
      <slot name="actions" />
    </div>
    <div class="px-4">
      <slot />
    </div>
  </div>
</template>
