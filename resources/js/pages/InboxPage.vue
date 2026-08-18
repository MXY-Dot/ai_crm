<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import InboxWorkspace from '@/components/dashboard/InboxWorkspace.vue';

defineOptions({ layout: AppLayout });
</script>

<template>
    <InboxWorkspace />
</template>
