<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input, InputGroup } from '../../ui/input';
import { Textarea } from '../../ui/textarea';

export type VehicleRow = {
    id: number; make: string; model: string; year: number | null; plate_number: string; vin: string | null; color: string | null; notes: string | null;
    customer_id: number; customer?: { id: number; name: string; phone: string | null } | null;
};
type Customer = { id: number; name: string; phone: string | null };

const props = defineProps<{ open: boolean; vehicle: VehicleRow | null; companyId: number; tenantSlug: string; customers: Customer[] }>();
const emit = defineEmits<{ 'update:open': [boolean]; saved: [] }>();
const locale = useLocaleStore();

const form = ref({ make: '', model: '', year: null as number | null, plate_number: '', vin: '', color: '', notes: '' });
const customerMode = ref<'existing' | 'new'>('existing');
const customerId = ref<number | null>(null);
const customerName = ref('');
const customerPhone = ref('');
const saving = ref(false);

watch(() => props.open, (open) => {
    if (! open) return;
    if (props.vehicle) {
        form.value = { make: props.vehicle.make, model: props.vehicle.model, year: props.vehicle.year, plate_number: props.vehicle.plate_number, vin: props.vehicle.vin ?? '', color: props.vehicle.color ?? '', notes: props.vehicle.notes ?? '' };
        customerMode.value = 'existing';
        customerId.value = props.vehicle.customer_id;
        customerPhone.value = props.vehicle.customer?.phone ?? '';
    } else {
        form.value = { make: '', model: '', year: null, plate_number: '', vin: '', color: '', notes: '' };
        customerMode.value = 'new';
        customerId.value = null;
        customerName.value = '';
        customerPhone.value = '';
    }
});

const filteredCustomers = computed(() => {
    const q = customerPhone.value.trim();
    if (! q) return [];
    return props.customers.filter((c) => c.phone?.includes(q)).slice(0, 5);
});

const canSubmit = computed(() => !! form.value.make && !! form.value.model && !! form.value.plate_number
    && (customerMode.value === 'existing' ? !! customerId.value : !! customerName.value && !! customerPhone.value));

async function submit(): Promise<void> {
    if (! canSubmit.value) return;
    saving.value = true;
    try {
        const payload: Record<string, unknown> = { ...form.value, company_id: props.companyId };
        if (customerMode.value === 'existing') {
            payload.customer_id = customerId.value;
        } else {
            payload.customer_name = customerName.value;
            payload.customer_phone = customerPhone.value;
        }

        if (props.vehicle) {
            await apiRequest(`/api/vehicles/${props.vehicle.id}`, { method: 'PATCH', body: payload, tenant: props.tenantSlug });
        } else {
            // Vehicles need a real customer_id (VehicleController is plain CRUD, no
            // find-or-create-by-phone convenience like Booking/Order's store()) --
            // resolve/create the customer first when adding a new one inline.
            if (customerMode.value === 'new') {
                const customer = await apiRequest<{ id: number }>('/api/customers', {
                    method: 'POST',
                    body: { company_id: props.companyId, name: customerName.value, phone: customerPhone.value, source: 'auto_service' },
                    tenant: props.tenantSlug,
                });
                payload.customer_id = customer.id;
            }
            await apiRequest('/api/vehicles', { method: 'POST', body: payload, tenant: props.tenantSlug });
        }
        toast.success(locale.t('autoService.saved'));
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
        <DialogContent class="sm:max-w-[32.2rem]">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ vehicle ? locale.t('autoService.editVehicle') : locale.t('autoService.addVehicle') }}</DialogTitle>
                </DialogHeader>
                <div class="grid max-h-[70vh] gap-3 overflow-y-auto py-4">
                    <div class="grid grid-cols-2 gap-3">
                        <Input v-model="form.make" :placeholder="locale.t('autoService.vehicleMake')" required />
                        <Input v-model="form.model" :placeholder="locale.t('autoService.vehicleModel')" required />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <InputGroup v-model.number="form.year" type="number" min="1950" max="2100" :placeholder="locale.t('autoService.vehicleYear')" />
                        <Input v-model="form.plate_number" :placeholder="locale.t('autoService.vehiclePlate')" required />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <Input v-model="form.vin" :placeholder="locale.t('autoService.vehicleVin')" />
                        <Input v-model="form.color" :placeholder="locale.t('autoService.vehicleColor')" />
                    </div>
                    <Textarea v-model="form.notes" :placeholder="locale.t('booking.notes')" class="min-h-16" />

                    <div class="flex gap-2 text-xs">
                        <button type="button" class="rounded-md border px-2 py-1" :class="customerMode === 'existing' ? 'border-primary text-primary' : 'border-border ui-subtle'" @click="customerMode = 'existing'">{{ locale.t('booking.existingCustomer') }}</button>
                        <button type="button" class="rounded-md border px-2 py-1" :class="customerMode === 'new' ? 'border-primary text-primary' : 'border-border ui-subtle'" @click="customerMode = 'new'">{{ locale.t('booking.customerName') }}</button>
                    </div>

                    <template v-if="customerMode === 'existing'">
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
                    <template v-else>
                        <Input v-model="customerName" :placeholder="locale.t('booking.customerName')" />
                        <Input v-model="customerPhone" :placeholder="locale.t('booking.customerPhone')" />
                    </template>
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving || ! canSubmit">{{ locale.t('booking.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
