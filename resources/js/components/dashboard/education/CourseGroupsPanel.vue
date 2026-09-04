<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Plus } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import DataTable from '../DataTable.vue';
import { Badge } from '../../ui/badge';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import CourseGroupDetailDialog from './CourseGroupDetailDialog.vue';
import CourseGroupFormDialog from './CourseGroupFormDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();

type Slot = { weekday: number; start_time: string; end_time: string };
type GroupRow = {
    id: number; name: string; status: string; capacity: number | null; enrollments_count: number;
    course: { id: number; name: string } | null; employee: { id: number; name: string } | null; resource: { id: number; name: string } | null;
    schedule: Slot[];
};

const groups = ref<GroupRow[]>([]);
const loading = ref(true);
const newOpen = ref(false);
const detailOpen = ref(false);
const selectedId = ref<number | null>(null);

const WEEKDAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

async function load(): Promise<void> {
    loading.value = true;
    try {
        const data = await apiRequest<{ data: GroupRow[] }>('/api/course-groups', { tenant: props.tenantSlug });
        groups.value = data.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function openDetail(id: number): void {
    selectedId.value = id;
    detailOpen.value = true;
}

function formatSchedule(schedule: Slot[]): string {
    return schedule.map((s) => `${locale.t('booking.weekday.' + WEEKDAY_KEYS[s.weekday])} ${s.start_time}`).join(', ');
}

const STATUS_TONE: Record<string, 'neutral' | 'green' | 'amber' | 'red' | 'blue'> = {
    recruiting: 'amber', active: 'green', completed: 'neutral', cancelled: 'red',
};
</script>

<template>
    <Card :title="locale.t('education.tabGroups')">
        <template #actions>
            <Button size="sm" @click="newOpen = true"><Plus class="h-4 w-4" />{{ locale.t('education.addGroup') }}</Button>
        </template>

        <DataTable
            embedded
            :loading="loading"
            :row-count="groups.length"
            :column-count="4"
            :empty-message="locale.t('education.noGroups')"
            min-width="min-w-full"
        >
            <template #thead>
                <th class="p-3">{{ locale.t('education.tabGroups') }}</th>
                <th class="p-3">{{ locale.t('education.schedule') }}</th>
                <th class="p-3 text-right">{{ locale.t('education.groupCapacity') }}</th>
                <th class="p-3">{{ locale.t('common.status') }}</th>
            </template>

            <tr v-for="g in groups" :key="g.id" class="cursor-pointer transition hover:bg-muted" @click="openDetail(g.id)">
                <td class="p-3">
                    <p class="text-sm font-medium ui-text">{{ g.name }} · {{ g.course?.name }}</p>
                    <p v-if="g.employee" class="text-xs ui-subtle">{{ g.employee.name }}</p>
                </td>
                <td class="p-3 text-xs ui-subtle">{{ formatSchedule(g.schedule) }}</td>
                <td class="p-3 text-right text-xs ui-subtle">
                    <span v-if="g.capacity">{{ g.enrollments_count }}/{{ g.capacity }}</span>
                    <span v-else>{{ g.enrollments_count }} {{ locale.t('education.studentsUnit') }}</span>
                </td>
                <td class="p-3"><Badge :tone="STATUS_TONE[g.status] ?? 'neutral'">{{ locale.t('education.statuses.' + g.status) }}</Badge></td>
            </tr>
        </DataTable>

        <CourseGroupFormDialog v-model:open="newOpen" :group="null" :company-id="companyId" :tenant-slug="tenantSlug" @saved="load" />
        <CourseGroupDetailDialog v-model:open="detailOpen" :group-id="selectedId" :company-id="companyId" :tenant-slug="tenantSlug" @changed="load" />
    </Card>
</template>
