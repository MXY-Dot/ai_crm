<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import DataTable from '../DataTable.vue';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Money } from '../../ui/money';
import CourseFormDialog, { type CourseRow } from './CourseFormDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();

const courses = ref<CourseRow[]>([]);
const loading = ref(true);
const dialogOpen = ref(false);
const editing = ref<CourseRow | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const data = await apiRequest<{ data: CourseRow[] }>('/api/courses', { tenant: props.tenantSlug });
        courses.value = data.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function openCreate(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function openEdit(course: CourseRow): void {
    editing.value = course;
    dialogOpen.value = true;
}

async function remove(course: CourseRow): Promise<void> {
    if (! confirm(course.name + '?')) return;
    try {
        await apiRequest(`/api/courses/${course.id}`, { method: 'DELETE', tenant: props.tenantSlug });
        courses.value = courses.value.filter((c) => c.id !== course.id);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}
</script>

<template>
    <Card :title="locale.t('education.tabCourses')">
        <template #actions>
            <Button size="sm" @click="openCreate"><Plus class="h-4 w-4" />{{ locale.t('education.addCourse') }}</Button>
        </template>

        <DataTable
            embedded
            :loading="loading"
            :row-count="courses.length"
            :column-count="3"
            :empty-message="locale.t('education.noCourses')"
            min-width="min-w-full"
        >
            <template #thead>
                <th class="p-3">{{ locale.t('education.tabCourses') }}</th>
                <th class="p-3 text-right">{{ locale.t('common.price') }}</th>
                <th class="p-3 text-right">{{ locale.t('common.actions') }}</th>
            </template>

            <tr v-for="course in courses" :key="course.id">
                <td class="p-3">
                    <p class="text-sm font-medium ui-text">{{ course.name }}<span v-if="course.category" class="font-normal ui-subtle"> · {{ course.category }}</span></p>
                    <p v-if="course.duration_lessons" class="text-xs ui-subtle">{{ course.duration_lessons }} {{ locale.t('education.lessonsUnit') }}</p>
                </td>
                <td class="p-3 text-right"><Money :value="course.price" tone="muted" /></td>
                <td class="p-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                        <Button variant="ghost" size="icon" @click="openEdit(course)"><Pencil class="h-4 w-4" /></Button>
                        <Button variant="ghost" size="icon" @click="remove(course)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                    </div>
                </td>
            </tr>
        </DataTable>

        <CourseFormDialog v-model:open="dialogOpen" :course="editing" :company-id="companyId" :tenant-slug="tenantSlug" @saved="load" />
    </Card>
</template>
