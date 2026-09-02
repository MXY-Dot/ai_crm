<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { DatePicker } from '../../ui/date-picker';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input, InputGroup } from '../../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Textarea } from '../../ui/textarea';

type VehicleOption = { id: number; make: string; model: string; plate_number: string; customer_id: number };
type Employee = { id: number; name: string };

const props = defineProps<{ open: boolean; companyId: number; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; created: [] }>();
const locale = useLocaleStore();

const vehicles = ref<VehicleOption[]>([]);
const employees = ref<Employee[]>([]);
const plateSearch = ref('');
const selectedVehicleId = ref<number | null>(null);
const employeeId = ref<number | null>(null);
const problemDescription = ref('');
const estimatedTotal = ref<number | null>(null);
const promisedDate = ref('');
const saving = ref(false);

async function loadOptions(): Promise<void> {
    try {
        const [vehiclesRes, employeesRes] = await Promise.all([
            apiRequest<{ data: VehicleOption[] }>('/api/vehicles', { tenant: props.tenantSlug }),
            apiRequest<{ data: Employee[] }>('/api/employees', { tenant: props.tenantSlug }),
        ]);
        vehicles.value = vehiclesRes.data;
        employees.value = employeesRes.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}

onMounted(loadOptions);

watch(() => props.open, (open) => {
    if (! open) return;
    plateSearch.value = '';
    selectedVehicleId.value = null;
    employeeId.value = null;
    problemDescription.value = '';
    estimatedTotal.value = null;
    promisedDate.value = '';
    loadOptions();
});

const filteredVehicles = computed(() => {
    const q = plateSearch.value.trim().toLowerCase();
    if (! q) return vehicles.value.slice(0, 8);
    return vehicles.value.filter((v) => v.plate_number.toLowerCase().includes(q)).slice(0, 8);
});

const employeeValue = computed({
    get: () => (employeeId.value ? String(employeeId.value) : 'none'),
    set: (v: string) => { employeeId.value = v === 'none' ? null : Number(v); },
});

const canSubmit = computed(() => !! selectedVehicleId.value && !! problemDescription.value.trim());

async function submit(): Promise<void> {
    if (! canSubmit.value) return;
    saving.value = true;
    try {
        await apiRequest('/api/repair-orders', {
            method: 'POST',
            body: {
                company_id: props.companyId,
                vehicle_id: selectedVehicleId.value,
                employee_id: employeeId.value,
                problem_description: problemDescription.value,
                estimated_total: estimatedTotal.value,
                promised_at: promisedDate.value ? `${promisedDate.value}T18:00:00` : null,
            },
            tenant: props.tenantSlug,
        });
        toast.success(locale.t('autoService.saved'));
        emit('update:open', false);
        emit('created');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-lg">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ locale.t('autoService.newRepairOrder') }}</DialogTitle>
                </DialogHeader>
                <div class="grid max-h-[70vh] gap-3 overflow-y-auto py-4">
                    <div>
                        <p class="mb-1 text-xs ui-subtle">{{ locale.t('autoService.selectVehicle') }}</p>
                        <Input v-model="plateSearch" :placeholder="locale.t('autoService.vehiclePlate')" />
                        <div v-if="filteredVehicles.length" class="mt-2 grid max-h-40 gap-1 overflow-y-auto">
                            <button
                                v-for="v in filteredVehicles" :key="v.id" type="button"
                                class="rounded-md border px-2 py-1.5 text-left text-xs"
                                :class="selectedVehicleId === v.id ? 'border-primary bg-primary/10' : 'border-border'"
                                @click="selectedVehicleId = v.id"
                            >{{ v.make }} {{ v.model }} · {{ v.plate_number }}</button>
                        </div>
                        <p v-else class="mt-2 text-xs ui-subtle">{{ locale.t('autoService.noVehicles') }}</p>
                    </div>

                    <Textarea v-model="problemDescription" :placeholder="locale.t('autoService.problemDescription')" class="min-h-20" required />

                    <div class="grid grid-cols-2 gap-3">
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('autoService.mechanic') }}
                            <Select v-model="employeeValue">
                                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">{{ locale.t('autoService.mechanicNone') }}</SelectItem>
                                    <SelectItem v-for="e in employees" :key="e.id" :value="String(e.id)">{{ e.name }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </label>
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('autoService.estimatedTotal') }}
                            <InputGroup v-model.number="estimatedTotal" type="number" min="0" step="0.01">
                                <template #suffix>{{ locale.t('commerce.currency') }}</template>
                            </InputGroup>
                        </label>
                    </div>

                    <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('autoService.promisedDate') }}
                        <DatePicker v-model="promisedDate" class="w-full" />
                    </label>
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving || ! canSubmit">{{ locale.t('booking.create') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
