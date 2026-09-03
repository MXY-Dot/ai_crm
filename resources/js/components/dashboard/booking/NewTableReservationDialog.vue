<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { DatePicker } from '../../ui/date-picker';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input, InputGroup } from '../../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { AnalogTimePicker } from '../calendar/analog-clock';

type Slot = { resource_id: number; resource_name: string; capacity: number; starts_at: string; ends_at: string };
type Customer = { id: number; name: string; phone: string | null };

const props = defineProps<{
    open: boolean;
    companyId: number;
    tenantSlug: string;
    customers: Customer[];
    initialDate: string;
    initialIso?: string | null;
}>();
const emit = defineEmits<{ 'update:open': [boolean]; created: [] }>();
const locale = useLocaleStore();

const partySize = ref(2);
const date = ref(props.initialDate);
const slots = ref<Slot[]>([]);
const selectedSlot = ref<Slot | null>(null);
const loadingSlots = ref(false);
const customerMode = ref<'existing' | 'new'>('new');
const customerId = ref<number | null>(null);
const customerName = ref('');
const customerPhone = ref('');
const notes = ref('');
const saving = ref(false);

watch(() => props.open, (open) => {
    if (! open) return;
    partySize.value = 2;
    date.value = props.initialDate;
    selectedSlot.value = null;
    customerMode.value = 'new';
    customerId.value = null;
    customerName.value = '';
    customerPhone.value = '';
    notes.value = '';
});

async function loadSlots(): Promise<void> {
    if (! partySize.value || ! date.value) {
        slots.value = [];
        return;
    }
    loadingSlots.value = true;
    selectedSlot.value = null;
    try {
        const data = await apiRequest<{ slots: Slot[] }>('/api/table-availability?' + new URLSearchParams({
            company_id: String(props.companyId),
            party_size: String(partySize.value),
            date: date.value,
        }), { tenant: props.tenantSlug });
        slots.value = data.slots;

        if (props.initialIso) {
            const target = new Date(props.initialIso).getTime();
            selectedSlot.value = slots.value.find((s) => Math.abs(new Date(s.starts_at).getTime() - target) < 60_000) ?? null;
        }
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loadingSlots.value = false;
    }
}

watch([partySize, date, () => props.open], () => { if (props.open) loadSlots(); });

const filteredCustomers = computed(() => {
    const q = customerPhone.value.trim();
    if (! q) return [];
    return props.customers.filter((c) => c.phone?.includes(q)).slice(0, 5);
});

async function submit(): Promise<void> {
    if (! selectedSlot.value) return;
    saving.value = true;
    try {
        await apiRequest('/api/table-reservations', {
            method: 'POST',
            body: {
                company_id: props.companyId,
                resource_id: selectedSlot.value.resource_id,
                party_size: partySize.value,
                starts_at: selectedSlot.value.starts_at,
                notes: notes.value || null,
                ...(customerMode.value === 'existing'
                    ? { customer_id: customerId.value }
                    : { customer_name: customerName.value, customer_phone: customerPhone.value }),
            },
            tenant: props.tenantSlug,
        });
        toast.success(locale.t('restaurant.saved'));
        emit('update:open', false);
        emit('created');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        saving.value = false;
    }
}

const canSubmit = computed(() => !! selectedSlot.value && (customerMode.value === 'existing' ? !! customerId.value : !! customerName.value && !! customerPhone.value));
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-[36.8rem]">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ locale.t('restaurant.newReservation') }}</DialogTitle>
                </DialogHeader>
                <div class="grid max-h-[70vh] gap-3 overflow-y-auto py-4">
                    <div class="grid grid-cols-2 gap-3">
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('restaurant.partySize') }}
                            <InputGroup v-model.number="partySize" type="number" min="1" required>
                                <template #suffix>{{ locale.t('restaurant.guestsUnit') }}</template>
                            </InputGroup>
                        </label>
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('booking.selectDate') }}
                            <DatePicker v-model="date" class="w-full" />
                        </label>
                    </div>

                    <div>
                        <p class="mb-2 text-xs ui-subtle">{{ locale.t('booking.selectTime') }}</p>
                        <AnalogTimePicker
                            v-model="selectedSlot"
                            :slots="slots"
                            :loading="loadingSlots"
                            :resource-label="(slot) => slot.resource_name"
                        />
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
