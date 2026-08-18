<script setup lang="ts">
import { defineAsyncComponent } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import InboxWorkspace from '@/components/dashboard/InboxWorkspace.vue';
import { advancedChatEnabled } from '@/lib/chat/advancedChatFlag';

defineOptions({ layout: AppLayout });

// Dynamic import so @advanced-chat/components (and its CSS) is only ever
// fetched when the flag is on — never bundled into the default experience.
const InboxWorkspaceAdvanced = defineAsyncComponent(() => import('@/components/dashboard/InboxWorkspaceAdvanced.vue'));
</script>

<template>
    <InboxWorkspaceAdvanced v-if="advancedChatEnabled" />
    <InboxWorkspace v-else />
</template>