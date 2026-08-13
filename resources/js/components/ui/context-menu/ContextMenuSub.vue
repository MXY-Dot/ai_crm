<script setup lang="ts">
import type { ContextMenuSubEmits, ContextMenuSubProps } from 'reka-ui'
import {
  ContextMenuSub,
  useForwardPropsEmits,
} from 'reka-ui'

const props = defineProps</* @vue-ignore */ContextMenuSubProps>()
const emits = defineEmits</* @vue-ignore */ContextMenuSubEmits>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <ContextMenuSub
    data-slot="context-menu-sub"
    v-bind="forwarded"
  >
    <slot />
  </ContextMenuSub>
</template>
