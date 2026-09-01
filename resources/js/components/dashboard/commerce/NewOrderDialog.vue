<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Plus, Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input, InputGroup } from '../../ui/input';
import { Money } from '../../ui/money';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';

type Product = { id: number; name: string; price: number };
type Customer = { id: number; name: string; phone: string | null };
type ItemRow = { productId: number | null; quantity: number };

const props = defineProps<{ open: boolean; companyId: number; tenantSlug: string; products: Product[]; customers: Customer[] }>();
const emit = defineEmits<{ 'update:open': [boolean]; created: [] }>();
const locale = useLocaleStore();

const items = ref<ItemRow[]>([{ productId: null, quantity: 1 }]);
const customerMode = ref<'existing' | 'new'>('new');
const customerId = ref<number | null>(null);
const customerName = ref('');
const customerPhone = ref('');
const notes = ref('');
const deliveryFee = ref(0);
const discountAmount = ref(0);
const saving = ref(false);

watch(() => props.open, (open) => {
    if (! open) return;
    items.value = [{ productId: props.products[0]?.id ?? null, quantity: 1 }];
    customerMode.value = 'new';
    customerId.value = null;
    customerName.value = '';
    customerPhone.value = '';
    notes.value = '';
    deliveryFee.value = 0;
    discountAmount.value = 0;
});

function addItem(): void {
    items.value.push({ productId: props.products[0]?.id ?? null, quantity: 1 });
}

function removeItem(index: number): void {
    items.value.splice(index, 1);
}

function priceFor(productId: number | null): number {
    return props.products.find((p) => p.id === productId)?.price ?? 0;
}

// Informational estimate only, computed client-side from the same product list
// the page already loaded -- the real total is always recomputed server-side
// from the live Product price at write time (see OrderService::createOrder()),
// this is never what actually gets charged.
const estimatedSubtotal = computed(() => items.value.reduce((sum, item) => sum + priceFor(item.productId) * item.quantity, 0));
const estimatedTotal = computed(() => Math.max(0, estimatedSubtotal.value + deliveryFee.value - discountAmount.value));

const filteredCustomers = computed(() => {
    const q = customerPhone.value.trim();
    if (! q) return [];
    return props.customers.filter((c) => c.phone?.includes(q)).slice(0, 5);
});

const canSubmit = computed(() => {
    const hasItems = items.value.length > 0 && items.value.every((i) => i.productId && i.quantity > 0);
    const hasCustomer = customerMode.value === 'existing' ? !! customerId.value : !! customerName.value && !! customerPhone.value;

    return hasItems && hasCustomer;
});

async function submit(): Promise<void> {
    if (! canSubmit.value) return;
    saving.value = true;
    try {
        await apiRequest('/api/orders', {
            method: 'POST',
            body: {
                company_id: props.companyId,
                items: items.value.map((i) => ({ product_id: i.productId, quantity: i.quantity })),
                delivery_fee: deliveryFee.value || undefined,
                discount_amount: discountAmount.value || undefined,
                notes: notes.value || null,
                ...(customerMode.value === 'existing'
                    ? { customer_id: customerId.value }
                    : { customer_name: customerName.value, customer_phone: customerPhone.value }),
            },
            tenant: props.tenantSlug,
        });
        toast.success(locale.t('commerce.saved'));
        emit('update:open', false);
        emit('created');
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
                    <DialogTitle>{{ locale.t('commerce.newOrder') }}</DialogTitle>
                </DialogHeader>
                <div class="grid max-h-[70vh] gap-3 overflow-y-auto py-4">
                    <div>
                        <p class="mb-1 text-xs ui-subtle">{{ locale.t('commerce.orderItems') }}</p>
                        <div class="grid gap-2">
                            <div v-for="(item, index) in items" :key="index" class="flex items-center gap-2">
                                <Select
                                    :model-value="item.productId ? String(item.productId) : ''"
                                    class="min-w-0 flex-1"
                                    @update:model-value="(v) => { item.productId = v ? Number(v) : null; }"
                                >
                                    <SelectTrigger class="w-full"><SelectValue :placeholder="locale.t('commerce.selectProduct')" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="p in products" :key="p.id" :value="String(p.id)">{{ p.name }} · {{ p.price }} {{ locale.t('commerce.currency') }}</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Input v-model.number="item.quantity" type="number" min="1" class="w-20" />
                                <Button type="button" variant="ghost" size="icon" :disabled="items.length === 1" @click="removeItem(index)">
                                    <Trash2 class="h-4 w-4 text-destructive" />
                                </Button>
                            </div>
                        </div>
                        <Button type="button" variant="outline" size="sm" class="mt-2" @click="addItem">
                            <Plus class="h-4 w-4" />{{ locale.t('commerce.addItem') }}
                        </Button>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('commerce.deliveryFee') }}
                            <InputGroup v-model.number="deliveryFee" type="number" min="0" step="0.01">
                                <template #suffix>{{ locale.t('commerce.currency') }}</template>
                            </InputGroup>
                        </label>
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('commerce.discount') }}
                            <InputGroup v-model.number="discountAmount" type="number" min="0" step="0.01">
                                <template #suffix>{{ locale.t('commerce.currency') }}</template>
                            </InputGroup>
                        </label>
                    </div>

                    <div class="flex gap-2 text-xs">
                        <button type="button" class="rounded-md border px-2 py-1" :class="customerMode === 'new' ? 'border-primary text-primary' : 'border-border ui-subtle'" @click="customerMode = 'new'">{{ locale.t('commerce.customerName') }}</button>
                        <button type="button" class="rounded-md border px-2 py-1" :class="customerMode === 'existing' ? 'border-primary text-primary' : 'border-border ui-subtle'" @click="customerMode = 'existing'">{{ locale.t('booking.existingCustomer') }}</button>
                    </div>

                    <template v-if="customerMode === 'new'">
                        <Input v-model="customerName" :placeholder="locale.t('commerce.customerName')" />
                        <Input v-model="customerPhone" :placeholder="locale.t('commerce.customerPhone')" />
                    </template>
                    <template v-else>
                        <Input v-model="customerPhone" :placeholder="locale.t('commerce.customerPhone')" />
                        <div v-if="filteredCustomers.length" class="grid gap-1">
                            <button
                                v-for="c in filteredCustomers" :key="c.id" type="button"
                                class="rounded-md border px-2 py-1 text-left text-xs"
                                :class="customerId === c.id ? 'border-primary bg-primary/10' : 'border-border'"
                                @click="customerId = c.id"
                            >{{ c.name }} · {{ c.phone }}</button>
                        </div>
                    </template>

                    <Input v-model="notes" :placeholder="locale.t('commerce.notes')" />

                    <p class="text-xs ui-subtle">{{ locale.t('commerce.total') }}: <Money :value="estimatedTotal.toFixed(2)" tone="muted" /></p>
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving || ! canSubmit">{{ locale.t('commerce.newOrder') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
