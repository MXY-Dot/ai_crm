<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { DrawerDescription } from 'vaul-vue'
import { cn } from '@/lib/utils'

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<{ class?: HTMLAttributes['class'] }>()
</script>

<template>
  <DrawerDescription
    data-slot="drawer-description"
    v-bind="$attrs"
    :class="cn('text-sm text-muted-foreground', props.class)"
  >
    <slot />
  </DrawerDescription>
</template>
