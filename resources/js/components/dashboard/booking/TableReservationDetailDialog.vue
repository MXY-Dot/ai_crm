<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { DatePicker } from '../../ui/date-picker';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input } from '../../ui/input';
import { Money } from '../../ui/money';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Skeleton } from '../../ui/skeleton';
import { Textarea } from '../../ui/textarea';

type ReservationDetail = {
    id: number; status: string; party_size: number; starts_at: string; ends_at: string; notes: string | null;
    customer: { name: string; phone: string | null } | null;
    resource: { name: string; capacity: number | null } | null;
    status_history: { id: number; old_status: string | null; new_status: string; comment: string | null; changed_by: { name: string } | null; created_at: string }[];
    orders: { id: number; status: string; total: number }[];
};

const STATUS_OPTIONS = ['confirmed', 'seated', 'completed', 'no_show'];

const props = defineProps<{ open: boolean; reservationId: number | null; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; changed: [] }>();
const locale = useLocaleStore();

const reservation = ref<ReservationDetail | null>(null);
const loading = ref(true);
const busy = ref(false);
const newStatus = ref('confirmed');
const rescheduleDate = ref('');
const rescheduleTime = ref('');
const cancelReason = ref('');

async function load(): Promise<void> {
    if (! props.reservationId) return;
    loading.value = true;
    try {
        reservation.value = await apiRequest<ReservationDetail>(`/api/table-reservations/${props.reservationId}`, { tenant: props.tenantSlug });
        newStatus.value = reservation.value.status;
        const d = new Date(reservation.value.starts_at);
        rescheduleDate.value = d.toISOString().slice(0, 10);
        rescheduleTime.value = d.toTimeString().slice(0, 5);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

watch(() => [props.open, props.reservationId], () => { if (props.open) load(); });

const isActive = computed(() => reservation.value && ! ['completed', 'cancelled', 'no_show'].includes(reservation.value.status));

async function changeStatus(): Promise<void> {
    if (! reservation.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/table-reservations/${reservation.value.id}/status`, { method: 'PATCH', body: { status: newStatus.value }, tenant: props.tenantSlug });
        toast.success(locale.t('restaurant.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function reschedule(): Promise<void> {
    if (! reservation.value || ! rescheduleDate.value || ! rescheduleTime.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/table-reservations/${reservation.value.id}/reschedule`, {
            method: 'PATCH',
            body: { starts_at: `${rescheduleDate.value}T${rescheduleTime.value}:00` },
            tenant: props.tenantSlug,
        });
        toast.success(locale.t('restaurant.saved'));
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
        await apiRequest(`/api/table-reservations/${reservation.value.id}/cancel`, { method: 'POST', body: { reason: cancelReason.value }, tenant: props.tenantSlug });
        toast.success(locale.t('restaurant.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ locale.t('restaurant.reservationDetails') }}</DialogTitle>
            </DialogHeader>

            <div v-if="loading" class="space-y-2 py-4">
                <Skeleton v-for="i in 4" :key="i" class="h-10 rounded-lg" />
            </div>
            <div v-else-if="reservation" class="grid max-h-[75vh] gap-5 overflow-y-auto py-4 text-sm">
                <section class="grid gap-1">
                    <p class="font-medium ui-text">{{ reservation.customer?.name }} <span class="ui-subtle">· {{ reservation.customer?.phone }}</span></p>
                    <p class="ui-subtle">{{ reservation.resource?.name }}<span v-if="reservation.resource?.capacity"> · {{ locale.t('restaurant.tableCapacity') }}: {{ reservation.resource.capacity }}</span></p>
                    <p class="font-medium tabular-nums ui-text">{{ formatDateTime(reservation.starts_at) }} — {{ new Date(reservation.ends_at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) }}</p>
                    <p class="ui-subtle">{{ locale.t('restaurant.partySize') }}: {{ reservation.party_size }}</p>
                    <p v-if="reservation.notes" class="ui-subtle">{{ reservation.notes }}</p>
                    <p class="font-medium ui-text">{{ locale.t('restaurant.statuses.' + reservation.status) }}</p>
                </section>

                <section v-if="reservation.orders?.length" class="grid gap-2 rounded-lg border border-border p-3">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('restaurant.preOrders') }}</p>
                    <div v-for="o in reservation.orders" :key="o.id" class="flex items-center justify-between text-xs">
                        <span class="ui-text">{{ locale.t('commerce.orderNumber') }} #{{ o.id }} · {{ locale.t('commerce.statuses.' + o.status) }}</span>
                        <Money :value="o.total" tone="muted" />
                    </div>
                </section>

                <section v-if="isActive" class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('restaurant.changeStatus') }}</p>
                    <div class="flex gap-2">
                        <Select v-model="newStatus">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="s in STATUS_OPTIONS" :key="s" :value="s">{{ locale.t('restaurant.statuses.' + s) }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button :disabled="busy" @click="changeStatus">{{ locale.t('restaurant.save') }}</Button>
                    </div>
                </section>

                <section v-if="isActive" class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('restaurant.reschedule') }}</p>
                    <div class="flex gap-2">
                        <DatePicker v-model="rescheduleDate" />
                        <Input v-model="rescheduleTime" type="time" />
                        <Button variant="outline" :disabled="busy" @click="reschedule">{{ locale.t('restaurant.reschedule') }}</Button>
                    </div>
                </section>

                <section v-if="isActive" class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('restaurant.cancelReservation') }}</p>
                    <div class="flex gap-2">
                        <Textarea v-model="cancelReason" :placeholder="locale.t('restaurant.cancelReason')" class="min-h-10" />
                        <Button variant="destructive" :disabled="busy || ! cancelReason.trim()" @click="cancelReservation">{{ locale.t('restaurant.cancelReservation') }}</Button>
                    </div>
                </section>

                <section v-if="reservation.status_history?.length" class="grid gap-1">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('restaurant.history') }}</p>
                    <div class="grid gap-1 text-xs ui-subtle">
                        <p v-for="h in reservation.status_history" :key="h.id">
                            <span class="font-medium tabular-nums ui-text">{{ formatDateTime(h.created_at) }}</span> · {{ locale.t('restaurant.statuses.' + h.new_status) }}<span v-if="h.changed_by"> · {{ h.changed_by.name }}</span><span v-if="h.comment"> · {{ h.comment }}</span>
                        </p>
                    </div>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
