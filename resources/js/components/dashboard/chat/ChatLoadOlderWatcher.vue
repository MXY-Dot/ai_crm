<script setup lang="ts">
import { watch } from 'vue';
import { useMessageScrollerScrollable } from '@/components/ui/message-scroller';

/**
 * Renderless helper: `useMessageScrollerScrollable()` reads state provided by
 * `MessageScrollerProvider` via Vue's provide/inject, which only flows down to
 * descendants — the parent that renders `<MessageScrollerProvider>` in its own
 * template can't call the composable itself, so this tiny component exists to
 * sit *inside* the provider's tree and forward the "user scrolled near the top"
 * signal back up as a plain event.
 */
const props = defineProps<{ conversationId: number | null }>();
const emit = defineEmits<{ 'load-older': [] }>();

const scrollable = useMessageScrollerScrollable();

watch(() => scrollable.value.start, (canScrollToStart) => {
    if (! canScrollToStart && props.conversationId) emit('load-older');
});
</script>

<template></template>
