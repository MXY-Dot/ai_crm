<script setup lang="ts">
defineProps<{ src: string }>();
</script>

<template>
    <video :src="src" controls preload="metadata" class="mt-1 max-h-72 max-w-56 rounded-lg bg-black" />
</template>
