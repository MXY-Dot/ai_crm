<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import CompanyProfilePanel from '../components/dashboard/CompanyProfilePanel.vue';
import NotificationPreferencesPanel from '../components/dashboard/NotificationPreferencesPanel.vue';
import TenantUsersPanel from '../components/dashboard/TenantUsersPanel.vue';

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">Настройки компании</h2>
            <p class="mt-1 text-sm ui-subtle">Управляйте общими параметрами и командой вашего рабочего пространства.</p>
        </div>

        <div class="grid items-start gap-6 xl:grid-cols-[1.6fr_1fr]">
            <CompanyProfilePanel />
            <NotificationPreferencesPanel />
        </div>
        <TenantUsersPanel />
    </section>
</template>
