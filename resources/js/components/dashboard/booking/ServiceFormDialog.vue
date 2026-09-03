<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input, InputGroup } from '../../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Switch } from '../../ui/switch';
import { Textarea } from '../../ui/textarea';

export type ServiceRow = {
    id: number;
    name: string;
    category: string | null;
    description: string | null;
    duration_minutes: number;
    price: number;
    prepayment_type: string;
    prepayment_value: number | null;
    buffer_after_minutes: number;
    required_resource_id: number | null;
    is_active: boolean;
};

const props = defineProps<{ open: boolean; service: ServiceRow | null; companyId: number; tenantSlug: string; resources: Array<{ id: number; name: string }> }>();
const emit = defineEmits<{ 'update:open': [boolean]; saved: [] }>();
const locale = useLocaleStore();

const form = ref({
    name: '', category: '', description: '', duration_minutes: 60, price: 0,
    prepayment_type: 'none', prepayment_value: 0, buffer_after_minutes: 0,
    required_resource_id: null as number | null, is_active: true,
});
const saving = ref(false);

watch(() => props.open, (open) => {
    if (! open) return;
    const s = props.service;
    form.value = s
        ? { name: s.name, category: s.category ?? '', description: s.description ?? '', duration_minutes: s.duration_minutes, price: s.price, prepayment_type: s.prepayment_type, prepayment_value: s.prepayment_value ?? 0, buffer_after_minutes: s.buffer_after_minutes, required_resource_id: s.required_resource_id, is_active: s.is_active }
        : { name: '', category: '', description: '', duration_minutes: 60, price: 0, prepayment_type: 'none', prepayment_value: 0, buffer_after_minutes: 0, required_resource_id: null, is_active: true };
});

const resourceValue = computed({
    get: () => (form.value.required_resource_id ? String(form.value.required_resource_id) : 'none'),
    set: (v: string) => { form.value.required_resource_id = v === 'none' ? null : Number(v); },
});

async function submit(): Promise<void> {
    saving.value = true;
    try {
        const payload = { ...form.value, company_id: props.companyId };
        if (props.service) {
            await apiRequest(`/api/services/${props.service.id}`, { method: 'PATCH', body: payload, tenant: props.tenantSlug });
        } else {
            await apiRequest('/api/services', { method: 'POST', body: payload, tenant: props.tenantSlug });
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
        <DialogContent class="sm:max-w-[36.8rem]">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ service ? locale.t('booking.editService') : locale.t('booking.addService') }}</DialogTitle>
                </DialogHeader>
                <div class="grid max-h-[70vh] gap-3 overflow-x-hidden overflow-y-auto py-4">
                    <Input v-model="form.name" :placeholder="locale.t('booking.serviceName')" required />
                    <Input v-model="form.category" :placeholder="locale.t('booking.serviceCategory')" />
                    <Textarea v-model="form.description" :placeholder="locale.t('booking.serviceDescription')" class="min-h-16" />
                    <div class="grid grid-cols-2 gap-3">
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('booking.serviceDuration') }}
                            <InputGroup v-model.number="form.duration_minutes" type="number" min="5" step="5" required>
                                <template #suffix>{{ locale.t('booking.minutesUnit') }}</template>
                            </InputGroup>
                        </label>
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('booking.servicePrice') }}
                            <InputGroup v-model.number="form.price" type="number" min="0" step="0.01">
                                <template #suffix>{{ locale.t('commerce.currency') }}</template>
                            </InputGroup>
                        </label>
                    </div>
                    <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('booking.servicePrepaymentType') }}
                        <Select v-model="form.prepayment_type">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">{{ locale.t('booking.prepaymentNone') }}</SelectItem>
                                <SelectItem value="fixed">{{ locale.t('booking.prepaymentFixed') }}</SelectItem>
                                <SelectItem value="percent">{{ locale.t('booking.prepaymentPercent') }}</SelectItem>
                                <SelectItem value="full">{{ locale.t('booking.prepaymentFull') }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </label>
                    <label v-if="form.prepayment_type === 'fixed' || form.prepayment_type === 'percent'" class="grid gap-1 text-xs ui-subtle">
                        {{ locale.t('booking.servicePrepaymentValue') }}
                        <InputGroup v-model.number="form.prepayment_value" type="number" min="0" step="0.01">
                            <template #suffix>{{ form.prepayment_type === 'percent' ? '%' : locale.t('commerce.currency') }}</template>
                        </InputGroup>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('booking.serviceBuffer') }}
                            <InputGroup v-model.number="form.buffer_after_minutes" type="number" min="0" step="5">
                                <template #suffix>{{ locale.t('booking.minutesUnit') }}</template>
                            </InputGroup>
                        </label>
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('booking.serviceResource') }}
                            <Select v-model="resourceValue">
                                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">{{ locale.t('booking.serviceResourceNone') }}</SelectItem>
                                    <SelectItem v-for="r in resources" :key="r.id" :value="String(r.id)">{{ r.name }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </label>
                    </div>
                    <label class="flex items-center justify-between text-sm ui-text">{{ locale.t('booking.active') }}
                        <Switch v-model="form.is_active" />
                    </label>
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving">{{ locale.t('booking.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
