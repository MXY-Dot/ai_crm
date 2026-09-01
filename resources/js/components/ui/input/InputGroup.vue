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
  <div class="relative flex items-center">
    <span
      v-if="$slots.prefix"
      class="pointer-events-none absolute left-0 top-0 z-10 flex h-full items-center border-r border-input px-2.5 text-xs font-semibold uppercase tracking-wide ui-subtle"
    >
      <slot name="prefix" />
    </span>
    <Input
      :model-value="modelValue"
      v-bind="$attrs"
      :class="cn($slots.prefix ? 'pl-14' : '', $slots.suffix ? 'pr-14' : '', props.class)"
      @update:model-value="onUpdate"
    />
    <span
      v-if="$slots.suffix"
      class="pointer-events-none absolute right-0 top-0 z-10 flex h-full items-center border-l border-input px-2.5 text-xs font-semibold uppercase tracking-wide ui-subtle"
    >
      <slot name="suffix" />
    </span>
  </div>
</template>
