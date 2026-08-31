<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { UserPlus } from '@lucide/vue';
import { apiRequest } from '../../lib/apiClient';
import { useCrmDashboardStore, type TenantUser } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Alert, AlertDescription } from '../ui/alert';
import { Button } from '../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '../ui/dialog';
import { Input } from '../ui/input';
import { PhoneInput } from '../ui/phone-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select';

type Employee = { id: number; name: string };

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const open = ref(false);
const roles: TenantUser['role'][] = ['super_admin', 'owner', 'manager', 'operator', 'specialist', 'accountant'];
const form = reactive({ name: '', email: '', phone: '', role: 'operator' as TenantUser['role'], password: '', employee_id: null as number | null });
const employees = ref<Employee[]>([]);

watch(open, async (isOpen) => {
    if (isOpen && ! employees.value.length) {
        try {
            employees.value = await apiRequest<Employee[]>('/api/employees', { tenant: store.tenant?.slug ?? null });
        } catch {
            // Non-fatal -- the specialist picker just stays empty; invite still works with role alone.
        }
    }
});

async function submit(): Promise<void> {
    await store.createTenantUser({
        name: form.name,
        email: form.email,
        phone: form.phone || null,
        role: form.role,
        status: 'invited',
        password: form.password || undefined,
        employee_id: form.role === 'specialist' ? form.employee_id : null,
    });
    Object.assign(form, { name: '', email: '', phone: '', role: 'operator', password: '', employee_id: null });
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="primary" type="button"><UserPlus class="h-4 w-4" />{{ locale.t('team.inviteUser') }}</Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><UserPlus class="h-4 w-4 text-primary" />{{ locale.t('team.inviteUser') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <Alert v-if="store.error" variant="destructive"><AlertDescription>{{ store.error }}</AlertDescription></Alert>
                    <Input v-model="form.name" :placeholder="locale.t('team.name')" required />
                    <Input v-model="form.email" type="email" :placeholder="locale.t('team.email')" required />
                    <PhoneInput v-model="form.phone" :placeholder="locale.t('team.phone')" />
                    <Input v-model="form.password" type="password" :placeholder="locale.t('team.password')" />
                    <Select v-model="form.role">
                        <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="role in roles" :key="role" :value="role">{{ locale.t(`team.roles.${role}`) }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select v-if="form.role === 'specialist'" :model-value="form.employee_id ? String(form.employee_id) : undefined" @update:model-value="(v) => (form.employee_id = v ? Number(v) : null)">
                        <SelectTrigger class="w-full"><SelectValue :placeholder="locale.t('team.linkedEmployee')" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="employee in employees" :key="employee.id" :value="String(employee.id)">{{ employee.name }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <DialogFooter>
                    <Button type="submit" variant="primary" :disabled="store.busy || !form.name || !form.email">{{ locale.t('team.create') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
