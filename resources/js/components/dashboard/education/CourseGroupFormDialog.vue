<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Plus, Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { DatePicker } from '../../ui/date-picker';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input, InputGroup } from '../../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Textarea } from '../../ui/textarea';

type Course = { id: number; name: string };
type Employee = { id: number; name: string };
type Resource = { id: number; name: string };
type Slot = { weekday: number; start_time: string; end_time: string };

export type CourseGroupRow = {
    id: number; course_id: number; employee_id: number | null; resource_id: number | null; name: string;
    capacity: number | null; schedule: Slot[]; starts_on: string | null; ends_on: string | null; status: string; notes: string | null;
};

const props = defineProps<{ open: boolean; group: CourseGroupRow | null; companyId: number; tenantSlug: string; defaultCourseId?: number | null }>();
const emit = defineEmits<{ 'update:open': [boolean]; saved: [] }>();
const locale = useLocaleStore();

const courses = ref<Course[]>([]);
const employees = ref<Employee[]>([]);
const resources = ref<Resource[]>([]);

const courseId = ref<number | null>(null);
const employeeId = ref<number | null>(null);
const resourceId = ref<number | null>(null);
const name = ref('');
const capacity = ref<number | null>(null);
const schedule = ref<Slot[]>([{ weekday: 1, start_time: '18:00', end_time: '19:30' }]);
const startsOn = ref('');
const endsOn = ref('');
const notes = ref('');
const saving = ref(false);

async function loadOptions(): Promise<void> {
    try {
        const [coursesRes, employeesRes, resourcesRes] = await Promise.all([
            apiRequest<{ data: Course[] }>('/api/courses', { tenant: props.tenantSlug }),
            apiRequest<{ data: Employee[] }>('/api/employees', { tenant: props.tenantSlug }),
            apiRequest<{ data: Resource[] }>('/api/resources', { tenant: props.tenantSlug }),
        ]);
        courses.value = coursesRes.data;
        employees.value = employeesRes.data;
        resources.value = resourcesRes.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}

onMounted(loadOptions);

watch(() => props.open, (open) => {
    if (! open) return;
    loadOptions();
    if (props.group) {
        courseId.value = props.group.course_id;
        employeeId.value = props.group.employee_id;
        resourceId.value = props.group.resource_id;
        name.value = props.group.name;
        capacity.value = props.group.capacity;
        schedule.value = props.group.schedule.length ? [...props.group.schedule] : [{ weekday: 1, start_time: '18:00', end_time: '19:30' }];
        startsOn.value = props.group.starts_on ?? '';
        endsOn.value = props.group.ends_on ?? '';
        notes.value = props.group.notes ?? '';
    } else {
        courseId.value = props.defaultCourseId ?? null;
        employeeId.value = null;
        resourceId.value = null;
        name.value = '';
        capacity.value = null;
        schedule.value = [{ weekday: 1, start_time: '18:00', end_time: '19:30' }];
        startsOn.value = '';
        endsOn.value = '';
        notes.value = '';
    }
});

const WEEKDAYS = [
    { value: 0, key: 'mon' }, { value: 1, key: 'tue' }, { value: 2, key: 'wed' },
    { value: 3, key: 'thu' }, { value: 4, key: 'fri' }, { value: 5, key: 'sat' }, { value: 6, key: 'sun' },
];

function addSlot(): void {
    schedule.value.push({ weekday: 0, start_time: '18:00', end_time: '19:30' });
}

function removeSlot(index: number): void {
    schedule.value.splice(index, 1);
}

const courseValue = computed({ get: () => (courseId.value ? String(courseId.value) : ''), set: (v: string) => { courseId.value = v ? Number(v) : null; } });
const employeeValue = computed({
    get: () => (employeeId.value ? String(employeeId.value) : 'none'),
    set: (v: string) => { employeeId.value = v === 'none' ? null : Number(v); },
});
const resourceValue = computed({
    get: () => (resourceId.value ? String(resourceId.value) : 'none'),
    set: (v: string) => { resourceId.value = v === 'none' ? null : Number(v); },
});

const canSubmit = computed(() => !! courseId.value && !! name.value.trim() && schedule.value.length > 0
    && schedule.value.every((s) => s.start_time && s.end_time && s.end_time > s.start_time));

async function submit(): Promise<void> {
    if (! canSubmit.value) return;
    saving.value = true;
    try {
        const payload = {
            company_id: props.companyId,
            course_id: courseId.value,
            employee_id: employeeId.value,
            resource_id: resourceId.value,
            name: name.value,
            capacity: capacity.value,
            schedule: schedule.value,
            starts_on: startsOn.value || null,
            ends_on: endsOn.value || null,
            notes: notes.value || null,
        };
        if (props.group) {
            await apiRequest(`/api/course-groups/${props.group.id}`, { method: 'PATCH', body: payload, tenant: props.tenantSlug });
        } else {
            await apiRequest('/api/course-groups', { method: 'POST', body: payload, tenant: props.tenantSlug });
        }
        toast.success(locale.t('education.saved'));
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
                    <DialogTitle>{{ group ? locale.t('education.editGroup') : locale.t('education.addGroup') }}</DialogTitle>
                </DialogHeader>
                <div class="grid max-h-[70vh] gap-3 overflow-y-auto py-4">
                    <Select v-model="courseValue">
                        <SelectTrigger class="w-full"><SelectValue :placeholder="locale.t('education.selectCourse')" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="c in courses" :key="c.id" :value="String(c.id)">{{ c.name }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <Input v-model="name" :placeholder="locale.t('education.groupName')" required />

                    <div class="grid grid-cols-2 gap-3">
                        <Select v-model="employeeValue">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">{{ locale.t('education.teacherNone') }}</SelectItem>
                                <SelectItem v-for="e in employees" :key="e.id" :value="String(e.id)">{{ e.name }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select v-model="resourceValue">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">{{ locale.t('education.roomNone') }}</SelectItem>
                                <SelectItem v-for="r in resources" :key="r.id" :value="String(r.id)">{{ r.name }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <p class="mb-1 text-xs ui-subtle">{{ locale.t('education.schedule') }}</p>
                        <div class="grid gap-2">
                            <div v-for="(slot, i) in schedule" :key="i" class="flex items-center gap-2">
                                <Select v-model.number="slot.weekday">
                                    <SelectTrigger class="w-24"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="wd in WEEKDAYS" :key="wd.value" :value="wd.value">{{ locale.t('booking.weekday.' + wd.key) }}</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Input v-model="slot.start_time" type="time" class="w-28" />
                                <Input v-model="slot.end_time" type="time" class="w-28" />
                                <Button type="button" variant="ghost" size="icon" :disabled="schedule.length <= 1" @click="removeSlot(i)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                            </div>
                        </div>
                        <Button type="button" variant="outline" size="sm" class="mt-2" @click="addSlot"><Plus class="h-4 w-4" />{{ locale.t('education.addSlot') }}</Button>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <InputGroup v-model.number="capacity" type="number" min="1" :placeholder="locale.t('education.groupCapacity')">
                            <template #suffix>{{ locale.t('education.studentsUnit') }}</template>
                        </InputGroup>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('education.startsOn') }}
                            <DatePicker v-model="startsOn" class="w-full" />
                        </label>
                        <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('education.endsOn') }}
                            <DatePicker v-model="endsOn" class="w-full" />
                        </label>
                    </div>

                    <Textarea v-model="notes" :placeholder="locale.t('booking.notes')" class="min-h-16" />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving || ! canSubmit">{{ locale.t('booking.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
