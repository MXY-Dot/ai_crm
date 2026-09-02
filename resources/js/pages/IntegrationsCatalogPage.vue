<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import AppLayout from '@/layouts/AppLayout.vue';
import { Database } from '@lucide/vue';
import { apiRequest } from '@/lib/apiClient';
import { useLocaleStore } from '../stores/locale';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import ChannelCard from '../components/dashboard/channels/ChannelCard.vue';

const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { tenant } = storeToRefs(store);
const tenantSlug = computed(() => tenant.value?.slug ?? '');

defineOptions({ layout: AppLayout });

type ApiKey = { id: number; is_active: boolean };

// ERP/1C moved here from its own sidebar nav item -- it's an integration
// like any channel, not a standalone business module, so it belongs on the
// integrations marketplace alongside them rather than cluttering "Модули"
// with something a company sets up once and rarely revisits. The real
// configuration UI (key list, generate/revoke, endpoint reference) stays on
// its own /erp-settings page -- too much content for this card's small
// inline dialog, so this card links out via settingsHref instead of the
// usual slot-based dialog every channel card here uses.
const apiKeys = ref<ApiKey[]>([]);
const erpStatus = computed(() => (apiKeys.value.some((k) => k.is_active) ? 'connected' : 'pending'));

onMounted(async () => {
    try {
        apiKeys.value = await apiRequest<ApiKey[]>('/api/integration-api-keys', { tenant: tenantSlug.value });
    } catch {
        // Best-effort -- a failed fetch just leaves the card showing "not configured", never blocks the page.
    }
});
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('nav.marketplace') }}</h2>
            <p class="mt-1 text-sm ui-subtle">{{ locale.t('marketplace.subtitle') }}</p>
        </div>

        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            <ChannelCard :icon="Database" name="ERP / 1С" brand="erp" :status="erpStatus" settings-href="/erp-settings">
                <template #stats>
                    <p class="text-xs ui-subtle">{{ apiKeys.length }} {{ locale.t('marketplace.erpKeys') }}</p>
                </template>
            </ChannelCard>
        </div>
    </section>
</template>
