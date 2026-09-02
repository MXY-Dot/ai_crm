<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { DatePicker } from '../../ui/date-picker';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { InputGroup } from '../../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Textarea } from '../../ui/textarea';

type Tour = { id: number; name: string };

export type TourDepartureRow = {
    id: number; tour_id: number; departure_date: string; return_date: string | null;
    capacity: number | null; price: number | null; status: string; notes: string | null;
};

const props = defineProps<{ open: boolean; departure: TourDepartureRow | null; companyId: number; tenantSlug: string; defaultTourId?: number | null }>();
const emit = defineEmits<{ 'update:open': [boolean]; saved: [] }>();
const locale = useLocaleStore();

const tours = ref<Tour[]>([]);
const tourId = ref<number | null>(null);
const departureDate = ref('');
const returnDate = ref('');
const capacity = ref<number | null>(null);
const price = ref<number | null>(null);
const notes = ref('');
const saving = ref(false);

async function loadTours(): Promise<void> {
    try {
        const data = await apiRequest<{ data: Tour[] }>('/api/tours', { tenant: props.tenantSlug });
        tours.value = data.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}

onMounted(loadTours);

watch(() => props.open, (open) => {
    if (! open) return;
    loadTours();
    if (props.departure) {
        tourId.value = props.departure.tour_id;
        departureDate.value = props.departure.departure_date.slice(0, 10);
        returnDate.value = props.departure.return_date ? props.departure.return_date.slice(0, 10) : '';
        capacity.value = props.departure.capacity;
        price.value = props.departure.price;
        notes.value = props.departure.notes ?? '';
    } else {
        tourId.value = props.defaultTourId ?? null;
        departureDate.value = '';
        returnDate.value = '';
        capacity.value = null;
        price.value = null;
        notes.value = '';
    }
});

const tourValue = computed({ get: () => (tourId.value ? String(tourId.value) : ''), set: (v: string) => { tourId.value = v ? Number(v) : null; } });
const canSubmit = computed(() => !! tourId.value && !! departureDate.value);

async function submit(): Promise<void> {
    if (! canSubmit.value) return;
    saving.value = true;
    try {
        const payload = {
            company_id: props.companyId,
            tour_id: tourId.value,
            departure_date: departureDate.value,
            return_date: returnDate.value || null,
            capacity: capacity.value,
            price: price.value,
            notes: notes.value || null,
        };
        if (props.departure) {
            await apiRequest(`/api/tour-departures/${props.departure.id}`, { method: 'PATCH', body: payload, tenant: props.tenantSlug });
        } else {
            await apiRequest('/api/tour-departures', { method: 'POST', body: payload, tenant: props.tenantSlug });
        }
        toast.success(locale.t('travel.saved'));
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
        <DialogContent class="sm:max-w-sm">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ departure ? locale.t('travel.editDeparture') : locale.t('travel.addDeparture') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <Select v-model="tourValue">
                        <SelectTrigger class="w-full"><SelectValue :placeholder="locale.t('education.selectCourse')" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="t in tours" :key="t.id" :value="String(t.id)">{{ t.name }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('travel.departureDate') }}
                            <DatePicker v-model="departureDate" class="w-full" />
                        </label>
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('travel.returnDate') }}
                            <DatePicker v-model="returnDate" class="w-full" />
                        </label>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <InputGroup v-model.number="capacity" type="number" min="1" :placeholder="locale.t('travel.seats')">
                            <template #suffix>{{ locale.t('travel.seatsUnit') }}</template>
                        </InputGroup>
                        <InputGroup v-model.number="price" type="number" min="0" step="0.01" :placeholder="locale.t('travel.priceOverride')">
                            <template #suffix>{{ locale.t('commerce.currency') }}</template>
                        </InputGroup>
                    </div>
                    <Textarea v-model="notes" :placeholder="locale.t('booking.notes')" class="min-h-16" />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving || ! canSubmit">{{ locale.t('booking.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
