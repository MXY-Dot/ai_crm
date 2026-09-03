<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { DatePicker } from '../../ui/date-picker';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../../ui/dialog';
import { InputGroup } from '../../ui/input';
import { Money } from '../../ui/money';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Skeleton } from '../../ui/skeleton';
import { Textarea } from '../../ui/textarea';

type StatusEntry = { id: number; old_status: string | null; new_status: string; comment: string | null; changed_by: { name: string } | null; created_at: string };
type LinkedOrder = { id: number; status: string; payment_status: string; total: number };
type RepairOrderDetail = {
    id: number; status: string; problem_description: string; diagnosis_notes: string | null;
    estimated_total: number | null; promised_at: string | null; completed_at: string | null; notes: string | null;
    customer: { name: string; phone: string | null } | null;
    vehicle: { make: string; model: string; year: number | null; plate_number: string; vin: string | null; color: string | null } | null;
    employee: { name: string } | null;
    status_history: StatusEntry[]; orders: LinkedOrder[];
};

const STATUS_OPTIONS = ['diagnosing', 'awaiting_approval', 'in_progress', 'awaiting_parts', 'ready_for_pickup', 'completed'];

const props = defineProps<{ open: boolean; repairOrderId: number | null; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; changed: [] }>();
const locale = useLocaleStore();

const repairOrder = ref<RepairOrderDetail | null>(null);
const loading = ref(true);
const busy = ref(false);
const newStatus = ref('diagnosing');
const diagnosisNotes = ref('');
const estimatedTotal = ref<number | null>(null);
const promisedDate = ref('');
const cancelReason = ref('');

async function load(): Promise<void> {
    if (! props.repairOrderId) return;
    loading.value = true;
    try {
        repairOrder.value = await apiRequest<RepairOrderDetail>(`/api/repair-orders/${props.repairOrderId}`, { tenant: props.tenantSlug });
        newStatus.value = STATUS_OPTIONS.includes(repairOrder.value.status) ? repairOrder.value.status : STATUS_OPTIONS[0];
        diagnosisNotes.value = repairOrder.value.diagnosis_notes ?? '';
        estimatedTotal.value = repairOrder.value.estimated_total;
        promisedDate.value = repairOrder.value.promised_at ? repairOrder.value.promised_at.slice(0, 10) : '';
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

watch(() => [props.open, props.repairOrderId], () => { if (props.open) load(); });

const isActive = computed(() => repairOrder.value && ! ['completed', 'cancelled'].includes(repairOrder.value.status));

async function changeStatus(): Promise<void> {
    if (! repairOrder.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/repair-orders/${repairOrder.value.id}/status`, { method: 'PATCH', body: { status: newStatus.value }, tenant: props.tenantSlug });
        toast.success(locale.t('autoService.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function saveDetails(): Promise<void> {
    if (! repairOrder.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/repair-orders/${repairOrder.value.id}/details`, {
            method: 'PATCH',
            body: { diagnosis_notes: diagnosisNotes.value || null, estimated_total: estimatedTotal.value, promised_at: promisedDate.value ? `${promisedDate.value}T18:00:00` : null },
            tenant: props.tenantSlug,
        });
        toast.success(locale.t('autoService.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function cancelRepairOrder(): Promise<void> {
    if (! repairOrder.value || ! cancelReason.value.trim()) return;
    busy.value = true;
    try {
        await apiRequest(`/api/repair-orders/${repairOrder.value.id}/cancel`, { method: 'POST', body: { reason: cancelReason.value }, tenant: props.tenantSlug });
        toast.success(locale.t('autoService.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-[36.8rem]">
            <DialogHeader>
                <DialogTitle>{{ locale.t('autoService.repairOrderDetails') }}</DialogTitle>
            </DialogHeader>

            <div v-if="loading" class="space-y-2 py-4">
                <Skeleton v-for="i in 5" :key="i" class="h-10 rounded-lg" />
            </div>
            <div v-else-if="repairOrder" class="grid max-h-[75vh] gap-5 overflow-x-hidden overflow-y-auto py-4 text-sm">
                <section class="grid gap-1">
                    <p class="font-medium ui-text">{{ repairOrder.customer?.name }} <span class="ui-subtle">· {{ repairOrder.customer?.phone }}</span></p>
                    <p class="ui-subtle">{{ repairOrder.vehicle?.make }} {{ repairOrder.vehicle?.model }}<span v-if="repairOrder.vehicle?.year"> · {{ repairOrder.vehicle.year }}</span> · {{ repairOrder.vehicle?.plate_number }}</p>
                    <p v-if="repairOrder.employee" class="ui-subtle">{{ locale.t('autoService.mechanic') }}: {{ repairOrder.employee.name }}</p>
                    <p class="ui-text">{{ repairOrder.problem_description }}</p>
                    <p v-if="repairOrder.estimated_total" class="ui-subtle">{{ locale.t('autoService.estimatedTotal') }}: <Money :value="repairOrder.estimated_total" tone="muted" /></p>
                    <p v-if="repairOrder.promised_at" class="ui-subtle">{{ locale.t('autoService.promisedDate') }}: {{ formatDate(repairOrder.promised_at) }}</p>
                    <p class="font-medium ui-text">{{ locale.t('autoService.statuses.' + repairOrder.status) }}</p>
                </section>

                <section v-if="repairOrder.orders?.length" class="grid gap-2 rounded-lg border border-border p-3">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('autoService.invoices') }}</p>
                    <div v-for="o in repairOrder.orders" :key="o.id" class="flex items-center justify-between text-xs">
                        <span class="ui-text">{{ locale.t('commerce.orderNumber') }} #{{ o.id }} · {{ locale.t('commerce.statuses.' + o.status) }} · {{ locale.t('commerce.paymentStatuses.' + o.payment_status) }}</span>
                        <Money :value="o.total" tone="muted" />
                    </div>
                </section>

                <section v-if="isActive" class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('autoService.changeStatus') }}</p>
                    <div class="flex gap-2">
                        <Select v-model="newStatus">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="s in STATUS_OPTIONS" :key="s" :value="s">{{ locale.t('autoService.statuses.' + s) }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button :disabled="busy" @click="changeStatus">{{ locale.t('autoService.save') }}</Button>
                    </div>
                </section>

                <section v-if="isActive" class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('autoService.diagnosisNotes') }}</p>
                    <Textarea v-model="diagnosisNotes" :placeholder="locale.t('autoService.diagnosisNotes')" class="min-h-16" />
                    <div class="grid grid-cols-2 gap-3">
                        <InputGroup v-model.number="estimatedTotal" type="number" min="0" step="0.01" :placeholder="locale.t('autoService.estimatedTotal')">
                            <template #suffix>{{ locale.t('commerce.currency') }}</template>
                        </InputGroup>
                        <DatePicker v-model="promisedDate" class="w-full" />
                    </div>
                    <Button variant="outline" class="w-fit" :disabled="busy" @click="saveDetails">{{ locale.t('autoService.updateDetails') }}</Button>
                </section>

                <section v-if="isActive" class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('autoService.cancelRepairOrder') }}</p>
                    <div class="flex gap-2">
                        <Textarea v-model="cancelReason" :placeholder="locale.t('autoService.cancelReason')" class="min-h-10" />
                        <Button variant="destructive" :disabled="busy || ! cancelReason.trim()" @click="cancelRepairOrder">{{ locale.t('autoService.cancelRepairOrder') }}</Button>
                    </div>
                </section>

                <section v-if="repairOrder.status_history?.length" class="grid gap-1">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('autoService.history') }}</p>
                    <div class="grid gap-1 text-xs ui-subtle">
                        <p v-for="h in repairOrder.status_history" :key="h.id">
                            {{ new Date(h.created_at).toLocaleString() }} · {{ locale.t('autoService.statuses.' + h.new_status) }}<span v-if="h.changed_by"> · {{ h.changed_by.name }}</span><span v-if="h.comment"> · {{ h.comment }}</span>
                        </p>
                    </div>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
