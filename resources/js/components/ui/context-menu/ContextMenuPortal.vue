<script setup lang="ts">
import type { ContextMenuPortalProps } from 'reka-ui'
import { ContextMenuPortal } from 'reka-ui'

const props = defineProps</* @vue-ignore */ContextMenuPortalProps>()
</script>

<template>
  <ContextMenuPortal
    data-slot="context-menu-portal"
    v-bind="props"
  >
    <slot />
  </ContextMenuPortal>
</template>
