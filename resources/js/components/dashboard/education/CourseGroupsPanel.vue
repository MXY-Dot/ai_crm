<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Plus, UsersRound } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Badge } from '../../ui/badge';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { EmptyState } from '../../ui/empty-state';
import { Skeleton } from '../../ui/skeleton';
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

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-14 rounded-lg" />
        </div>
        <EmptyState v-else-if="! groups.length" :icon="UsersRound" :title="locale.t('education.noGroups')" />
        <div v-else class="divide-y divide-border pb-2">
            <button
                v-for="g in groups" :key="g.id" type="button"
                class="flex w-full flex-wrap items-center justify-between gap-3 py-3 text-left transition-colors hover:bg-accent/40"
                @click="openDetail(g.id)"
            >
                <div class="min-w-0">
                    <p class="text-sm font-medium ui-text">{{ g.name }} · {{ g.course?.name }}</p>
                    <p class="text-xs ui-subtle">
                        <span v-if="g.employee">{{ g.employee.name }} · </span>{{ formatSchedule(g.schedule) }}
                        <span v-if="g.capacity"> · {{ g.enrollments_count }}/{{ g.capacity }}</span>
                        <span v-else> · {{ g.enrollments_count }} {{ locale.t('education.studentsUnit') }}</span>
                    </p>
                </div>
                <Badge :tone="STATUS_TONE[g.status] ?? 'neutral'">{{ locale.t('education.statuses.' + g.status) }}</Badge>
            </button>
        </div>

        <CourseGroupFormDialog v-model:open="newOpen" :group="null" :company-id="companyId" :tenant-slug="tenantSlug" @saved="load" />
        <CourseGroupDetailDialog v-model:open="detailOpen" :group-id="selectedId" :company-id="companyId" :tenant-slug="tenantSlug" @changed="load" />
    </Card>
</template>
