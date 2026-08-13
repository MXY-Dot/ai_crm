<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { DrawerOverlay } from 'vaul-vue'
import { cn } from '@/lib/utils'

const props = defineProps<{ class?: HTMLAttributes['class'] }>()
</script>

<template>
  <DrawerOverlay
    data-slot="drawer-overlay"
    :class="cn(
      'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/40',
      props.class,
    )"
  />
</template>
