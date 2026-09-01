<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input, InputGroup } from '../../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';

export type ResourceRow = { id: number; name: string; type: string; capacity: number | null; price_per_night: number | null; is_active: boolean; branch_id: number | null };

const props = defineProps<{ open: boolean; resource: ResourceRow | null; companyId: number; tenantSlug: string; branches: Array<{ id: number; name: string }>; defaultType?: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; saved: [] }>();
const locale = useLocaleStore();

const form = ref({ name: '', type: 'other', capacity: null as number | null, price_per_night: null as number | null, branch_id: null as number | null });
const saving = ref(false);

watch(() => props.open, (open) => {
    if (! open) return;
    form.value = props.resource
        ? { name: props.resource.name, type: props.resource.type, capacity: props.resource.capacity, price_per_night: props.resource.price_per_night, branch_id: props.resource.branch_id }
        : { name: '', type: props.defaultType ?? 'other', capacity: null, price_per_night: null, branch_id: null };
});

const branchValue = computed({
    get: () => (form.value.branch_id ? String(form.value.branch_id) : 'none'),
    set: (v: string) => { form.value.branch_id = v === 'none' ? null : Number(v); },
});

async function submit(): Promise<void> {
    saving.value = true;
    try {
        const payload = { ...form.value, company_id: props.companyId };
        if (props.resource) {
            await apiRequest(`/api/resources/${props.resource.id}`, { method: 'PATCH', body: payload, tenant: props.tenantSlug });
        } else {
            await apiRequest('/api/resources', { method: 'POST', body: payload, tenant: props.tenantSlug });
        }
        toast.success(locale.t('booking.saved'));
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
                    <DialogTitle>{{ resource ? locale.t('booking.editResource') : locale.t('booking.addResource') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <Input v-model="form.name" :placeholder="locale.t('booking.resourceName')" required />
                    <Select v-model="form.type">
                        <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="chair">{{ locale.t('booking.resourceTypes.chair') }}</SelectItem>
                            <SelectItem value="cabinet">{{ locale.t('booking.resourceTypes.cabinet') }}</SelectItem>
                            <SelectItem value="room">{{ locale.t('booking.resourceTypes.room') }}</SelectItem>
                            <SelectItem value="table">{{ locale.t('booking.resourceTypes.table') }}</SelectItem>
                            <SelectItem value="equipment">{{ locale.t('booking.resourceTypes.equipment') }}</SelectItem>
                            <SelectItem value="other">{{ locale.t('booking.resourceTypes.other') }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <label v-if="form.type === 'table'" class="grid gap-1 text-xs ui-subtle">{{ locale.t('restaurant.tableCapacity') }}
                        <InputGroup v-model.number="form.capacity" type="number" min="1" required>
                            <template #suffix>{{ locale.t('restaurant.seatsUnit') }}</template>
                        </InputGroup>
                    </label>
                    <template v-if="form.type === 'room'">
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('hotel.roomCapacity') }}
                            <InputGroup v-model.number="form.capacity" type="number" min="1" required>
                                <template #suffix>{{ locale.t('hotel.guestsUnit') }}</template>
                            </InputGroup>
                        </label>
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('hotel.pricePerNight') }}
                            <InputGroup v-model.number="form.price_per_night" type="number" min="0" step="0.01">
                                <template #suffix>{{ locale.t('commerce.currency') }}</template>
                            </InputGroup>
                        </label>
                    </template>
                    <Select v-model="branchValue">
                        <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">{{ locale.t('booking.branchNone') }}</SelectItem>
                            <SelectItem v-for="b in branches" :key="b.id" :value="String(b.id)">{{ b.name }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving">{{ locale.t('booking.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
