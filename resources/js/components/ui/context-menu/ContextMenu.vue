<script setup lang="ts">
import type { ContextMenuRootEmits, ContextMenuRootProps } from 'reka-ui'
import { ContextMenuRoot, useForwardPropsEmits } from 'reka-ui'

const props = defineProps</* @vue-ignore */ContextMenuRootProps>()
const emits = defineEmits</* @vue-ignore */ContextMenuRootEmits>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <ContextMenuRoot
    data-slot="context-menu"
    v-bind="forwarded"
  >
    <slot />
  </ContextMenuRoot>
</template>
