<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import { useForwardProps } from 'reka-ui'
import { computed } from 'vue'
import { useVueOTPContext } from 'vue-input-otp'
import { cn } from '@/lib/utils'
import { type InputOTPSlotVariants, inputOTPSlotVariants } from '.'

const props = defineProps<{ index: number, size?: InputOTPSlotVariants['size'], class?: HTMLAttributes['class'] }>()

const delegatedProps = reactiveOmit(props, 'class', 'size')

const forwarded = useForwardProps(delegatedProps)

const context = useVueOTPContext()

const slot = computed(() => context?.value.slots[props.index])
</script>

<template>
  <div
    v-bind="forwarded"
    data-slot="input-otp-slot"
    :data-active="slot?.isActive"
    :class="cn(inputOTPSlotVariants({ size }), props.class)"
  >
    {{ slot?.char }}
    <div v-if="slot?.hasFakeCaret" class="pointer-events-none absolute inset-0 flex items-center justify-center">
      <div class="animate-caret-blink bg-foreground h-4 w-px duration-1000" />
    </div>
  </div>
</template>
