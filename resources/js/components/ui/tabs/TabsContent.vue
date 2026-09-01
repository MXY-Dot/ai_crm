<script setup lang="ts">
import type { TabsContentProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import { TabsContent } from 'reka-ui'
import { cn } from '@/lib/utils'

const props = defineProps</* @vue-ignore */ TabsContentProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = reactiveOmit(props, 'class')
</script>

<template>
  <TabsContent
    data-slot="tabs-content"
    :class="cn(
      'text-sm flex-1 min-w-0 outline-none',
      // Every panel shares the same grid cell (row 2, under TabsList's row 1) instead of
      // stacking in normal flow -- during the crossfade, reka-ui keeps the outgoing panel
      // present (not display:none) until its exit animation finishes, so for ~200ms both
      // the old and new panel are actually in the DOM at once. In normal flow that doubles
      // the content height for that instant and shoves everything below the tabs down and
      // back up -- the exact jank reported. Overlapping them in one cell means the height
      // is just whichever panel is tallest, with no push.
      'group-data-horizontal/tabs:col-start-1 group-data-horizontal/tabs:row-start-2',
      'data-[state=active]:animate-in data-[state=active]:fade-in-0 data-[state=active]:slide-in-from-bottom-1',
      'data-[state=inactive]:animate-out data-[state=inactive]:fade-out-0',
      'duration-200',
      props.class,
    )"
    v-bind="delegatedProps"
  >
    <slot />
  </TabsContent>
</template>
