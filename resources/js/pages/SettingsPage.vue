<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import IntegrationHealthPanel from '../components/dashboard/IntegrationHealthPanel.vue';
import OperatorDemoFlowPanel from '../components/dashboard/OperatorDemoFlowPanel.vue';
import TenantUsersPanel from '../components/dashboard/TenantUsersPanel.vue';

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-6">
        <IntegrationHealthPanel />
        <OperatorDemoFlowPanel />
        <TenantUsersPanel />
    </section>
</template>