<script setup lang="ts">
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { BarChart3, Package, Undo2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import OrderReportsPanel from '../components/dashboard/commerce/OrderReportsPanel.vue';
import OrderReturnsPanel from '../components/dashboard/commerce/OrderReturnsPanel.vue';
import ProductsPanel from '../components/dashboard/commerce/ProductsPanel.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

defineOptions({ layout: AppLayout });

const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { company, tenant } = storeToRefs(store);
const activeTab = ref('products');

// crmDashboard's own `companyId` computed is a private local (never in its
// return object, so `store.companyId` is always undefined) -- derive it here,
// same as BookingSettingsPage.vue.
const companyId = computed(() => company.value?.id ?? null);
const ready = computed(() => !! companyId.value);
const tenantSlug = computed(() => tenant.value?.slug ?? '');
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('commerce.settingsTitle') }}</h2>
            <p class="mt-1 text-sm ui-subtle">{{ locale.t('commerce.settingsSubtitle') }}</p>
        </div>

        <Tabs v-if="ready" v-model="activeTab">
            <TabsList class="flex-wrap">
                <TabsTrigger value="products"><Package class="h-4 w-4" />{{ locale.t('commerce.tabProducts') }}</TabsTrigger>
                <TabsTrigger value="returns"><Undo2 class="h-4 w-4" />{{ locale.t('commerce.tabReturns') }}</TabsTrigger>
                <TabsTrigger value="reports"><BarChart3 class="h-4 w-4" />{{ locale.t('commerce.tabReports') }}</TabsTrigger>
            </TabsList>

            <TabsContent value="products" class="mt-4">
                <ProductsPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
            </TabsContent>
            <TabsContent value="returns" class="mt-4">
                <OrderReturnsPanel :tenant-slug="tenantSlug" />
            </TabsContent>
            <TabsContent value="reports" class="mt-4">
                <OrderReportsPanel :tenant-slug="tenantSlug" />
            </TabsContent>
        </Tabs>
    </section>
</template>
