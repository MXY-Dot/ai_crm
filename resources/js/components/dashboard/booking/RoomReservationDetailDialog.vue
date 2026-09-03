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
type ReservationDetail = {
    id: number; status: string; starts_at: string; ends_at: string; guests_count: number;
    total_amount: number; prepayment_amount: number; prepayment_status: string; notes: string | null;
    customer: { name: string; phone: string | null } | null; resource: { name: string; capacity: number | null } | null;
    status_history: StatusEntry[]; payment_proofs: PaymentProof[];
};

const STATUS_OPTIONS = ['confirmed', 'checked_in', 'checked_out', 'no_show'];

const props = defineProps<{ open: boolean; reservationId: number | null; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; changed: [] }>();
const locale = useLocaleStore();

const reservation = ref<ReservationDetail | null>(null);
const loading = ref(true);
const busy = ref(false);
const newStatus = ref('confirmed');
const rescheduleCheckIn = ref('');
const rescheduleCheckOut = ref('');
const cancelReason = ref('');
const proofAmount = ref<number | null>(null);
const proofOperation = ref('');
const proofFile = ref<File | null>(null);
const gatewayPayment = ref<GatewayPayment | null>(null);

async function load(): Promise<void> {
    if (! props.reservationId) return;
    loading.value = true;
    gatewayPayment.value = null;
    try {
        reservation.value = await apiRequest<ReservationDetail>(`/api/room-reservations/${props.reservationId}`, { tenant: props.tenantSlug });
        newStatus.value = reservation.value.status;
        rescheduleCheckIn.value = reservation.value.starts_at.slice(0, 10);
        rescheduleCheckOut.value = reservation.value.ends_at.slice(0, 10);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

watch(() => [props.open, props.reservationId], () => { if (props.open) load(); });

const isActive = computed(() => reservation.value && ! ['checked_out', 'cancelled', 'no_show'].includes(reservation.value.status));

async function changeStatus(): Promise<void> {
    if (! reservation.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/room-reservations/${reservation.value.id}/status`, { method: 'PATCH', body: { status: newStatus.value }, tenant: props.tenantSlug });
        toast.success(locale.t('hotel.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function reschedule(): Promise<void> {
    if (! reservation.value || ! rescheduleCheckIn.value || ! rescheduleCheckOut.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/room-reservations/${reservation.value.id}/reschedule`, {
            method: 'PATCH',
            body: { starts_at: `${rescheduleCheckIn.value}T14:00:00`, ends_at: `${rescheduleCheckOut.value}T12:00:00` },
            tenant: props.tenantSlug,
        });
        toast.success(locale.t('hotel.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function cancelReservation(): Promise<void> {
    if (! reservation.value || ! cancelReason.value.trim()) return;
    busy.value = true;
    try {
        await apiRequest(`/api/room-reservations/${reservation.value.id}/cancel`, { method: 'POST', body: { reason: cancelReason.value }, tenant: props.tenantSlug });
        toast.success(locale.t('hotel.saved'));
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
    if (! reservation.value || ! proofFile.value) return;
    busy.value = true;
    try {
        const body = new FormData();
        body.append('file', proofFile.value);
        if (proofAmount.value) body.append('amount', String(proofAmount.value));
        if (proofOperation.value) body.append('operation_number', proofOperation.value);
        await apiRequest(`/api/room-reservations/${reservation.value.id}/payment-proof`, { method: 'POST', body, tenant: props.tenantSlug });
        toast.success(locale.t('hotel.saved'));
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
    if (! reservation.value) return;
    busy.value = true;
    try {
        gatewayPayment.value = await apiRequest<GatewayPayment>(`/api/room-reservations/${reservation.value.id}/gateway-payment`, {
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
    if (! reservation.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/room-reservations/${reservation.value.id}/payment-proof/${proof.id}`, { method: 'PATCH', body: { decision }, tenant: props.tenantSlug });
        toast.success(locale.t('hotel.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function markCashPaid(): Promise<void> {
    if (! reservation.value || ! confirm(locale.t('booking.markCashPaidConfirm'))) return;
    busy.value = true;
    try {
        await apiRequest(`/api/room-reservations/${reservation.value.id}/mark-cash-paid`, { method: 'POST', tenant: props.tenantSlug });
        toast.success(locale.t('hotel.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function refundAction(action: 'request' | 'processing' | 'refunded' | 'rejected'): Promise<void> {
    if (! reservation.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/room-reservations/${reservation.value.id}/refund`, { method: 'POST', body: { action }, tenant: props.tenantSlug });
        toast.success(locale.t('hotel.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

const pendingProof = computed(() => reservation.value?.payment_proofs.find((p) => p.status === 'pending') ?? null);
const canMarkCash = computed(() => reservation.value && reservation.value.prepayment_amount > 0 && reservation.value.prepayment_status !== 'confirmed' && ! ['refund_pending', 'refund_processing', 'refunded'].includes(reservation.value.prepayment_status));
const canRequestRefund = computed(() => reservation.value?.prepayment_status === 'confirmed');
const refundInFlight = computed(() => reservation.value && ['refund_pending', 'refund_processing'].includes(reservation.value.prepayment_status));

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-[36.8rem]">
            <DialogHeader>
                <DialogTitle>{{ locale.t('hotel.reservationDetails') }}</DialogTitle>
            </DialogHeader>

            <div v-if="loading" class="space-y-2 py-4">
                <Skeleton v-for="i in 5" :key="i" class="h-10 rounded-lg" />
            </div>
            <div v-else-if="reservation" class="grid max-h-[75vh] gap-5 overflow-x-hidden overflow-y-auto py-4 text-sm">
                <section class="grid gap-1">
                    <p class="font-medium ui-text">{{ reservation.customer?.name }} <span class="ui-subtle">· {{ reservation.customer?.phone }}</span></p>
                    <p class="ui-subtle">{{ reservation.resource?.name }}<span v-if="reservation.resource?.capacity"> · {{ locale.t('hotel.roomCapacity') }}: {{ reservation.resource.capacity }}</span> · {{ locale.t('hotel.guests') }}: {{ reservation.guests_count }}</p>
                    <p class="font-medium tabular-nums ui-text">{{ locale.t('hotel.checkIn') }} {{ formatDate(reservation.starts_at) }} — {{ locale.t('hotel.checkOut') }} {{ formatDate(reservation.ends_at) }}</p>
                    <p class="ui-subtle">{{ locale.t('hotel.total') }}: <Money :value="reservation.total_amount" tone="muted" /><span v-if="reservation.prepayment_amount"> · {{ locale.t('booking.prepayment') }}: <Money :value="reservation.prepayment_amount" tone="muted" /> ({{ locale.t('booking.prepaymentStatuses.' + reservation.prepayment_status) }})</span></p>
                    <p v-if="reservation.notes" class="ui-subtle">{{ reservation.notes }}</p>
                    <p class="font-medium ui-text">{{ locale.t('hotel.statuses.' + reservation.status) }}</p>
                </section>

                <section v-if="isActive" class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('hotel.changeStatus') }}</p>
                    <div class="flex gap-2">
                        <Select v-model="newStatus">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="s in STATUS_OPTIONS" :key="s" :value="s">{{ locale.t('hotel.statuses.' + s) }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button :disabled="busy" @click="changeStatus">{{ locale.t('hotel.save') }}</Button>
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

                <section v-else-if="isActive && reservation.prepayment_amount > 0 && reservation.prepayment_status !== 'confirmed'" class="grid gap-2 rounded-lg border border-border p-3">
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
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('booking.refundSection') }}: {{ locale.t('booking.prepaymentStatuses.' + reservation.prepayment_status) }}</p>
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
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('hotel.reschedule') }}</p>
                    <div class="flex gap-2">
                        <DatePicker v-model="rescheduleCheckIn" />
                        <DatePicker v-model="rescheduleCheckOut" />
                        <Button variant="outline" :disabled="busy" @click="reschedule">{{ locale.t('hotel.reschedule') }}</Button>
                    </div>
                </section>

                <section v-if="isActive" class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('hotel.cancelReservation') }}</p>
                    <div class="flex gap-2">
                        <Textarea v-model="cancelReason" :placeholder="locale.t('hotel.cancelReason')" class="min-h-10" />
                        <Button variant="destructive" :disabled="busy || ! cancelReason.trim()" @click="cancelReservation">{{ locale.t('hotel.cancelReservation') }}</Button>
                    </div>
                </section>

                <section v-if="reservation.status_history?.length" class="grid gap-1">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('hotel.history') }}</p>
                    <div class="grid gap-1 text-xs ui-subtle">
                        <p v-for="h in reservation.status_history" :key="h.id">
                            {{ new Date(h.created_at).toLocaleString() }} · {{ locale.t('hotel.statuses.' + h.new_status) }}<span v-if="h.changed_by"> · {{ h.changed_by.name }}</span><span v-if="h.comment"> · {{ h.comment }}</span>
                        </p>
                    </div>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
