<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import Input from './Input.vue'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  modelValue?: string | number
  modelModifiers?: { number?: boolean }
  class?: HTMLAttributes['class']
}>()

const emit = defineEmits<{ (e: 'update:modelValue', payload: string | number): void }>()

function onUpdate(value: string | number): void {
  if (props.modelModifiers?.number) {
    const n = typeof value === 'string' ? parseFloat(value) : value
    emit('update:modelValue', Number.isNaN(n) ? 0 : n)
    return
  }
  emit('update:modelValue', value)
}
</script>

<template>
  <div
    class="dark:bg-input/30 border-input focus-within:border-ring focus-within:ring-ring/50 flex h-8 items-stretch overflow-hidden rounded-lg border bg-transparent transition-colors focus-within:ring-3 lg:h-10"
  >
    <span
      v-if="$slots.prefix"
      class="border-input flex shrink-0 items-center border-r px-2.5 text-xs font-semibold uppercase tracking-wide ui-subtle"
    >
      <slot name="prefix" />
    </span>
    <Input
      :model-value="modelValue"
      v-bind="$attrs"
      :class="cn('h-full flex-1 rounded-none border-0 bg-transparent shadow-none focus-visible:border-transparent focus-visible:ring-0', props.class)"
      @update:model-value="onUpdate"
    />
    <span
      v-if="$slots.suffix"
      class="border-input flex shrink-0 items-center border-l px-2.5 text-xs font-semibold uppercase tracking-wide ui-subtle"
    >
      <slot name="suffix" />
    </span>
  </div>
</template>
