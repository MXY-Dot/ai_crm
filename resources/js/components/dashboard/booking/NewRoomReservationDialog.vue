<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { DatePicker } from '../../ui/date-picker';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input, InputGroup } from '../../ui/input';
import { Money } from '../../ui/money';

type Room = { resource_id: number; resource_name: string; capacity: number | null; price_per_night: number };
type Customer = { id: number; name: string; phone: string | null };

const props = defineProps<{
    open: boolean;
    companyId: number;
    tenantSlug: string;
    customers: Customer[];
}>();
const emit = defineEmits<{ 'update:open': [boolean]; created: [] }>();
const locale = useLocaleStore();

function defaultCheckIn(): string {
    return new Date().toISOString().slice(0, 10);
}
function defaultCheckOut(): string {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    return d.toISOString().slice(0, 10);
}

const guests = ref(1);
const checkIn = ref(defaultCheckIn());
const checkOut = ref(defaultCheckOut());
const rooms = ref<Room[]>([]);
const selectedRoom = ref<Room | null>(null);
const loadingRooms = ref(false);
const customerMode = ref<'existing' | 'new'>('new');
const customerId = ref<number | null>(null);
const customerName = ref('');
const customerPhone = ref('');
const notes = ref('');
const saving = ref(false);

watch(() => props.open, (open) => {
    if (! open) return;
    guests.value = 1;
    checkIn.value = defaultCheckIn();
    checkOut.value = defaultCheckOut();
    selectedRoom.value = null;
    customerMode.value = 'new';
    customerId.value = null;
    customerName.value = '';
    customerPhone.value = '';
    notes.value = '';
});

async function loadRooms(): Promise<void> {
    if (! guests.value || ! checkIn.value || ! checkOut.value || checkOut.value <= checkIn.value) {
        rooms.value = [];
        return;
    }
    loadingRooms.value = true;
    selectedRoom.value = null;
    try {
        const data = await apiRequest<{ rooms: Room[] }>('/api/room-availability?' + new URLSearchParams({
            company_id: String(props.companyId),
            guests: String(guests.value),
            check_in: checkIn.value,
            check_out: checkOut.value,
        }), { tenant: props.tenantSlug });
        rooms.value = data.rooms;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loadingRooms.value = false;
    }
}

watch([guests, checkIn, checkOut, () => props.open], () => { if (props.open) loadRooms(); });

const nights = computed(() => {
    if (! checkIn.value || ! checkOut.value) return 0;
    const diff = (new Date(checkOut.value).getTime() - new Date(checkIn.value).getTime()) / 86400000;
    return Math.max(1, Math.round(diff));
});

const filteredCustomers = computed(() => {
    const q = customerPhone.value.trim();
    if (! q) return [];
    return props.customers.filter((c) => c.phone?.includes(q)).slice(0, 5);
});

async function submit(): Promise<void> {
    if (! selectedRoom.value) return;
    saving.value = true;
    try {
        await apiRequest('/api/room-reservations', {
            method: 'POST',
            body: {
                company_id: props.companyId,
                resource_id: selectedRoom.value.resource_id,
                guests_count: guests.value,
                starts_at: checkIn.value + 'T14:00:00',
                ends_at: checkOut.value + 'T12:00:00',
                notes: notes.value || null,
                ...(customerMode.value === 'existing'
                    ? { customer_id: customerId.value }
                    : { customer_name: customerName.value, customer_phone: customerPhone.value }),
            },
            tenant: props.tenantSlug,
        });
        toast.success(locale.t('hotel.saved'));
        emit('update:open', false);
        emit('created');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        saving.value = false;
    }
}

const canSubmit = computed(() => !! selectedRoom.value && (customerMode.value === 'existing' ? !! customerId.value : !! customerName.value && !! customerPhone.value));
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-[36.8rem]">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ locale.t('hotel.newReservation') }}</DialogTitle>
                </DialogHeader>
                <div class="grid max-h-[70vh] gap-3 overflow-y-auto py-4">
                    <div class="grid grid-cols-3 gap-3">
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('hotel.checkIn') }}
                            <DatePicker v-model="checkIn" class="w-full" />
                        </label>
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('hotel.checkOut') }}
                            <DatePicker v-model="checkOut" class="w-full" />
                        </label>
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('hotel.guests') }}
                            <InputGroup v-model.number="guests" type="number" min="1" required>
                                <template #suffix>{{ locale.t('hotel.guestsUnit') }}</template>
                            </InputGroup>
                        </label>
                    </div>
                    <p class="text-xs ui-subtle">{{ locale.t('hotel.nights') }}: {{ nights }}</p>

                    <div>
                        <p class="mb-1 text-xs ui-subtle">{{ locale.t('hotel.selectRoom') }}</p>
                        <p v-if="loadingRooms" class="text-xs ui-subtle">…</p>
                        <p v-else-if="! rooms.length" class="text-xs ui-subtle">{{ locale.t('hotel.noRooms') }}</p>
                        <div v-else class="grid max-h-40 gap-2 overflow-y-auto">
                            <button
                                v-for="room in rooms" :key="room.resource_id" type="button"
                                class="flex items-center justify-between rounded-md border px-2 py-1.5 text-left text-xs"
                                :class="selectedRoom === room ? 'border-primary bg-primary/10' : 'border-border ui-text'"
                                @click="selectedRoom = room"
                            >
                                <span class="font-medium">{{ room.resource_name }}<span v-if="room.capacity" class="font-normal ui-subtle"> · {{ locale.t('hotel.roomCapacity') }} {{ room.capacity }}</span></span>
                                <Money :value="room.price_per_night * nights" tone="muted" />
                            </button>
                        </div>
                    </div>

                    <div class="flex gap-2 text-xs">
                        <button type="button" class="rounded-md border px-2 py-1" :class="customerMode === 'new' ? 'border-primary text-primary' : 'border-border ui-subtle'" @click="customerMode = 'new'">{{ locale.t('booking.customerName') }}</button>
                        <button type="button" class="rounded-md border px-2 py-1" :class="customerMode === 'existing' ? 'border-primary text-primary' : 'border-border ui-subtle'" @click="customerMode = 'existing'">{{ locale.t('booking.existingCustomer') }}</button>
                    </div>

                    <template v-if="customerMode === 'new'">
                        <Input v-model="customerName" :placeholder="locale.t('booking.customerName')" />
                        <Input v-model="customerPhone" :placeholder="locale.t('booking.customerPhone')" />
                    </template>
                    <template v-else>
                        <Input v-model="customerPhone" :placeholder="locale.t('booking.customerPhone')" />
                        <div v-if="filteredCustomers.length" class="grid gap-1">
                            <button
                                v-for="c in filteredCustomers" :key="c.id" type="button"
                                class="rounded-md border px-2 py-1 text-left text-xs"
                                :class="customerId === c.id ? 'border-primary bg-primary/10' : 'border-border'"
                                @click="customerId = c.id"
                            >{{ c.name }} · {{ c.phone }}</button>
                        </div>
                    </template>

                    <Input v-model="notes" :placeholder="locale.t('booking.notes')" />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving || ! canSubmit">{{ locale.t('booking.create') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
