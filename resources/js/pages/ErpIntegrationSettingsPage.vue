<script setup lang="ts">
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import AppLayout from '@/layouts/AppLayout.vue';
import { Card } from '@/components/ui/card';
import { CodeBlock } from '@/components/ui/code-block';
import ApiKeysPanel from '../components/dashboard/erp/ApiKeysPanel.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

defineOptions({ layout: AppLayout });

const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { company, tenant } = storeToRefs(store);

const companyId = computed(() => company.value?.id ?? null);
const ready = computed(() => !! companyId.value);
const tenantSlug = computed(() => tenant.value?.slug ?? '');

const authExample = 'Authorization: Bearer wero_erp_...';

const endpoints = [
    { method: 'GET', path: '/api/erp/products', desc: 'erp.docsProducts' },
    { method: 'PATCH', path: '/api/erp/products/{sku}/stock', desc: 'erp.docsStock' },
    { method: 'GET', path: '/api/erp/orders', desc: 'erp.docsOrders' },
    { method: 'PATCH', path: '/api/erp/orders/{order}/sync-status', desc: 'erp.docsSyncStatus' },
];
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('erp.settingsTitle') }}</h2>
            <p class="mt-1 text-sm ui-subtle">{{ locale.t('erp.settingsSubtitle') }}</p>
        </div>

        <ApiKeysPanel v-if="ready" :company-id="companyId as number" :tenant-slug="tenantSlug" />

        <Card :title="locale.t('erp.docsTitle')">
            <div class="grid gap-4 text-sm">
                <p class="ui-subtle">{{ locale.t('erp.docsIntro') }}</p>
                <CodeBlock :code="authExample" :label="locale.t('erp.docsAuthLabel')" />
                <div class="grid gap-3">
                    <div v-for="e in endpoints" :key="e.path" class="grid gap-1 rounded-lg border border-border p-3">
                        <p class="font-mono text-xs"><span class="rounded bg-accent px-1.5 py-0.5 font-medium ui-text">{{ e.method }}</span> <span class="ui-text">{{ e.path }}</span></p>
                        <p class="text-xs ui-subtle">{{ locale.t(e.desc) }}</p>
                    </div>
                </div>
            </div>
        </Card>
    </section>
</template>
