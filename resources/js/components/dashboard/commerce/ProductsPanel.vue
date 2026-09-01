<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Package, Pencil, Plus, Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { EmptyState } from '../../ui/empty-state';
import { Money } from '../../ui/money';
import { Skeleton } from '../../ui/skeleton';
import { Switch } from '../../ui/switch';
import ProductFormDialog, { type ProductRow } from './ProductFormDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();

const products = ref<ProductRow[]>([]);
const loading = ref(true);
const dialogOpen = ref(false);
const editing = ref<ProductRow | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const res = await apiRequest<{ data: ProductRow[] }>('/api/products', { tenant: props.tenantSlug });
        products.value = res.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function openCreate(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function openEdit(product: ProductRow): void {
    editing.value = product;
    dialogOpen.value = true;
}

async function toggleActive(product: ProductRow): Promise<void> {
    const next = ! product.is_active;
    product.is_active = next;
    try {
        await apiRequest(`/api/products/${product.id}`, { method: 'PATCH', body: { is_active: next }, tenant: props.tenantSlug });
    } catch (error) {
        product.is_active = ! next;
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}

async function remove(product: ProductRow): Promise<void> {
    if (! confirm(product.name + '?')) return;
    try {
        await apiRequest(`/api/products/${product.id}`, { method: 'DELETE', tenant: props.tenantSlug });
        products.value = products.value.filter((p) => p.id !== product.id);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}

function stockLabel(product: ProductRow): string {
    if (! product.track_stock) return locale.t('commerce.productStockUnlimited');

    return String(product.stock_quantity ?? 0);
}
</script>

<template>
    <Card :title="locale.t('commerce.tabProducts')">
        <template #actions>
            <Button size="sm" @click="openCreate"><Plus class="h-4 w-4" />{{ locale.t('commerce.addProduct') }}</Button>
        </template>

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-14 rounded-lg" />
        </div>
        <EmptyState v-else-if="! products.length" :icon="Package" :title="locale.t('commerce.noProducts')" />
        <div v-else class="divide-y divide-border pb-2">
            <div v-for="product in products" :key="product.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium ui-text">{{ product.name }}</p>
                    <p class="text-xs ui-subtle">{{ product.category || '—' }} · {{ stockLabel(product) }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <Money :value="product.price" tone="lg" />
                    <Switch :model-value="product.is_active" @update:model-value="toggleActive(product)" />
                    <Button variant="ghost" size="icon" @click="openEdit(product)"><Pencil class="h-4 w-4" /></Button>
                    <Button variant="ghost" size="icon" @click="remove(product)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                </div>
            </div>
        </div>

        <ProductFormDialog v-model:open="dialogOpen" :product="editing" :company-id="companyId" :tenant-slug="tenantSlug" @saved="load" />
    </Card>
</template>
