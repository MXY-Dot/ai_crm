<script setup lang="ts">
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { BedDouble, CalendarClock } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import ModuleHelpDialog from '../components/dashboard/help/ModuleHelpDialog.vue';
import ResourcesPanel from '../components/dashboard/booking/ResourcesPanel.vue';
import RoomReservationsPanel from '../components/dashboard/booking/RoomReservationsPanel.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

defineOptions({ layout: AppLayout });

const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { company, tenant } = storeToRefs(store);
const activeTab = ref('rooms');

const companyId = computed(() => company.value?.id ?? null);
const ready = computed(() => !! companyId.value);
const tenantSlug = computed(() => tenant.value?.slug ?? '');
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('hotel.settingsTitle') }}</h2>
                <p class="mt-1 text-sm ui-subtle">{{ locale.t('hotel.settingsSubtitle') }}</p>
            </div>
            <ModuleHelpDialog module-key="roomBooking" />
        </div>

        <Tabs v-if="ready" v-model="activeTab">
            <TooltipProvider :delay-duration="200">
                <TabsList class="flex-wrap">
                    <Tooltip v-for="t in [
                        { value: 'rooms', icon: BedDouble, label: locale.t('hotel.tabRooms') },
                        { value: 'reservations', icon: CalendarClock, label: locale.t('hotel.tabReservations') },
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

            <TabsContent value="rooms" class="mt-4">
                <ResourcesPanel :company-id="companyId as number" :tenant-slug="tenantSlug" type="room" :title="locale.t('hotel.tabRooms')" />
            </TabsContent>
            <TabsContent value="reservations" class="mt-4">
                <RoomReservationsPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
            </TabsContent>
        </Tabs>
    </section>
</template>
