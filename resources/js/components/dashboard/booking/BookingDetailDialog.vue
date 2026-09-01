<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { CodeBlock } from '../../ui/code-block';
import { DatePicker } from '../../ui/date-picker';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input, InputGroup } from '../../ui/input';
import { Money } from '../../ui/money';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Skeleton } from '../../ui/skeleton';
import { Textarea } from '../../ui/textarea';

type PaymentProof = { id: number; file_url: string; amount: number | null; operation_number: string | null; status: string; comment: string | null };
type GatewayPayment = { id: number; gateway: string; checkout_url: string | null; status: string };
type StatusEntry = { id: number; old_status: string | null; new_status: string; comment: string | null; changed_by: { name: string } | null; created_at: string };
type BookingDetail = {
    id: number; status: string; starts_at: string; ends_at: string; price: number; prepayment_amount: number; prepayment_status: string; notes: string | null;
    customer: { name: string; phone: string | null } | null; service: { name: string } | null; employee: { name: string } | null; resource: { name: string } | null;
    status_history: StatusEntry[]; payment_proofs: PaymentProof[];
};

const STATUS_OPTIONS = ['confirmed', 'client_arrived', 'in_progress', 'completed', 'no_show'];

const props = defineProps<{ open: boolean; bookingId: number | null; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; changed: [] }>();
const locale = useLocaleStore();

const booking = ref<BookingDetail | null>(null);
const loading = ref(true);
const busy = ref(false);
const newStatus = ref('confirmed');
const rescheduleDate = ref('');
const rescheduleTime = ref('');
const cancelReason = ref('');
const proofAmount = ref<number | null>(null);
const proofOperation = ref('');
const proofFile = ref<File | null>(null);
const gatewayPayment = ref<GatewayPayment | null>(null);

async function load(): Promise<void> {
    if (! props.bookingId) return;
    loading.value = true;
    gatewayPayment.value = null;
    try {
        booking.value = await apiRequest<BookingDetail>(`/api/bookings/${props.bookingId}`, { tenant: props.tenantSlug });
        newStatus.value = booking.value.status;
        const d = new Date(booking.value.starts_at);
        rescheduleDate.value = d.toISOString().slice(0, 10);
        rescheduleTime.value = d.toTimeString().slice(0, 5);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

watch(() => [props.open, props.bookingId], () => { if (props.open) load(); });

async function changeStatus(): Promise<void> {
    if (! booking.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/bookings/${booking.value.id}/status`, { method: 'PATCH', body: { status: newStatus.value }, tenant: props.tenantSlug });
        toast.success(locale.t('booking.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function reschedule(): Promise<void> {
    if (! booking.value || ! rescheduleDate.value || ! rescheduleTime.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/bookings/${booking.value.id}/reschedule`, {
            method: 'PATCH',
            body: { starts_at: `${rescheduleDate.value}T${rescheduleTime.value}:00` },
            tenant: props.tenantSlug,
        });
        toast.success(locale.t('booking.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function cancelBooking(): Promise<void> {
    if (! booking.value || ! cancelReason.value.trim()) return;
    busy.value = true;
    try {
        await apiRequest(`/api/bookings/${booking.value.id}/cancel`, { method: 'POST', body: { reason: cancelReason.value }, tenant: props.tenantSlug });
        toast.success(locale.t('booking.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

function onFileChange(event: Event): void {
    proofFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

async function uploadProof(): Promise<void> {
    if (! booking.value || ! proofFile.value) return;
    busy.value = true;
    try {
        const body = new FormData();
        body.append('file', proofFile.value);
        if (proofAmount.value) body.append('amount', String(proofAmount.value));
        if (proofOperation.value) body.append('operation_number', proofOperation.value);
        await apiRequest(`/api/bookings/${booking.value.id}/payment-proof`, { method: 'POST', body, tenant: props.tenantSlug });
        toast.success(locale.t('booking.saved'));
        proofFile.value = null;
        proofAmount.value = null;
        proofOperation.value = '';
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function createGatewayPayment(): Promise<void> {
    if (! booking.value) return;
    busy.value = true;
    try {
        gatewayPayment.value = await apiRequest<GatewayPayment>(`/api/bookings/${booking.value.id}/gateway-payment`, {
            method: 'POST',
            body: { gateway: 'alif' },
            tenant: props.tenantSlug,
        });
        toast.success('Счёт создан — скопируйте ссылку и отправьте клиенту');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось создать счёт на оплату');
    } finally {
        busy.value = false;
    }
}

async function reviewProof(proof: PaymentProof, decision: 'confirmed' | 'rejected' | 'resubmission_requested'): Promise<void> {
    if (! booking.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/bookings/${booking.value.id}/payment-proof/${proof.id}`, { method: 'PATCH', body: { decision }, tenant: props.tenantSlug });
        toast.success(locale.t('booking.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function markCashPaid(): Promise<void> {
    if (! booking.value || ! confirm(locale.t('booking.markCashPaidConfirm'))) return;
    busy.value = true;
    try {
        await apiRequest(`/api/bookings/${booking.value.id}/mark-cash-paid`, { method: 'POST', tenant: props.tenantSlug });
        toast.success(locale.t('booking.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function refundAction(action: 'request' | 'processing' | 'refunded' | 'rejected'): Promise<void> {
    if (! booking.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/bookings/${booking.value.id}/refund`, { method: 'POST', body: { action }, tenant: props.tenantSlug });
        toast.success(locale.t('booking.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

const isActive = computed(() => booking.value && ! ['completed', 'cancelled', 'no_show'].includes(booking.value.status));
const pendingProof = computed(() => booking.value?.payment_proofs.find((p) => p.status === 'pending') ?? null);
const canMarkCash = computed(() => booking.value && booking.value.prepayment_amount > 0 && booking.value.prepayment_status !== 'confirmed' && ! ['refund_pending', 'refund_processing', 'refunded'].includes(booking.value.prepayment_status));
const canRequestRefund = computed(() => booking.value?.prepayment_status === 'confirmed');
const refundInFlight = computed(() => booking.value && ['refund_pending', 'refund_processing'].includes(booking.value.prepayment_status));
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ locale.t('booking.bookingDetails') }}</DialogTitle>
            </DialogHeader>

            <div v-if="loading" class="space-y-2 py-4">
                <Skeleton v-for="i in 5" :key="i" class="h-10 rounded-lg" />
            </div>
            <div v-else-if="booking" class="grid max-h-[75vh] gap-5 overflow-y-auto py-4 text-sm">
                <section class="grid gap-1">
                    <p class="font-medium ui-text">{{ booking.customer?.name }} <span class="ui-subtle">· {{ booking.customer?.phone }}</span></p>
                    <p class="ui-subtle">{{ booking.service?.name }} · {{ booking.employee?.name }}<span v-if="booking.resource"> · {{ booking.resource.name }}</span></p>
                    <p class="font-medium tabular-nums ui-text">{{ new Date(booking.starts_at).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }} — {{ new Date(booking.ends_at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) }}</p>
                    <p class="ui-subtle">{{ locale.t('booking.price') }}: <Money :value="booking.price" tone="muted" /><span v-if="booking.prepayment_amount"> · {{ locale.t('booking.prepayment') }}: <Money :value="booking.prepayment_amount" tone="muted" /> ({{ locale.t('booking.prepaymentStatuses.' + booking.prepayment_status) }})</span></p>
                    <p v-if="booking.notes" class="ui-subtle">{{ booking.notes }}</p>
                    <p class="font-medium ui-text">{{ locale.t('booking.statuses.' + booking.status) }}</p>
                </section>

                <section v-if="isActive" class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('booking.markStatus') || 'Status' }}</p>
                    <div class="flex gap-2">
                        <Select v-model="newStatus">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="s in STATUS_OPTIONS" :key="s" :value="s">{{ locale.t('booking.statuses.' + s) }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button :disabled="busy" @click="changeStatus">{{ locale.t('booking.save') }}</Button>
                    </div>
                </section>

                <section v-if="pendingProof" class="grid gap-2 rounded-lg border border-border p-3">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('booking.paymentReview') }}</p>
                    <a :href="pendingProof.file_url" target="_blank" class="text-xs text-primary underline">{{ pendingProof.file_url }}</a>
                    <p v-if="pendingProof.amount" class="text-xs ui-subtle">{{ locale.t('booking.proofAmount') }}: <Money :value="pendingProof.amount" tone="muted" /></p>
                    <p v-if="pendingProof.operation_number" class="text-xs ui-subtle">{{ locale.t('booking.proofOperation') }}: {{ pendingProof.operation_number }}</p>
                    <div class="flex flex-wrap gap-2">
                        <Button size="sm" :disabled="busy" @click="reviewProof(pendingProof, 'confirmed')">{{ locale.t('booking.confirmPayment') }}</Button>
                        <Button size="sm" variant="outline" :disabled="busy" @click="reviewProof(pendingProof, 'resubmission_requested')">{{ locale.t('booking.requestNewScreenshot') }}</Button>
                        <Button size="sm" variant="outline" :disabled="busy" @click="reviewProof(pendingProof, 'rejected')">{{ locale.t('booking.rejectPayment') }}</Button>
                    </div>
                </section>

                <section v-else-if="isActive && booking.prepayment_amount > 0 && booking.prepayment_status !== 'confirmed'" class="grid gap-2 rounded-lg border border-border p-3">
                    <p class="text-xs font-medium ui-subtle">Оплата онлайн (Alif Pay)</p>
                    <p v-if="! gatewayPayment" class="text-xs ui-subtle">Создать счёт и получить ссылку на оплату для клиента.</p>
                    <Button v-if="! gatewayPayment?.checkout_url" size="sm" variant="outline" class="w-fit" :disabled="busy" @click="createGatewayPayment">Создать ссылку на оплату</Button>
                    <CodeBlock v-else :code="gatewayPayment.checkout_url" label="Ссылка на оплату" wrap />

                    <p class="mt-2 text-xs font-medium ui-subtle">{{ locale.t('booking.uploadProof') }}</p>
                    <input type="file" accept="image/*,.pdf" class="text-xs" @change="onFileChange">
                    <div class="flex gap-2">
                        <InputGroup v-model.number="proofAmount" type="number" :placeholder="locale.t('booking.proofAmount')" class="w-32">
                            <template #suffix>{{ locale.t('commerce.currency') }}</template>
                        </InputGroup>
                        <Input v-model="proofOperation" :placeholder="locale.t('booking.proofOperation')" />
                    </div>
                    <Button size="sm" class="w-fit" :disabled="busy || ! proofFile" @click="uploadProof">{{ locale.t('booking.uploadProof') }}</Button>

                    <Button v-if="canMarkCash" size="sm" variant="outline" class="mt-2 w-fit" :disabled="busy" @click="markCashPaid">{{ locale.t('booking.markCashPaid') }}</Button>
                </section>

                <section v-if="canRequestRefund || refundInFlight" class="grid gap-2 rounded-lg border border-border p-3">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('booking.refundSection') }}: {{ locale.t('booking.prepaymentStatuses.' + booking.prepayment_status) }}</p>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="canRequestRefund" size="sm" variant="outline" :disabled="busy" @click="refundAction('request')">{{ locale.t('booking.requestRefund') }}</Button>
                        <template v-if="refundInFlight">
                            <Button size="sm" variant="outline" :disabled="busy" @click="refundAction('processing')">{{ locale.t('booking.refundMarkProcessing') }}</Button>
                            <Button size="sm" :disabled="busy" @click="refundAction('refunded')">{{ locale.t('booking.markRefunded') }}</Button>
                            <Button size="sm" variant="outline" :disabled="busy" @click="refundAction('rejected')">{{ locale.t('booking.rejectRefund') }}</Button>
                        </template>
                    </div>
                </section>

                <section v-if="isActive" class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('booking.reschedule') }}</p>
                    <div class="flex gap-2">
                        <DatePicker v-model="rescheduleDate" />
                        <Input v-model="rescheduleTime" type="time" />
                        <Button variant="outline" :disabled="busy" @click="reschedule">{{ locale.t('booking.reschedule') }}</Button>
                    </div>
                </section>

                <section v-if="isActive" class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('booking.cancelBooking') }}</p>
                    <div class="flex gap-2">
                        <Textarea v-model="cancelReason" :placeholder="locale.t('booking.cancelReason')" class="min-h-10" />
                        <Button variant="destructive" :disabled="busy || ! cancelReason.trim()" @click="cancelBooking">{{ locale.t('booking.cancelBooking') }}</Button>
                    </div>
                </section>

                <section v-if="booking.status_history?.length" class="grid gap-1">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('booking.history') }}</p>
                    <div class="grid gap-1 text-xs ui-subtle">
                        <p v-for="h in booking.status_history" :key="h.id">
                            {{ new Date(h.created_at).toLocaleString() }} · {{ locale.t('booking.statuses.' + h.new_status) }}<span v-if="h.changed_by"> · {{ h.changed_by.name }}</span><span v-if="h.comment"> · {{ h.comment }}</span>
                        </p>
                    </div>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
