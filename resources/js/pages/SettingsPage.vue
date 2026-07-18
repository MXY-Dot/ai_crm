<script setup lang="ts">
import IntegrationHealthPanel from '../components/dashboard/IntegrationHealthPanel.vue';
import OperatorDemoFlowPanel from '../components/dashboard/OperatorDemoFlowPanel.vue';
import TenantUsersPanel from '../components/dashboard/TenantUsersPanel.vue';
</script>

<template>
    <section class="space-y-6">
        <IntegrationHealthPanel />
        <OperatorDemoFlowPanel />
        <TenantUsersPanel />
    </section>
</template>