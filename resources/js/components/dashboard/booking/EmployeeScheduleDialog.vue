<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input } from '../../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Skeleton } from '../../ui/skeleton';
import type { EmployeeRow } from './EmployeeFormDialog.vue';

type TimeOffRow = { id: number; type: string; starts_at: string; ends_at: string; reason: string | null };

const props = defineProps<{ open: boolean; employee: EmployeeRow | null; allServices: Array<{ id: number; name: string }>; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean] }>();
const locale = useLocaleStore();

const WEEKDAYS: Array<{ value: number; key: string }> = [
    { value: 0, key: 'mon' }, { value: 1, key: 'tue' }, { value: 2, key: 'wed' }, { value: 3, key: 'thu' },
    { value: 4, key: 'fri' }, { value: 5, key: 'sat' }, { value: 6, key: 'sun' },
];

const loading = ref(true);
const saving = ref(false);
const selectedServiceIds = ref<number[]>([]);
const schedule = ref<Record<number, { enabled: boolean; start_time: string; end_time: string }>>({});
const timeOff = ref<TimeOffRow[]>([]);
const newTimeOff = ref({ type: 'break', starts_at: '', ends_at: '', reason: '' });

function resetSchedule(): void {
    schedule.value = Object.fromEntries(WEEKDAYS.map((d) => [d.value, { enabled: false, start_time: '09:00', end_time: '18:00' }]));
}

async function load(): Promise<void> {
    if (! props.employee) return;
    loading.value = true;
    resetSchedule();
    try {
        const data = await apiRequest<{ schedules: Array<{ weekday: number; start_time: string; end_time: string }>; time_off: TimeOffRow[]; services: Array<{ id: number }> }>(`/api/employees/${props.employee.id}`, { tenant: props.tenantSlug });
        selectedServiceIds.value = (data.services ?? []).map((s) => s.id);
        timeOff.value = data.time_off ?? [];
        for (const row of data.schedules ?? []) {
            schedule.value[row.weekday] = { enabled: true, start_time: row.start_time.slice(0, 5), end_time: row.end_time.slice(0, 5) };
        }
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (open) => { if (open) load(); });

async function save(): Promise<void> {
    if (! props.employee) return;
    saving.value = true;
    try {
        await apiRequest(`/api/employees/${props.employee.id}/services`, { method: 'PUT', body: { service_ids: selectedServiceIds.value }, tenant: props.tenantSlug });
        const rows = WEEKDAYS.filter((d) => schedule.value[d.value].enabled).map((d) => ({
            weekday: d.value,
            start_time: schedule.value[d.value].start_time,
            end_time: schedule.value[d.value].end_time,
        }));
        await apiRequest(`/api/employees/${props.employee.id}/schedule`, { method: 'PUT', body: { schedule: rows }, tenant: props.tenantSlug });
        toast.success(locale.t('booking.saved'));
        emit('update:open', false);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        saving.value = false;
    }
}

async function addTimeOff(): Promise<void> {
    if (! props.employee || ! newTimeOff.value.starts_at || ! newTimeOff.value.ends_at) return;
    try {
        const row = await apiRequest<TimeOffRow>(`/api/employees/${props.employee.id}/time-off`, { method: 'POST', body: newTimeOff.value, tenant: props.tenantSlug });
        timeOff.value = [...timeOff.value, row];
        newTimeOff.value = { type: 'break', starts_at: '', ends_at: '', reason: '' };
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}

async function removeTimeOff(row: TimeOffRow): Promise<void> {
    if (! props.employee) return;
    try {
        await apiRequest(`/api/employees/${props.employee.id}/time-off/${row.id}`, { method: 'DELETE', tenant: props.tenantSlug });
        timeOff.value = timeOff.value.filter((t) => t.id !== row.id);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}

const title = computed(() => props.employee?.name ?? '');
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-[48.3rem]">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
            </DialogHeader>

            <div v-if="loading" class="space-y-2 py-4">
                <Skeleton v-for="i in 4" :key="i" class="h-10 rounded-lg" />
            </div>
            <div v-else class="grid max-h-[70vh] gap-6 overflow-y-auto py-4">
                <section>
                    <h3 class="mb-2 text-sm font-medium ui-text">{{ locale.t('booking.employeeServices') }}</h3>
                    <div class="flex flex-wrap gap-3">
                        <label v-for="s in allServices" :key="s.id" class="flex items-center gap-2 text-sm ui-text">
                            <input v-model="selectedServiceIds" type="checkbox" :value="s.id" class="h-4 w-4 rounded border-input">
                            {{ s.name }}
                        </label>
                    </div>
                </section>

                <section>
                    <h3 class="mb-2 text-sm font-medium ui-text">{{ locale.t('booking.employeeSchedule') }}</h3>
                    <div class="grid gap-2">
                        <div v-for="d in WEEKDAYS" :key="d.value" class="flex items-center gap-3">
                            <label class="flex w-24 items-center gap-2 text-sm ui-text">
                                <input v-model="schedule[d.value].enabled" type="checkbox" class="h-4 w-4 rounded border-input">
                                {{ locale.t('booking.weekday.' + d.key) }}
                            </label>
                            <template v-if="schedule[d.value].enabled">
                                <Input v-model="schedule[d.value].start_time" type="time" class="w-28" />
                                <span class="text-xs ui-subtle">—</span>
                                <Input v-model="schedule[d.value].end_time" type="time" class="w-28" />
                            </template>
                            <span v-else class="text-xs ui-subtle">{{ locale.t('booking.dayOff') }}</span>
                        </div>
                    </div>
                </section>

                <section>
                    <h3 class="mb-2 text-sm font-medium ui-text">{{ locale.t('booking.employeeTimeOff') }}</h3>
                    <div class="mb-3 space-y-2">
                        <div v-for="row in timeOff" :key="row.id" class="flex items-center justify-between rounded-lg border border-border px-3 py-2 text-sm">
                            <span>{{ locale.t('booking.timeOffType.' + row.type) }} · {{ new Date(row.starts_at).toLocaleString() }} — {{ new Date(row.ends_at).toLocaleString() }}</span>
                            <Button variant="ghost" size="icon" @click="removeTimeOff(row)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                        </div>
                        <p v-if="! timeOff.length" class="text-xs ui-subtle">—</p>
                    </div>
                    <div class="flex flex-wrap items-end gap-2">
                        <Select v-model="newTimeOff.type">
                            <SelectTrigger class="w-36"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="break">{{ locale.t('booking.timeOffType.break') }}</SelectItem>
                                <SelectItem value="day_off">{{ locale.t('booking.timeOffType.day_off') }}</SelectItem>
                                <SelectItem value="vacation">{{ locale.t('booking.timeOffType.vacation') }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Input v-model="newTimeOff.starts_at" type="datetime-local" class="w-48" />
                        <Input v-model="newTimeOff.ends_at" type="datetime-local" class="w-48" />
                        <Button type="button" variant="outline" size="sm" @click="addTimeOff">{{ locale.t('booking.addTimeOff') }}</Button>
                    </div>
                </section>
            </div>

            <DialogFooter>
                <Button :disabled="saving" @click="save">{{ locale.t('booking.save') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
