<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Pencil } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Badge } from '../../ui/badge';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Skeleton } from '../../ui/skeleton';
import NewTourBookingDialog from './NewTourBookingDialog.vue';
import TourDepartureFormDialog, { type TourDepartureRow } from './TourDepartureFormDialog.vue';

type BookingRow = { id: number; status: string; pax_count: number; customer: { id: number; name: string; phone: string | null } | null };
type DepartureDetail = {
    id: number; departure_date: string; return_date: string | null; status: string; capacity: number | null; price: number | null; notes: string | null;
    tour: { id: number; name: string; destination: string | null; price: number } | null;
    bookings: BookingRow[];
};

const STATUS_OPTIONS = ['open', 'closed', 'departed', 'completed', 'cancelled'];

const props = defineProps<{ open: boolean; departureId: number | null; companyId: number; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; changed: [] }>();
const locale = useLocaleStore();

const departure = ref<DepartureDetail | null>(null);
const loading = ref(true);
const busy = ref(false);
const newStatus = ref('open');
const editOpen = ref(false);
const bookOpen = ref(false);

async function load(): Promise<void> {
    if (! props.departureId) return;
    loading.value = true;
    try {
        departure.value = await apiRequest<DepartureDetail>(`/api/tour-departures/${props.departureId}`, { tenant: props.tenantSlug });
        newStatus.value = departure.value.status;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

watch(() => [props.open, props.departureId], () => { if (props.open) load(); });

async function changeStatus(): Promise<void> {
    if (! departure.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/tour-departures/${departure.value.id}`, { method: 'PATCH', body: { status: newStatus.value }, tenant: props.tenantSlug });
        toast.success(locale.t('travel.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function confirmBooking(booking: BookingRow): Promise<void> {
    busy.value = true;
    try {
        await apiRequest(`/api/tour-bookings/${booking.id}/confirm`, { method: 'POST', tenant: props.tenantSlug });
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function completeBooking(booking: BookingRow): Promise<void> {
    busy.value = true;
    try {
        await apiRequest(`/api/tour-bookings/${booking.id}/complete`, { method: 'POST', tenant: props.tenantSlug });
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function cancelBooking(booking: BookingRow): Promise<void> {
    const reason = prompt(locale.t('education.cancelReason'));
    if (! reason) return;
    busy.value = true;
    try {
        await apiRequest(`/api/tour-bookings/${booking.id}/cancel`, { method: 'POST', body: { reason }, tenant: props.tenantSlug });
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

const departureForEdit = computed<TourDepartureRow | null>(() => {
    if (! departure.value) return null;
    return {
        id: departure.value.id,
        tour_id: departure.value.tour?.id ?? 0,
        departure_date: departure.value.departure_date,
        return_date: departure.value.return_date,
        capacity: departure.value.capacity,
        price: departure.value.price,
        status: departure.value.status,
        notes: departure.value.notes,
    };
});

const bookedSeats = computed(() => (departure.value?.bookings ?? []).filter((b) => b.status === 'requested' || b.status === 'confirmed').reduce((sum, b) => sum + b.pax_count, 0));

const BOOKING_TONE: Record<string, 'neutral' | 'green' | 'amber' | 'red' | 'blue'> = {
    requested: 'amber', confirmed: 'blue', completed: 'green', cancelled: 'red',
};

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-[36.8rem]">
            <DialogHeader>
                <DialogTitle>{{ departure?.tour?.name ?? locale.t('travel.departureDetails') }}</DialogTitle>
            </DialogHeader>

            <div v-if="loading" class="space-y-2 py-4">
                <Skeleton v-for="i in 5" :key="i" class="h-10 rounded-lg" />
            </div>
            <div v-else-if="departure" class="grid max-h-[75vh] gap-5 overflow-y-auto py-4 text-sm">
                <section class="grid gap-1">
                    <div class="flex items-center justify-between">
                        <p class="font-medium ui-text">{{ departure.tour?.destination }}</p>
                        <Button variant="ghost" size="icon" @click="editOpen = true"><Pencil class="h-4 w-4" /></Button>
                    </div>
                    <p class="ui-subtle">{{ formatDate(departure.departure_date) }}<span v-if="departure.return_date"> — {{ formatDate(departure.return_date) }}</span></p>
                    <p v-if="departure.capacity" class="ui-subtle">{{ locale.t('travel.seats') }}: {{ bookedSeats }}/{{ departure.capacity }}</p>
                    <p v-if="departure.notes" class="ui-subtle">{{ departure.notes }}</p>
                    <p class="font-medium ui-text">{{ locale.t('travel.statuses.' + departure.status) }}</p>
                </section>

                <section class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('travel.changeStatus') }}</p>
                    <div class="flex gap-2">
                        <Select v-model="newStatus">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="s in STATUS_OPTIONS" :key="s" :value="s">{{ locale.t('travel.statuses.' + s) }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button :disabled="busy" @click="changeStatus">{{ locale.t('education.save') }}</Button>
                    </div>
                </section>

                <section class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium ui-subtle">{{ locale.t('travel.bookingsList') }}</p>
                        <Button size="sm" variant="outline" @click="bookOpen = true">{{ locale.t('travel.newBooking') }}</Button>
                    </div>
                    <div v-if="! departure.bookings.length" class="text-xs ui-subtle">{{ locale.t('travel.noBookings') }}</div>
                    <div v-for="b in departure.bookings" :key="b.id" class="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2 text-xs">
                        <span class="ui-text">{{ b.customer?.name }}<span class="ui-subtle"> · {{ b.customer?.phone }} · {{ b.pax_count }} {{ locale.t('travel.paxUnit') }}</span></span>
                        <div class="flex items-center gap-2">
                            <Badge :tone="BOOKING_TONE[b.status] ?? 'neutral'">{{ locale.t('travel.bookingStatuses.' + b.status) }}</Badge>
                            <template v-if="b.status === 'requested'">
                                <Button size="sm" variant="ghost" :disabled="busy" @click="confirmBooking(b)">{{ locale.t('travel.confirm') }}</Button>
                            </template>
                            <template v-if="b.status === 'requested' || b.status === 'confirmed'">
                                <Button size="sm" variant="ghost" :disabled="busy" @click="completeBooking(b)">{{ locale.t('education.markCompleted') }}</Button>
                                <Button size="sm" variant="ghost" :disabled="busy" @click="cancelBooking(b)">{{ locale.t('education.cancel') }}</Button>
                            </template>
                        </div>
                    </div>
                </section>
            </div>

            <TourDepartureFormDialog v-model:open="editOpen" :departure="departureForEdit" :company-id="companyId" :tenant-slug="tenantSlug" @saved="load" />
            <NewTourBookingDialog v-model:open="bookOpen" :tour-departure-id="departureId" :company-id="companyId" :tenant-slug="tenantSlug" @booked="load" />
        </DialogContent>
    </Dialog>
</template>
