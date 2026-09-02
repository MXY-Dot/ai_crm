<script setup lang="ts">
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import AppLayout from '@/layouts/AppLayout.vue';
import ShipmentsPanel from '../components/dashboard/logistics/ShipmentsPanel.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

defineOptions({ layout: AppLayout });

const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { company, tenant } = storeToRefs(store);

const companyId = computed(() => company.value?.id ?? null);
const ready = computed(() => !! companyId.value);
const tenantSlug = computed(() => tenant.value?.slug ?? '');
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('logistics.settingsTitle') }}</h2>
            <p class="mt-1 text-sm ui-subtle">{{ locale.t('logistics.settingsSubtitle') }}</p>
        </div>

        <ShipmentsPanel v-if="ready" :company-id="companyId as number" :tenant-slug="tenantSlug" />
    </section>
</template>
