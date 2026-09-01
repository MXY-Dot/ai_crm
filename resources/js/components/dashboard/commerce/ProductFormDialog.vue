<script setup lang="ts">
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input } from '../../ui/input';
import { Switch } from '../../ui/switch';
import { Textarea } from '../../ui/textarea';

export type ProductRow = {
    id: number;
    name: string;
    category: string | null;
    description: string | null;
    sku: string | null;
    price: number;
    stock_quantity: number | null;
    track_stock: boolean;
    is_active: boolean;
};

const props = defineProps<{ open: boolean; product: ProductRow | null; companyId: number; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; saved: [] }>();
const locale = useLocaleStore();

const form = ref({
    name: '', category: '', description: '', sku: '', price: 0,
    stock_quantity: 0, track_stock: false, is_active: true,
});
const saving = ref(false);

watch(() => props.open, (open) => {
    if (! open) return;
    const p = props.product;
    form.value = p
        ? { name: p.name, category: p.category ?? '', description: p.description ?? '', sku: p.sku ?? '', price: p.price, stock_quantity: p.stock_quantity ?? 0, track_stock: p.track_stock, is_active: p.is_active }
        : { name: '', category: '', description: '', sku: '', price: 0, stock_quantity: 0, track_stock: false, is_active: true };
});

async function submit(): Promise<void> {
    saving.value = true;
    try {
        const payload = { ...form.value, company_id: props.companyId, stock_quantity: form.value.track_stock ? form.value.stock_quantity : null };
        if (props.product) {
            await apiRequest(`/api/products/${props.product.id}`, { method: 'PATCH', body: payload, tenant: props.tenantSlug });
        } else {
            await apiRequest('/api/products', { method: 'POST', body: payload, tenant: props.tenantSlug });
        }
        toast.success(locale.t('commerce.saved'));
        emit('update:open', false);
        emit('saved');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-lg">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ product ? locale.t('commerce.editProduct') : locale.t('commerce.addProduct') }}</DialogTitle>
                </DialogHeader>
                <div class="grid max-h-[70vh] gap-3 overflow-y-auto py-4">
                    <Input v-model="form.name" :placeholder="locale.t('commerce.productName')" required />
                    <div class="grid grid-cols-2 gap-3">
                        <Input v-model="form.category" :placeholder="locale.t('commerce.productCategory')" />
                        <Input v-model="form.sku" :placeholder="locale.t('commerce.productSku')" />
                    </div>
                    <Textarea v-model="form.description" :placeholder="locale.t('commerce.productDescription')" class="min-h-16" />
                    <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('commerce.productPrice') }}
                        <Input v-model.number="form.price" type="number" min="0" step="0.01" />
                    </label>
                    <label class="flex items-center justify-between text-sm ui-text">{{ locale.t('commerce.productTrackStock') }}
                        <Switch v-model="form.track_stock" />
                    </label>
                    <label v-if="form.track_stock" class="grid gap-1 text-xs ui-subtle">{{ locale.t('commerce.productStock') }}
                        <Input v-model.number="form.stock_quantity" type="number" min="0" step="1" />
                    </label>
                    <label class="flex items-center justify-between text-sm ui-text">{{ locale.t('commerce.active') }}
                        <Switch v-model="form.is_active" />
                    </label>
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving">{{ locale.t('commerce.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
