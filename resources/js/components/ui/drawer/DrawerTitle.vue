<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { DrawerTitle } from 'vaul-vue'
import { cn } from '@/lib/utils'

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<{ class?: HTMLAttributes['class'] }>()
</script>

<template>
  <DrawerTitle
    data-slot="drawer-title"
    v-bind="$attrs"
    :class="cn('font-display text-base font-semibold ui-text', props.class)"
  >
    <slot />
  </DrawerTitle>
</template>
