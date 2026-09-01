<script setup lang="ts">
import type { TabsRootEmits, TabsRootProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import { TabsRoot, useForwardPropsEmits } from 'reka-ui'
import { cn } from '@/lib/utils'

// unmountOnHide defaults to false here (reka-ui's own default is true) -- with
// it true, switching away from a tab and back fully unmounts+remounts that
// panel, re-running its onMounted() data fetch and flashing its loading
// skeleton again for data that was already loaded a moment ago. Keeping
// inactive panels mounted (just hidden) fixes that jank everywhere Tabs is
// used, in one place, without touching every panel's own load() logic.
const props = withDefaults(defineProps</* @vue-ignore */ TabsRootProps & { class?: HTMLAttributes['class'] }>(), {
  unmountOnHide: false,
})
const emits = defineEmits()

const delegatedProps = reactiveOmit(props, 'class')
const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <TabsRoot
    v-slot="slotProps"
    data-slot="tabs"
    :data-orientation="forwarded.orientation || 'horizontal'"
    v-bind="forwarded"
    :class="cn('gap-2 group/tabs flex data-[orientation=horizontal]:flex-col', props.class)"
  >
    <slot v-bind="slotProps" />
  </TabsRoot>
</template>
