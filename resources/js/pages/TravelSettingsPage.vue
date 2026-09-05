<script setup lang="ts">
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Luggage, MapPin } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import ModuleHelpDialog from '../components/dashboard/help/ModuleHelpDialog.vue';
import ToursPanel from '../components/dashboard/travel/ToursPanel.vue';
import TourDeparturesPanel from '../components/dashboard/travel/TourDeparturesPanel.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

defineOptions({ layout: AppLayout });

const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { company, tenant } = storeToRefs(store);
const activeTab = ref('tours');

const companyId = computed(() => company.value?.id ?? null);
const ready = computed(() => !! companyId.value);
const tenantSlug = computed(() => tenant.value?.slug ?? '');
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('travel.settingsTitle') }}</h2>
                <p class="mt-1 text-sm ui-subtle">{{ locale.t('travel.settingsSubtitle') }}</p>
            </div>
            <ModuleHelpDialog module-key="tourBookings" />
        </div>

        <Tabs v-if="ready" v-model="activeTab">
            <TooltipProvider :delay-duration="200">
                <TabsList class="flex-wrap">
                    <Tooltip v-for="t in [
                        { value: 'tours', icon: MapPin, label: locale.t('travel.tabTours') },
                        { value: 'departures', icon: Luggage, label: locale.t('travel.tabDepartures') },
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

            <TabsContent value="tours" class="mt-4">
                <ToursPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
            </TabsContent>
            <TabsContent value="departures" class="mt-4">
                <TourDeparturesPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
            </TabsContent>
        </Tabs>
    </section>
</template>
