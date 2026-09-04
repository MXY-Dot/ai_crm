<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Crown, RefreshCw, Save, Star } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import KpiCard from '@/components/dashboard/KpiCard.vue';
import DataTable from '@/components/dashboard/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';

defineOptions({ layout: SuperAdminLayout });

type TenantOption = { id: number; name: string };
type VipCustomer = {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    vip_score: number;
    vip_status: 'regular' | 'loyal' | 'vip' | 'top_vip';
    purchases_count: number;
    total_revenue: number;
    average_check: number;
    last_purchase_at: string | null;
    responsible_manager: string | null;
};

const tenantOptions = ref<TenantOption[]>([]);
const selectedTenantId = ref<number | null>(null);

const customers = ref<VipCustomer[]>([]);
const loading = ref(false);
const loaded = ref(false);
const recalculating = ref(false);
const savingCriteria = ref(false);
const criteria = reactive({ minPurchases: 5, minRevenue: null as number | null, minScore: 80, revenueScale: 10000 });

const statusLabels: Record<string, string> = { top_vip: 'TOP VIP', vip: 'VIP', loyal: 'Лояльный', regular: 'Обычный' };
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

async function load(tenantId: number): Promise<void> {
    loading.value = true;
    try {
        const [customersResp, settingsResp] = await Promise.all([
            apiRequest<{ data: VipCustomer[] }>(`/api/admin/companies/${tenantId}/vip-customers`),
            apiRequest<{ min_purchases: number; min_revenue: number | null; min_score: number; revenue_scale: number }>(`/api/admin/companies/${tenantId}/vip-settings`),
        ]);
        customers.value = customersResp.data;
        criteria.minPurchases = settingsResp.min_purchases;
        criteria.minRevenue = settingsResp.min_revenue;
        criteria.minScore = settingsResp.min_score;
        criteria.revenueScale = settingsResp.revenue_scale;
        loaded.value = true;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить VIP-клиентов');
    } finally {
        loading.value = false;
    }
}

async function recalculate(): Promise<void> {
    if (! selectedTenantId.value) return;

    recalculating.value = true;
    try {
        await apiRequest(`/api/admin/companies/${selectedTenantId.value}/vip-customers/recalculate`, { method: 'POST' });
        toast.success('Пересчитано');
        await load(selectedTenantId.value);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось пересчитать');
    } finally {
        recalculating.value = false;
    }
}

async function saveCriteria(): Promise<void> {
    if (! selectedTenantId.value) return;

    savingCriteria.value = true;
    try {
        await apiRequest(`/api/admin/companies/${selectedTenantId.value}/vip-settings`, {
            method: 'PATCH',
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

watch(selectedTenantId, (tenantId) => {
    customers.value = [];
    loaded.value = false;
    if (tenantId) load(tenantId);
});

onMounted(() => {
    apiRequest<TenantOption[]>('/api/admin/companies/lookup').then((options) => { tenantOptions.value = options; }).catch(() => {});
});

</script>

<template>
    <section class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">VIP-клиенты</h2>
                <p class="mt-2 text-sm ui-subtle">VIP-скоринг клиентов выбранной компании: критерии, список, ручной пересчёт.</p>
            </div>
            <Button v-if="selectedTenantId" size="sm" variant="outline" :disabled="recalculating" @click="recalculate">
                <RefreshCw class="h-4 w-4" />Пересчитать
            </Button>
        </div>

        <Select v-model="selectedTenantId">
            <SelectTrigger class="w-full sm:w-80"><SelectValue placeholder="Выберите компанию" /></SelectTrigger>
            <SelectContent>
                <SelectItem v-for="option in tenantOptions" :key="option.id" :value="option.id">{{ option.name }}</SelectItem>
            </SelectContent>
        </Select>

        <template v-if="selectedTenantId">
            <div v-if="loading && ! loaded" class="grid gap-5 md:grid-cols-3">
                <Skeleton v-for="i in 3" :key="i" class="h-28 rounded-xl" />
            </div>
            <template v-else>
                <div class="grid gap-5 md:grid-cols-3">
                    <KpiCard label="VIP" :value="customers.filter((c) => c.vip_status === 'vip' || c.vip_status === 'top_vip').length">
                        <template #icon><Crown class="h-4 w-4 text-primary" /></template>
                    </KpiCard>
                    <KpiCard label="TOP VIP" :value="customers.filter((c) => c.vip_status === 'top_vip').length">
                        <template #icon><Star class="h-4 w-4 text-primary" /></template>
                    </KpiCard>
                    <KpiCard label="Средний Score" :value="customers.length ? Math.round(customers.reduce((sum, c) => sum + c.vip_score, 0) / customers.length) : 0" />
                </div>

                <div class="rounded-xl border border-border bg-card p-5">
                    <h3 class="mb-4 font-display text-base font-semibold ui-text">Критерии VIP-статуса</h3>
                    <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="saveCriteria">
                        <label class="block text-sm">
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Мин. покупок</span>
                            <Input v-model.number="criteria.minPurchases" type="number" min="1" />
                        </label>
                        <label class="block text-sm">
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Мин. выручка</span>
                            <Input v-model.number="criteria.minRevenue" type="number" min="0" placeholder="—" />
                        </label>
                        <label class="block text-sm">
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Мин. score</span>
                            <Input v-model.number="criteria.minScore" type="number" min="1" max="100" />
                        </label>
                        <label class="block text-sm">
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Шкала выручки</span>
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
                    :column-count="7"
                    empty-message="Клиентов пока нет"
                    min-width=""
                >
                    <template #thead>
                        <th class="px-4 py-2 text-left">Клиент</th>
                        <th class="px-4 py-2 text-left">Покупок</th>
                        <th class="px-4 py-2 text-left">Выручка</th>
                        <th class="px-4 py-2 text-left">Средний чек</th>
                        <th class="px-4 py-2 text-left">Последняя покупка</th>
                        <th class="px-4 py-2 text-left">Статус</th>
                        <th class="px-4 py-2 text-left">Ответственный</th>
                    </template>

                    <tr v-for="customer in customers" :key="customer.id">
                        <td class="px-4 py-2">
                            <div class="font-medium ui-text">{{ customer.name }}</div>
                            <div class="text-xs ui-subtle">{{ customer.phone ?? customer.email ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-2 ui-subtle">{{ customer.purchases_count }}</td>
                        <td class="px-4 py-2 ui-subtle">{{ formatMoney(customer.total_revenue) }} TJS</td>
                        <td class="px-4 py-2 ui-subtle">{{ formatMoney(customer.average_check) }} TJS</td>
                        <td class="px-4 py-2 ui-subtle">{{ formatDate(customer.last_purchase_at) }}</td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <Badge :variant="statusBadgeVariant[customer.vip_status]">{{ statusLabels[customer.vip_status] }}</Badge>
                                <span class="ui-subtle">{{ customer.vip_score }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-2 ui-subtle">{{ customer.responsible_manager ?? '—' }}</td>
                    </tr>
                </DataTable>
            </template>
        </template>
        <p v-else class="rounded-xl border border-dashed border-border p-8 text-center text-sm ui-subtle">Выберите компанию, чтобы увидеть её VIP-клиентов</p>
    </section>
</template>
