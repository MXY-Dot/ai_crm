<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { CalendarClock, Pencil, Plus, Trash2, Users2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { EmptyState } from '../../ui/empty-state';
import { Skeleton } from '../../ui/skeleton';
import EmployeeFormDialog, { type EmployeeRow } from './EmployeeFormDialog.vue';
import EmployeeScheduleDialog from './EmployeeScheduleDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();

const employees = ref<EmployeeRow[]>([]);
const services = ref<Array<{ id: number; name: string }>>([]);
const branches = ref<Array<{ id: number; name: string }>>([]);
const loading = ref(true);
const formOpen = ref(false);
const scheduleOpen = ref(false);
const editing = ref<EmployeeRow | null>(null);
const scheduling = ref<EmployeeRow | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const [employeesRes, servicesRes, branchesRes] = await Promise.all([
            apiRequest<{ data: EmployeeRow[] }>('/api/employees', { tenant: props.tenantSlug }),
            apiRequest<{ data: Array<{ id: number; name: string }> }>('/api/services', { tenant: props.tenantSlug }),
            apiRequest<{ data: Array<{ id: number; name: string }> }>('/api/branches', { tenant: props.tenantSlug }),
        ]);
        employees.value = employeesRes.data;
        services.value = servicesRes.data;
        branches.value = branchesRes.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function openCreate(): void {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(employee: EmployeeRow): void {
    editing.value = employee;
    formOpen.value = true;
}

function openSchedule(employee: EmployeeRow): void {
    scheduling.value = employee;
    scheduleOpen.value = true;
}

async function remove(employee: EmployeeRow): Promise<void> {
    if (! confirm(employee.name + '?')) return;
    try {
        await apiRequest(`/api/employees/${employee.id}`, { method: 'DELETE', tenant: props.tenantSlug });
        employees.value = employees.value.filter((e) => e.id !== employee.id);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}

function onSaved(row: EmployeeRow): void {
    const idx = employees.value.findIndex((e) => e.id === row.id);
    if (idx >= 0) employees.value[idx] = row; else employees.value = [row, ...employees.value];
}
</script>

<template>
    <Card :title="locale.t('booking.tabEmployees')">
        <template #actions>
            <Button size="sm" @click="openCreate"><Plus class="h-4 w-4" />{{ locale.t('booking.addEmployee') }}</Button>
        </template>

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-14 rounded-lg" />
        </div>
        <EmptyState v-else-if="! employees.length" :icon="Users2" :title="locale.t('booking.noEmployees')" />
        <div v-else class="divide-y divide-border pb-2">
            <div v-for="employee in employees" :key="employee.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium ui-text">{{ employee.name }}</p>
                    <p class="text-xs ui-subtle">{{ employee.position || '—' }}<span v-if="employee.phone"> · {{ employee.phone }}</span><span v-if="employee.branch_id"> · {{ branches.find((b) => b.id === employee.branch_id)?.name }}</span></p>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="sm" @click="openSchedule(employee)"><CalendarClock class="h-4 w-4" />{{ locale.t('booking.employeeSchedule') }}</Button>
                    <Button variant="ghost" size="icon" @click="openEdit(employee)"><Pencil class="h-4 w-4" /></Button>
                    <Button variant="ghost" size="icon" @click="remove(employee)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                </div>
            </div>
        </div>

        <EmployeeFormDialog v-model:open="formOpen" :employee="editing" :company-id="companyId" :tenant-slug="tenantSlug" :branches="branches" @saved="onSaved" />
        <EmployeeScheduleDialog v-model:open="scheduleOpen" :employee="scheduling" :all-services="services" :tenant-slug="tenantSlug" />
    </Card>
</template>
