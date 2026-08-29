<script setup lang="ts">
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { CalendarClock, Scissors, Settings2, Users2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import CancellationPolicyPanel from '../components/dashboard/booking/CancellationPolicyPanel.vue';
import EmployeesPanel from '../components/dashboard/booking/EmployeesPanel.vue';
import ResourcesPanel from '../components/dashboard/booking/ResourcesPanel.vue';
import ServicesPanel from '../components/dashboard/booking/ServicesPanel.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

defineOptions({ layout: AppLayout });

const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { companyId, tenant } = storeToRefs(store);
const activeTab = ref('services');

const ready = computed(() => !! companyId.value);
const tenantSlug = computed(() => tenant.value?.slug ?? '');
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('booking.settingsTitle') }}</h2>
            <p class="mt-1 text-sm ui-subtle">{{ locale.t('booking.settingsSubtitle') }}</p>
        </div>

        <Tabs v-if="ready" v-model="activeTab">
            <TabsList class="flex-wrap">
                <TabsTrigger value="services"><Scissors class="h-4 w-4" />{{ locale.t('booking.tabServices') }}</TabsTrigger>
                <TabsTrigger value="employees"><Users2 class="h-4 w-4" />{{ locale.t('booking.tabEmployees') }}</TabsTrigger>
                <TabsTrigger value="resources"><CalendarClock class="h-4 w-4" />{{ locale.t('booking.tabResources') }}</TabsTrigger>
                <TabsTrigger value="policy"><Settings2 class="h-4 w-4" />{{ locale.t('booking.tabPolicy') }}</TabsTrigger>
            </TabsList>

            <TabsContent value="services" class="mt-4">
                <ServicesPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
            </TabsContent>
            <TabsContent value="employees" class="mt-4">
                <EmployeesPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
            </TabsContent>
            <TabsContent value="resources" class="mt-4">
                <ResourcesPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
            </TabsContent>
            <TabsContent value="policy" class="mt-4">
                <CancellationPolicyPanel :tenant-slug="tenantSlug" />
            </TabsContent>
        </Tabs>
    </section>
</template>
