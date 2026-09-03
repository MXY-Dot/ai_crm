<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { DatePicker } from '../../ui/date-picker';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input } from '../../ui/input';
import { Money } from '../../ui/money';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { AnalogTimePicker } from '../calendar/analog-clock';

type Service = { id: number; name: string; duration_minutes: number; price: number };
type Employee = { id: number; name: string };
type Slot = { employee_id: number; employee_name: string; starts_at: string; ends_at: string };
type Customer = { id: number; name: string; phone: string | null };

const props = defineProps<{
    open: boolean;
    companyId: number;
    tenantSlug: string;
    services: Service[];
    employees: Employee[];
    customers: Customer[];
    initialDate: string;
    initialEmployeeId?: number | null;
    initialIso?: string | null;
}>();
const emit = defineEmits<{ 'update:open': [boolean]; created: [] }>();
const locale = useLocaleStore();

const serviceId = ref<number | null>(null);
const employeeId = ref<number | null>(null);
const serviceIdStr = computed({ get: () => (serviceId.value ? String(serviceId.value) : ''), set: (v: string) => { serviceId.value = v ? Number(v) : null; } });
const employeeIdStr = computed({ get: () => (employeeId.value ? String(employeeId.value) : 'all'), set: (v: string) => { employeeId.value = v === 'all' ? null : Number(v); } });
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

const selectedService = computed(() => props.services.find((s) => s.id === serviceId.value) ?? null);

watch(() => props.open, (open) => {
    if (! open) return;
    serviceId.value = props.services[0]?.id ?? null;
    employeeId.value = props.initialEmployeeId ?? null;
    date.value = props.initialDate;
    selectedSlot.value = null;
    customerMode.value = 'new';
    customerId.value = null;
    customerName.value = '';
    customerPhone.value = '';
    notes.value = '';
});

async function loadSlots(): Promise<void> {
    if (! serviceId.value || ! date.value) {
        slots.value = [];
        return;
    }
    loadingSlots.value = true;
    selectedSlot.value = null;
    try {
        const data = await apiRequest<{ slots: Slot[] }>('/api/bookings/availability?' + new URLSearchParams({
            service_id: String(serviceId.value),
            date: date.value,
            ...(employeeId.value ? { employee_id: String(employeeId.value) } : {}),
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

watch([serviceId, employeeId, date, () => props.open], () => { if (props.open) loadSlots(); });

const filteredCustomers = computed(() => {
    const q = customerPhone.value.trim();
    if (! q) return [];
    return props.customers.filter((c) => c.phone?.includes(q)).slice(0, 5);
});

async function submit(): Promise<void> {
    if (! selectedSlot.value) return;
    saving.value = true;
    try {
        await apiRequest('/api/bookings', {
            method: 'POST',
            body: {
                company_id: props.companyId,
                service_id: serviceId.value,
                employee_id: selectedSlot.value.employee_id,
                starts_at: selectedSlot.value.starts_at,
                notes: notes.value || null,
                ...(customerMode.value === 'existing'
                    ? { customer_id: customerId.value }
                    : { customer_name: customerName.value, customer_phone: customerPhone.value }),
            },
            tenant: props.tenantSlug,
        });
        toast.success(locale.t('booking.saved'));
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
                    <DialogTitle>{{ locale.t('booking.newBooking') }}</DialogTitle>
                </DialogHeader>
                <div class="grid max-h-[70vh] gap-3 overflow-y-auto py-4">
                    <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('booking.selectService') }}
                        <Select v-model="serviceIdStr">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="s in services" :key="s.id" :value="String(s.id)">{{ s.name }} · {{ s.duration_minutes }} {{ locale.t('booking.minutesUnit') }} · {{ s.price }} {{ locale.t('commerce.currency') }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('booking.selectDate') }}
                            <DatePicker v-model="date" class="w-full" />
                        </label>
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('booking.selectEmployee') }}
                            <Select v-model="employeeIdStr">
                                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">{{ locale.t('booking.allEmployees') }}</SelectItem>
                                    <SelectItem v-for="e in employees" :key="e.id" :value="String(e.id)">{{ e.name }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </label>
                    </div>

                    <div>
                        <p class="mb-2 text-xs ui-subtle">{{ locale.t('booking.selectTime') }}</p>
                        <AnalogTimePicker
                            v-model="selectedSlot"
                            :slots="slots"
                            :loading="loadingSlots"
                            :resource-label="(slot) => slot.employee_name"
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

                    <p v-if="selectedService && selectedSlot" class="text-xs ui-subtle">
                        {{ locale.t('booking.price') }}: <Money :value="selectedService.price" tone="muted" />
                    </p>
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving || ! canSubmit">{{ locale.t('booking.create') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
