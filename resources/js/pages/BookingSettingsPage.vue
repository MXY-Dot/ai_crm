<script setup lang="ts">
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { BarChart3, Building2, CalendarClock, CreditCard, Scissors, Settings2, Users2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import BranchesPanel from '../components/dashboard/booking/BranchesPanel.vue';
import CancellationPolicyPanel from '../components/dashboard/booking/CancellationPolicyPanel.vue';
import EmployeesPanel from '../components/dashboard/booking/EmployeesPanel.vue';
import PaymentGatewaySettingsPanel from '../components/dashboard/booking/PaymentGatewaySettingsPanel.vue';
import ResourcesPanel from '../components/dashboard/booking/ResourcesPanel.vue';
import SalonReportsPanel from '../components/dashboard/booking/SalonReportsPanel.vue';
import ServicesPanel from '../components/dashboard/booking/ServicesPanel.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

defineOptions({ layout: AppLayout });

const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { company, tenant } = storeToRefs(store);
const activeTab = ref('services');

// crmDashboard's own `companyId` computed is a private local (never in its
// return object, so `store.companyId` is always undefined) -- derive it here.
const companyId = computed(() => company.value?.id ?? null);
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
            <TooltipProvider :delay-duration="200">
                <TabsList class="flex-wrap">
                    <Tooltip v-for="t in [
                        { value: 'services', icon: Scissors, label: locale.t('booking.tabServices') },
                        { value: 'branches', icon: Building2, label: locale.t('booking.tabBranches') },
                        { value: 'employees', icon: Users2, label: locale.t('booking.tabEmployees') },
                        { value: 'resources', icon: CalendarClock, label: locale.t('booking.tabResources') },
                        { value: 'policy', icon: Settings2, label: locale.t('booking.tabPolicy') },
                        { value: 'payment', icon: CreditCard, label: 'Оплата' },
                        { value: 'reports', icon: BarChart3, label: locale.t('booking.tabReports') },
                    ]" :key="t.value"
                    >
                        <TooltipTrigger as-child>
                            <TabsTrigger :value="t.value" :aria-label="t.label">
                                <component :is="t.icon" class="h-4 w-4" />
                            </TabsTrigger>
                        </TooltipTrigger>
                        <TooltipContent>{{ t.label }}</TooltipContent>
                    </Tooltip>
                </TabsList>
            </TooltipProvider>

            <TabsContent value="services" class="mt-4">
                <ServicesPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
            </TabsContent>
            <TabsContent value="branches" class="mt-4">
                <BranchesPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
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
            <TabsContent value="payment" class="mt-4">
                <PaymentGatewaySettingsPanel />
            </TabsContent>
            <TabsContent value="reports" class="mt-4">
                <SalonReportsPanel :tenant-slug="tenantSlug" />
            </TabsContent>
        </Tabs>
    </section>
</template>
