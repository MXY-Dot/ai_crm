<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { Crown, RefreshCw, Save, Star } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { useCrmDashboardStore } from '@/stores/crmDashboard';
import { useLocaleStore } from '@/stores/locale';
import KpiCard from '@/components/dashboard/KpiCard.vue';
import DataTable from '@/components/dashboard/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';

defineOptions({ layout: AppLayout });

type VipCustomer = {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    vip_score: number;
    vip_status: 'regular' | 'loyal' | 'vip' | 'top_vip';
    vip_reason: string | null;
    segment: string | null;
    purchases_count: number;
    total_revenue: number;
    average_check: number;
    last_purchase_at: string | null;
    responsible_manager: string | null;
};

const dashboard = useCrmDashboardStore();
const locale = useLocaleStore();
const { tenant } = storeToRefs(dashboard);

const customers = ref<VipCustomer[]>([]);
const loading = ref(true);
const recalculating = ref(false);
const savingCriteria = ref(false);

const criteria = reactive({ minPurchases: 5, minRevenue: null as number | null, minScore: 80, revenueScale: 10000 });

async function load(): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    loading.value = true;
    try {
        const [customersResp, settingsResp] = await Promise.all([
            apiRequest<{ data: VipCustomer[] }>('/api/vip-customers', { tenant: slug }),
            apiRequest<{ min_purchases: number; min_revenue: number | null; min_score: number; revenue_scale: number }>('/api/vip-settings', { tenant: slug }),
        ]);
        customers.value = customersResp.data;
        criteria.minPurchases = settingsResp.min_purchases;
        criteria.minRevenue = settingsResp.min_revenue;
        criteria.minScore = settingsResp.min_score;
        criteria.revenueScale = settingsResp.revenue_scale;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить VIP-клиентов');
    } finally {
        loading.value = false;
    }
}

async function recalculate(): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    recalculating.value = true;
    try {
        await apiRequest('/api/vip-customers/recalculate', { method: 'POST', tenant: slug });
        toast.success('Пересчитано');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось пересчитать');
    } finally {
        recalculating.value = false;
    }
}

async function saveCriteria(): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    savingCriteria.value = true;
    try {
        await apiRequest('/api/vip-settings', {
            method: 'PATCH',
            tenant: slug,
            body: {
                min_purchases: criteria.minPurchases,
                min_revenue: criteria.minRevenue,
                min_score: criteria.minScore,
                revenue_scale: criteria.revenueScale,
            },
        });
        toast.success('Критерии сохранены');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось сохранить критерии');
    } finally {
        savingCriteria.value = false;
    }
}

const vipCount = computed(() => customers.value.filter((c) => c.vip_status === 'vip' || c.vip_status === 'top_vip').length);
const topVipCount = computed(() => customers.value.filter((c) => c.vip_status === 'top_vip').length);
const averageScore = computed(() => {
    if (! customers.value.length) return 0;
    return Math.round(customers.value.reduce((sum, c) => sum + c.vip_score, 0) / customers.value.length);
});

const statusBadgeVariant: Record<string, 'default' | 'secondary' | 'outline'> = {
    top_vip: 'default',
    vip: 'default',
    loyal: 'secondary',
    regular: 'outline',
};

const dateFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' });

function formatDate(value: string | null): string {
    return value ? dateFormatter.format(new Date(value)) : '—';
}

function formatMoney(value: number): string {
    return new Intl.NumberFormat('ru-RU').format(value);
}

onMounted(load);
</script>

<template>
    <section class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('vip.title') }}</h2>
                <p class="mt-2 text-sm ui-subtle">{{ locale.t('vip.subtitle') }}</p>
            </div>
            <Button size="sm" variant="outline" :disabled="recalculating" @click="recalculate">
                <RefreshCw class="h-4 w-4" />{{ locale.t('vip.recalculate') }}
            </Button>
        </div>

        <div v-if="loading" class="grid gap-5 md:grid-cols-3">
            <Skeleton v-for="i in 3" :key="i" class="h-28 rounded-xl" />
        </div>
        <div v-else class="grid gap-5 md:grid-cols-3">
            <KpiCard label="VIP" :value="vipCount">
                <template #icon><Crown class="h-4 w-4 text-primary" /></template>
            </KpiCard>
            <KpiCard label="TOP VIP" :value="topVipCount">
                <template #icon><Star class="h-4 w-4 text-primary" /></template>
            </KpiCard>
            <KpiCard label="Средний Score" :value="averageScore" />
        </div>

        <div class="rounded-xl border border-border bg-card p-5">
            <h3 class="mb-4 font-display text-base font-semibold ui-text">{{ locale.t('vip.criteria') }}</h3>
            <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="saveCriteria">
                <label class="block text-sm">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('vip.minPurchases') }}</span>
                    <Input v-model.number="criteria.minPurchases" type="number" min="1" />
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('vip.minRevenue') }}</span>
                    <Input v-model.number="criteria.minRevenue" type="number" min="0" placeholder="—" />
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('vip.minScore') }}</span>
                    <Input v-model.number="criteria.minScore" type="number" min="1" max="100" />
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('vip.revenueScale') }}</span>
                    <Input v-model.number="criteria.revenueScale" type="number" min="1" />
                </label>
                <div class="flex items-end">
                    <Button size="sm" variant="primary" type="submit" class="w-full" :disabled="savingCriteria">
                        <Save class="h-4 w-4" />{{ savingCriteria ? '…' : 'Сохранить' }}
                    </Button>
                </div>
            </form>
        </div>

        <DataTable
            :loading="loading"
            :row-count="customers.length"
            :column-count="8"
            empty-message="Клиентов пока нет"
            min-width=""
        >
            <template #thead>
                <th class="px-4 py-2 text-left">{{ locale.t('vip.columnName') }}</th>
                <th class="px-4 py-2 text-left">{{ locale.t('vip.columnPurchases') }}</th>
                <th class="px-4 py-2 text-left">{{ locale.t('vip.columnRevenue') }}</th>
                <th class="px-4 py-2 text-left">{{ locale.t('vip.columnAverageCheck') }}</th>
                <th class="px-4 py-2 text-left">{{ locale.t('vip.columnLastPurchase') }}</th>
                <th class="px-4 py-2 text-left">{{ locale.t('vip.columnScore') }}</th>
                <th class="px-4 py-2 text-left">{{ locale.t('vip.columnReason') }}</th>
                <th class="px-4 py-2 text-left">{{ locale.t('vip.columnManager') }}</th>
            </template>

            <tr v-for="customer in customers" :key="customer.id">
                <td class="px-4 py-2">
                    <div class="font-medium ui-text">{{ customer.name }}</div>
                    <div class="text-xs ui-subtle">{{ customer.phone ?? customer.email ?? '—' }}</div>
                </td>
                <td class="px-4 py-2">{{ customer.purchases_count }}</td>
                <td class="px-4 py-2">{{ formatMoney(customer.total_revenue) }} TJS</td>
                <td class="px-4 py-2">{{ formatMoney(customer.average_check) }} TJS</td>
                <td class="px-4 py-2 ui-subtle">{{ formatDate(customer.last_purchase_at) }}</td>
                <td class="px-4 py-2">
                    <div class="flex items-center gap-2">
                        <Badge :variant="statusBadgeVariant[customer.vip_status]">{{ locale.t(`vip.status.${customer.vip_status}`) }}</Badge>
                        <span class="ui-subtle">{{ customer.vip_score }}</span>
                    </div>
                </td>
                <td class="max-w-xs px-4 py-2 text-xs ui-subtle">{{ customer.vip_reason }}</td>
                <td class="px-4 py-2 ui-subtle">{{ customer.responsible_manager ?? '—' }}</td>
            </tr>
        </DataTable>
    </section>
</template>
