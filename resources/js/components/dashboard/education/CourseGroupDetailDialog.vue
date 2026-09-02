<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Pencil } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Badge } from '../../ui/badge';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Skeleton } from '../../ui/skeleton';
import CourseGroupFormDialog, { type CourseGroupRow } from './CourseGroupFormDialog.vue';
import NewEnrollmentDialog from './NewEnrollmentDialog.vue';

type Slot = { weekday: number; start_time: string; end_time: string };
type EnrollmentRow = { id: number; status: string; customer: { id: number; name: string; phone: string | null } | null };
type GroupDetail = {
    id: number; name: string; status: string; capacity: number | null; schedule: Slot[]; starts_on: string | null; ends_on: string | null; notes: string | null;
    course: { id: number; name: string; price: number } | null; employee: { id: number; name: string } | null; resource: { id: number; name: string } | null;
    enrollments: EnrollmentRow[];
};

const STATUS_OPTIONS = ['recruiting', 'active', 'completed', 'cancelled'];
const WEEKDAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

const props = defineProps<{ open: boolean; groupId: number | null; companyId: number; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; changed: [] }>();
const locale = useLocaleStore();

const group = ref<GroupDetail | null>(null);
const loading = ref(true);
const busy = ref(false);
const newStatus = ref('recruiting');
const editOpen = ref(false);
const enrollOpen = ref(false);

async function load(): Promise<void> {
    if (! props.groupId) return;
    loading.value = true;
    try {
        group.value = await apiRequest<GroupDetail>(`/api/course-groups/${props.groupId}`, { tenant: props.tenantSlug });
        newStatus.value = group.value.status;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

watch(() => [props.open, props.groupId], () => { if (props.open) load(); });

async function changeStatus(): Promise<void> {
    if (! group.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/course-groups/${group.value.id}`, { method: 'PATCH', body: { status: newStatus.value }, tenant: props.tenantSlug });
        toast.success(locale.t('education.saved'));
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function completeEnrollment(enrollment: EnrollmentRow): Promise<void> {
    busy.value = true;
    try {
        await apiRequest(`/api/enrollments/${enrollment.id}/complete`, { method: 'POST', tenant: props.tenantSlug });
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

async function cancelEnrollment(enrollment: EnrollmentRow): Promise<void> {
    const reason = prompt(locale.t('education.cancelReason'));
    if (! reason) return;
    busy.value = true;
    try {
        await apiRequest(`/api/enrollments/${enrollment.id}/cancel`, { method: 'POST', body: { reason }, tenant: props.tenantSlug });
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

const groupForEdit = computed<CourseGroupRow | null>(() => {
    if (! group.value) return null;
    return {
        id: group.value.id,
        course_id: group.value.course?.id ?? 0,
        employee_id: group.value.employee?.id ?? null,
        resource_id: group.value.resource?.id ?? null,
        name: group.value.name,
        capacity: group.value.capacity,
        schedule: group.value.schedule,
        starts_on: group.value.starts_on,
        ends_on: group.value.ends_on,
        status: group.value.status,
        notes: group.value.notes,
    };
});

const ENROLLMENT_TONE: Record<string, 'neutral' | 'green' | 'amber' | 'red' | 'blue'> = {
    enrolled: 'blue', completed: 'green', cancelled: 'red',
};

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ group?.name ?? locale.t('education.groupDetails') }}</DialogTitle>
            </DialogHeader>

            <div v-if="loading" class="space-y-2 py-4">
                <Skeleton v-for="i in 5" :key="i" class="h-10 rounded-lg" />
            </div>
            <div v-else-if="group" class="grid max-h-[75vh] gap-5 overflow-y-auto py-4 text-sm">
                <section class="grid gap-1">
                    <div class="flex items-center justify-between">
                        <p class="font-medium ui-text">{{ group.course?.name }}</p>
                        <Button variant="ghost" size="icon" @click="editOpen = true"><Pencil class="h-4 w-4" /></Button>
                    </div>
                    <p v-if="group.employee" class="ui-subtle">{{ locale.t('education.teacher') }}: {{ group.employee.name }}</p>
                    <p v-if="group.resource" class="ui-subtle">{{ locale.t('education.room') }}: {{ group.resource.name }}</p>
                    <p class="ui-subtle">
                        <span v-for="(s, i) in group.schedule" :key="i">{{ locale.t('booking.weekday.' + WEEKDAY_KEYS[s.weekday]) }} {{ s.start_time }}–{{ s.end_time }}<span v-if="i < group.schedule.length - 1">, </span></span>
                    </p>
                    <p v-if="group.starts_on" class="ui-subtle">{{ locale.t('education.startsOn') }}: {{ formatDate(group.starts_on) }}<span v-if="group.ends_on"> — {{ formatDate(group.ends_on) }}</span></p>
                    <p v-if="group.notes" class="ui-subtle">{{ group.notes }}</p>
                    <p class="font-medium ui-text">{{ locale.t('education.statuses.' + group.status) }}</p>
                </section>

                <section class="grid gap-2">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('education.changeStatus') }}</p>
                    <div class="flex gap-2">
                        <Select v-model="newStatus">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="s in STATUS_OPTIONS" :key="s" :value="s">{{ locale.t('education.statuses.' + s) }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button :disabled="busy" @click="changeStatus">{{ locale.t('education.save') }}</Button>
                    </div>
                </section>

                <section class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium ui-subtle">{{ locale.t('education.roster') }}<span v-if="group.capacity"> ({{ group.enrollments.filter((e) => e.status === 'enrolled').length }}/{{ group.capacity }})</span></p>
                        <Button size="sm" variant="outline" @click="enrollOpen = true">{{ locale.t('education.enrollStudent') }}</Button>
                    </div>
                    <div v-if="! group.enrollments.length" class="text-xs ui-subtle">{{ locale.t('education.noStudents') }}</div>
                    <div v-for="e in group.enrollments" :key="e.id" class="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2 text-xs">
                        <span class="ui-text">{{ e.customer?.name }}<span class="ui-subtle"> · {{ e.customer?.phone }}</span></span>
                        <div class="flex items-center gap-2">
                            <Badge :tone="ENROLLMENT_TONE[e.status] ?? 'neutral'">{{ locale.t('education.enrollmentStatuses.' + e.status) }}</Badge>
                            <template v-if="e.status === 'enrolled'">
                                <Button size="sm" variant="ghost" :disabled="busy" @click="completeEnrollment(e)">{{ locale.t('education.markCompleted') }}</Button>
                                <Button size="sm" variant="ghost" :disabled="busy" @click="cancelEnrollment(e)">{{ locale.t('education.cancel') }}</Button>
                            </template>
                        </div>
                    </div>
                </section>
            </div>

            <CourseGroupFormDialog v-model:open="editOpen" :group="groupForEdit" :company-id="companyId" :tenant-slug="tenantSlug" @saved="load" />
            <NewEnrollmentDialog v-model:open="enrollOpen" :course-group-id="groupId" :company-id="companyId" :tenant-slug="tenantSlug" @enrolled="load" />
        </DialogContent>
    </Dialog>
</template>
