<script setup lang="ts">
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { CalendarClock, Utensils, UtensilsCrossed } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import ModuleHelpDialog from '../components/dashboard/help/ModuleHelpDialog.vue';
import ProductsPanel from '../components/dashboard/commerce/ProductsPanel.vue';
import ResourcesPanel from '../components/dashboard/booking/ResourcesPanel.vue';
import TableReservationsPanel from '../components/dashboard/booking/TableReservationsPanel.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

defineOptions({ layout: AppLayout });

const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { company, tenant } = storeToRefs(store);
const activeTab = ref('tables');

const companyId = computed(() => company.value?.id ?? null);
const ready = computed(() => !! companyId.value);
const tenantSlug = computed(() => tenant.value?.slug ?? '');
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('restaurant.settingsTitle') }}</h2>
                <p class="mt-1 text-sm ui-subtle">{{ locale.t('restaurant.settingsSubtitle') }}</p>
            </div>
            <ModuleHelpDialog module-key="tableReservations" />
        </div>

        <Tabs v-if="ready" v-model="activeTab">
            <TooltipProvider :delay-duration="200">
                <TabsList class="flex-wrap">
                    <Tooltip v-for="t in [
                        { value: 'tables', icon: Utensils, label: locale.t('restaurant.tabTables') },
                        { value: 'menu', icon: UtensilsCrossed, label: locale.t('restaurant.tabMenu') },
                        { value: 'reservations', icon: CalendarClock, label: locale.t('restaurant.tabReservations') },
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

            <TabsContent value="tables" class="mt-4">
                <ResourcesPanel :company-id="companyId as number" :tenant-slug="tenantSlug" type="table" :title="locale.t('restaurant.tabTables')" />
            </TabsContent>
            <TabsContent value="menu" class="mt-4">
                <ProductsPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
            </TabsContent>
            <TabsContent value="reservations" class="mt-4">
                <TableReservationsPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
            </TabsContent>
        </Tabs>
    </section>
</template>
