<script setup lang="ts">
import type { MessageScrollerProviderProps } from './useMessageScroller'
import { provideMessageScroller } from './useMessageScroller'

const props = defineProps</* @vue-ignore */ MessageScrollerProviderProps>()

provideMessageScroller(props)
</script>

<template>
  <slot />
</template>
