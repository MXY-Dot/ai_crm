<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Badge } from '../../ui/badge';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input } from '../../ui/input';
import { Money } from '../../ui/money';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Skeleton } from '../../ui/skeleton';
import { Textarea } from '../../ui/textarea';

type OrderDetail = {
    id: number;
    status: string;
    payment_status: string;
    subtotal: number;
    delivery_fee: number;
    discount_amount: number;
    total: number;
    notes: string | null;
    cancelled_reason: string | null;
    created_at: string;
    customer: { id: number; name: string; phone: string | null; email: string | null } | null;
    items: { id: number; product_name: string; quantity: number; unit_price: number; line_total: number }[];
    status_history: { id: number; old_status: string | null; new_status: string; comment: string | null; changed_by: { name: string } | null; created_at: string }[];
    delivery: { method: string; address: string | null; tracking_number: string | null; carrier: string | null; status: string; notes: string | null } | null;
    returns: { id: number; reason: string; status: string; refund_amount: number | null }[];
};

const ACTIVE_STATUSES = ['pending', 'confirmed', 'processing', 'shipped', 'delivered'];
const NEXT_STATUSES = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'completed'];

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const props = defineProps<{ open: boolean; orderId: number | null; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; changed: [] }>();
const locale = useLocaleStore();

const order = ref<OrderDetail | null>(null);
const loading = ref(false);
const busy = ref(false);

const nextStatus = ref('');
const statusComment = ref('');
const cancelReason = ref('');

const deliveryMethod = ref('courier');
const deliveryAddress = ref('');
const deliveryTracking = ref('');
const deliveryCarrier = ref('');
const deliveryStatus = ref('pending');
const deliveryNotes = ref('');

const returnReason = ref('');

async function load(): Promise<void> {
    if (! props.orderId) return;
    loading.value = true;
    try {
        order.value = await apiRequest<OrderDetail>(`/api/orders/${props.orderId}`, { tenant: props.tenantSlug });
        nextStatus.value = order.value.status;
        statusComment.value = '';
        cancelReason.value = '';
        returnReason.value = '';

        const d = order.value.delivery;
        deliveryMethod.value = d?.method ?? 'courier';
        deliveryAddress.value = d?.address ?? '';
        deliveryTracking.value = d?.tracking_number ?? '';
        deliveryCarrier.value = d?.carrier ?? '';
        deliveryStatus.value = d?.status ?? 'pending';
        deliveryNotes.value = d?.notes ?? '';
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

watch([() => props.open, () => props.orderId], ([open]) => { if (open) load(); });

const isActive = computed(() => !! order.value && ACTIVE_STATUSES.includes(order.value.status));

async function changeStatus(): Promise<void> {
    if (! order.value || nextStatus.value === order.value.status) return;
    busy.value = true;
    try {
        await apiRequest(`/api/orders/${order.value.id}/status`, { method: 'PATCH', body: { status: nextStatus.value, comment: statusComment.value || null }, tenant: props.tenantSlug });
        toast.success(locale.t('commerce.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function cancelOrder(): Promise<void> {
    if (! order.value || ! cancelReason.value.trim()) return;
    busy.value = true;
    try {
        await apiRequest(`/api/orders/${order.value.id}/cancel`, { method: 'POST', body: { reason: cancelReason.value }, tenant: props.tenantSlug });
        toast.success(locale.t('commerce.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function saveDelivery(): Promise<void> {
    if (! order.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/orders/${order.value.id}/delivery`, {
            method: 'PATCH',
            body: {
                method: deliveryMethod.value,
                address: deliveryAddress.value || null,
                tracking_number: deliveryTracking.value || null,
                carrier: deliveryCarrier.value || null,
                status: deliveryStatus.value,
                notes: deliveryNotes.value || null,
            },
            tenant: props.tenantSlug,
        });
        toast.success(locale.t('commerce.saved'));
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function requestReturn(): Promise<void> {
    if (! order.value || ! returnReason.value.trim()) return;
    busy.value = true;
    try {
        await apiRequest(`/api/orders/${order.value.id}/return`, { method: 'POST', body: { reason: returnReason.value }, tenant: props.tenantSlug });
        toast.success(locale.t('commerce.saved'));
        returnReason.value = '';
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>{{ locale.t('commerce.orderDetails') }} #{{ orderId }}</DialogTitle>
            </DialogHeader>

            <div v-if="loading" class="space-y-2 py-4">
                <Skeleton v-for="i in 4" :key="i" class="h-10 rounded-lg" />
            </div>

            <div v-else-if="order" class="grid max-h-[75vh] gap-4 overflow-y-auto py-4 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="font-medium ui-text">{{ order.customer?.name ?? '—' }}</p>
                        <p class="text-xs ui-subtle">{{ order.customer?.phone }}</p>
                    </div>
                    <div class="text-right">
                        <Badge>{{ locale.t('commerce.statuses.' + order.status) }}</Badge>
                        <p class="mt-1 text-xs font-medium tabular-nums ui-text">{{ formatDateTime(order.created_at) }}</p>
                    </div>
                </div>

                <div class="divide-y divide-border rounded-lg border border-border">
                    <div v-for="item in order.items" :key="item.id" class="flex items-center justify-between px-3 py-2 text-xs">
                        <span class="ui-text">{{ item.product_name }} × {{ item.quantity }}</span>
                        <Money :value="item.line_total" tone="muted" />
                    </div>
                </div>
                <div class="grid grid-cols-2 items-center gap-1 text-xs ui-subtle">
                    <span>{{ locale.t('commerce.subtotal') }}</span><span class="text-right"><Money :value="order.subtotal" tone="muted" /></span>
                    <span>{{ locale.t('commerce.deliveryFee') }}</span><span class="text-right"><Money :value="order.delivery_fee" tone="muted" /></span>
                    <span>{{ locale.t('commerce.discount') }}</span><span class="text-right"><Money :value="order.discount_amount" tone="muted" negative /></span>
                    <span class="font-medium ui-text">{{ locale.t('commerce.total') }}</span><span class="text-right"><Money :value="order.total" tone="lg" /></span>
                </div>

                <section v-if="isActive" class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('commerce.changeStatus') }}</p>
                    <div class="flex gap-2">
                        <Select v-model="nextStatus">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="s in NEXT_STATUSES" :key="s" :value="s">{{ locale.t('commerce.statuses.' + s) }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button :disabled="busy || nextStatus === order.status" @click="changeStatus">{{ locale.t('commerce.save') }}</Button>
                    </div>
                </section>

                <section v-if="isActive" class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('commerce.cancelOrder') }}</p>
                    <div class="flex gap-2">
                        <Textarea v-model="cancelReason" :placeholder="locale.t('commerce.cancelReason')" class="min-h-10" />
                        <Button variant="destructive" :disabled="busy || ! cancelReason.trim()" @click="cancelOrder">{{ locale.t('commerce.cancelOrder') }}</Button>
                    </div>
                </section>
                <p v-else-if="order.cancelled_reason" class="text-xs ui-subtle">{{ locale.t('commerce.cancelReason') }}: {{ order.cancelled_reason }}</p>

                <section class="grid gap-2 rounded-lg border border-border p-3">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('commerce.delivery') }}</p>
                    <div class="grid grid-cols-2 gap-2">
                        <Select v-model="deliveryMethod">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="courier">{{ locale.t('commerce.deliveryMethods.courier') }}</SelectItem>
                                <SelectItem value="pickup">{{ locale.t('commerce.deliveryMethods.pickup') }}</SelectItem>
                                <SelectItem value="post">{{ locale.t('commerce.deliveryMethods.post') }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select v-model="deliveryStatus">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="pending">{{ locale.t('commerce.deliveryStatuses.pending') }}</SelectItem>
                                <SelectItem value="in_transit">{{ locale.t('commerce.deliveryStatuses.in_transit') }}</SelectItem>
                                <SelectItem value="delivered">{{ locale.t('commerce.deliveryStatuses.delivered') }}</SelectItem>
                                <SelectItem value="failed">{{ locale.t('commerce.deliveryStatuses.failed') }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <Input v-model="deliveryAddress" :placeholder="locale.t('commerce.deliveryAddress')" />
                    <div class="grid grid-cols-2 gap-2">
                        <Input v-model="deliveryTracking" :placeholder="locale.t('commerce.trackingNumber')" />
                        <Input v-model="deliveryCarrier" :placeholder="locale.t('commerce.carrier')" />
                    </div>
                    <Button size="sm" class="w-fit" :disabled="busy" @click="saveDelivery">{{ locale.t('commerce.save') }}</Button>
                </section>

                <section class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('commerce.requestReturn') }}</p>
                    <div v-for="r in order.returns" :key="r.id" class="rounded-lg border border-border px-3 py-2 text-xs">
                        <p class="ui-text">{{ r.reason }}</p>
                        <p class="ui-subtle">{{ locale.t('commerce.returnStatuses.' + r.status) }}<span v-if="r.refund_amount"> · <Money :value="r.refund_amount" tone="muted" /></span></p>
                    </div>
                    <div class="flex gap-2">
                        <Input v-model="returnReason" :placeholder="locale.t('commerce.returnReason')" />
                        <Button variant="outline" :disabled="busy || ! returnReason.trim()" @click="requestReturn">{{ locale.t('commerce.requestReturn') }}</Button>
                    </div>
                </section>

                <section v-if="order.status_history.length" class="grid gap-1">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('commerce.history') }}</p>
                    <p v-for="h in order.status_history" :key="h.id" class="text-xs ui-subtle">
                        <span class="font-medium tabular-nums ui-text">{{ formatDateTime(h.created_at) }}</span> · {{ locale.t('commerce.statuses.' + h.new_status) }}<span v-if="h.comment"> — {{ h.comment }}</span>
                    </p>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
