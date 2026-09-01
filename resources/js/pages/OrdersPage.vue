<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import NewOrderDialog from '../components/dashboard/commerce/NewOrderDialog.vue';
import OrderDetailDialog from '../components/dashboard/commerce/OrderDetailDialog.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

defineOptions({ layout: AppLayout });

const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { company, customers, tenant } = storeToRefs(store);

const companyId = computed(() => company.value?.id ?? null);
const tenantSlug = computed(() => tenant.value?.slug ?? '');

type Product = { id: number; name: string; price: number };
type OrderRow = {
    id: number;
    status: string;
    total: number;
    created_at: string;
    customer: { id: number; name: string; phone: string | null } | null;
    items: { id: number; product_name: string; quantity: number }[];
};

const products = ref<Product[]>([]);
const orders = ref<OrderRow[]>([]);
const loading = ref(true);
const statusFilter = ref('all');
const newOrderOpen = ref(false);
const detailOpen = ref(false);
const selectedOrderId = ref<number | null>(null);

async function loadProducts(): Promise<void> {
    try {
        const data = await apiRequest<{ data: Product[] }>('/api/products', { tenant: tenantSlug.value });
        products.value = data.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}

async function loadOrders(): Promise<void> {
    loading.value = true;
    try {
        const params = new URLSearchParams();
        if (statusFilter.value !== 'all') params.set('status', statusFilter.value);
        const data = await apiRequest<{ data: OrderRow[] }>('/api/orders?' + params, { tenant: tenantSlug.value });
        orders.value = data.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    await loadProducts();
    await loadOrders();
});

watch(statusFilter, loadOrders);

function openDetail(id: number): void {
    selectedOrderId.value = id;
    detailOpen.value = true;
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const STATUS_TONE: Record<string, 'neutral' | 'green' | 'amber' | 'red' | 'blue'> = {
    pending: 'amber', confirmed: 'blue', processing: 'blue', shipped: 'blue', delivered: 'green', completed: 'green', cancelled: 'red',
};
</script>

<template>
    <section class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('commerce.ordersTitle') }}</h2>
                <p class="mt-1 text-sm ui-subtle">{{ locale.t('commerce.ordersSubtitle') }}</p>
            </div>
            <Button v-if="products.length" @click="newOrderOpen = true"><Plus class="h-4 w-4" />{{ locale.t('commerce.newOrder') }}</Button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Select v-model="statusFilter">
                <SelectTrigger class="w-56"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{ locale.t('commerce.allStatuses') }}</SelectItem>
                    <SelectItem value="pending">{{ locale.t('commerce.statuses.pending') }}</SelectItem>
                    <SelectItem value="confirmed">{{ locale.t('commerce.statuses.confirmed') }}</SelectItem>
                    <SelectItem value="processing">{{ locale.t('commerce.statuses.processing') }}</SelectItem>
                    <SelectItem value="shipped">{{ locale.t('commerce.statuses.shipped') }}</SelectItem>
                    <SelectItem value="delivered">{{ locale.t('commerce.statuses.delivered') }}</SelectItem>
                    <SelectItem value="completed">{{ locale.t('commerce.statuses.completed') }}</SelectItem>
                    <SelectItem value="cancelled">{{ locale.t('commerce.statuses.cancelled') }}</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <p v-if="! products.length" class="text-sm ui-subtle">{{ locale.t('commerce.noProducts') }}</p>
        <Skeleton v-else-if="loading" class="h-96 rounded-xl" />
        <p v-else-if="! orders.length" class="text-sm ui-subtle">{{ locale.t('commerce.noOrders') }}</p>
        <div v-else class="overflow-hidden rounded-xl bg-card shadow-sm ring-1 ring-foreground/10">
            <div class="divide-y divide-border">
                <button
                    v-for="order in orders" :key="order.id" type="button"
                    class="flex w-full flex-wrap items-center justify-between gap-3 px-4 py-3 text-left transition-colors hover:bg-accent/40"
                    @click="openDetail(order.id)"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium ui-text">{{ locale.t('commerce.orderNumber') }} #{{ order.id }} · {{ order.customer?.name ?? '—' }}</p>
                        <p class="truncate text-xs ui-subtle">{{ order.items.map((i) => `${i.product_name} × ${i.quantity}`).join(', ') }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="text-xs ui-subtle">{{ formatDate(order.created_at) }}</span>
                        <span class="text-sm font-medium ui-text">{{ order.total }} {{ locale.t('commerce.currency') }}</span>
                        <Badge :tone="STATUS_TONE[order.status] ?? 'neutral'">{{ locale.t('commerce.statuses.' + order.status) }}</Badge>
                    </div>
                </button>
            </div>
        </div>

        <NewOrderDialog
            v-model:open="newOrderOpen"
            :company-id="companyId as number"
            :tenant-slug="tenantSlug"
            :products="products"
            :customers="customers as unknown as Array<{ id: number; name: string; phone: string | null }>"
            @created="loadOrders"
        />
        <OrderDetailDialog v-model:open="detailOpen" :order-id="selectedOrderId" :tenant-slug="tenantSlug" @changed="loadOrders" />
    </section>
</template>
