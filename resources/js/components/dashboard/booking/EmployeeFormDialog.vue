<script setup lang="ts">
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input } from '../../ui/input';

export type EmployeeRow = { id: number; name: string; position: string | null; phone: string | null; is_active: boolean };

const props = defineProps<{ open: boolean; employee: EmployeeRow | null; companyId: number; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; saved: [EmployeeRow] }>();
const locale = useLocaleStore();

const form = ref({ name: '', position: '', phone: '' });
const saving = ref(false);

watch(() => props.open, (open) => {
    if (! open) return;
    form.value = props.employee
        ? { name: props.employee.name, position: props.employee.position ?? '', phone: props.employee.phone ?? '' }
        : { name: '', position: '', phone: '' };
});

async function submit(): Promise<void> {
    saving.value = true;
    try {
        const payload = { ...form.value, company_id: props.companyId };
        const result = props.employee
            ? await apiRequest<EmployeeRow>(`/api/employees/${props.employee.id}`, { method: 'PATCH', body: payload, tenant: props.tenantSlug })
            : await apiRequest<EmployeeRow>('/api/employees', { method: 'POST', body: payload, tenant: props.tenantSlug });
        toast.success(locale.t('booking.saved'));
        emit('update:open', false);
        emit('saved', result);
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
                    <DialogTitle>{{ employee ? locale.t('booking.editEmployee') : locale.t('booking.addEmployee') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <Input v-model="form.name" :placeholder="locale.t('booking.employeeName')" required />
                    <Input v-model="form.position" :placeholder="locale.t('booking.employeePosition')" />
                    <Input v-model="form.phone" :placeholder="locale.t('booking.employeePhone')" />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving">{{ locale.t('booking.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
