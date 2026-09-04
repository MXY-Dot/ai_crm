<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import DataTable from '../DataTable.vue';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Money } from '../../ui/money';
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
        toast.success(next ? 'Товар включён' : 'Товар выключен');
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

        <DataTable
            embedded
            :loading="loading"
            :row-count="products.length"
            :column-count="3"
            :empty-message="locale.t('commerce.noProducts')"
            min-width="min-w-full"
        >
            <template #thead>
                <th class="p-3">{{ locale.t('commerce.tabProducts') }}</th>
                <th class="p-3 text-right">{{ locale.t('common.price') }}</th>
                <th class="p-3 text-right">{{ locale.t('common.actions') }}</th>
            </template>

            <tr v-for="product in products" :key="product.id">
                <td class="p-3">
                    <p class="text-sm font-medium ui-text">{{ product.name }}</p>
                    <p class="text-xs ui-subtle">{{ product.category || '—' }} · {{ stockLabel(product) }}</p>
                </td>
                <td class="p-3 text-right"><Money :value="product.price" tone="lg" /></td>
                <td class="p-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                        <Switch :model-value="product.is_active" @update:model-value="toggleActive(product)" />
                        <Button variant="ghost" size="icon" @click="openEdit(product)"><Pencil class="h-4 w-4" /></Button>
                        <Button variant="ghost" size="icon" @click="remove(product)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                    </div>
                </td>
            </tr>
        </DataTable>

        <ProductFormDialog v-model:open="dialogOpen" :product="editing" :company-id="companyId" :tenant-slug="tenantSlug" @saved="load" />
    </Card>
</template>
