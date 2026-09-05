<script setup lang="ts">
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Car, ClipboardList } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import ModuleHelpDialog from '../components/dashboard/help/ModuleHelpDialog.vue';
import VehiclesPanel from '../components/dashboard/autoservice/VehiclesPanel.vue';
import RepairOrdersPanel from '../components/dashboard/autoservice/RepairOrdersPanel.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

defineOptions({ layout: AppLayout });

const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { company, tenant } = storeToRefs(store);
const activeTab = ref('vehicles');

const companyId = computed(() => company.value?.id ?? null);
const ready = computed(() => !! companyId.value);
const tenantSlug = computed(() => tenant.value?.slug ?? '');
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('autoService.settingsTitle') }}</h2>
                <p class="mt-1 text-sm ui-subtle">{{ locale.t('autoService.settingsSubtitle') }}</p>
            </div>
            <ModuleHelpDialog module-key="vehicleService" />
        </div>

        <Tabs v-if="ready" v-model="activeTab">
            <TooltipProvider :delay-duration="200">
                <TabsList class="flex-wrap">
                    <Tooltip v-for="t in [
                        { value: 'vehicles', icon: Car, label: locale.t('autoService.tabVehicles') },
                        { value: 'repairOrders', icon: ClipboardList, label: locale.t('autoService.tabRepairOrders') },
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

            <TabsContent value="vehicles" class="mt-4">
                <VehiclesPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
            </TabsContent>
            <TabsContent value="repairOrders" class="mt-4">
                <RepairOrdersPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
            </TabsContent>
        </Tabs>
    </section>
</template>
