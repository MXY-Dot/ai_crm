<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Pencil, Plus, Presentation, Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { EmptyState } from '../../ui/empty-state';
import { Money } from '../../ui/money';
import { Skeleton } from '../../ui/skeleton';
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

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-12 rounded-lg" />
        </div>
        <EmptyState v-else-if="! courses.length" :icon="Presentation" :title="locale.t('education.noCourses')" />
        <div v-else class="divide-y divide-border pb-2">
            <div v-for="course in courses" :key="course.id" class="flex items-center justify-between gap-3 py-3">
                <div>
                    <p class="text-sm font-medium ui-text">{{ course.name }}<span v-if="course.category" class="font-normal ui-subtle"> · {{ course.category }}</span></p>
                    <p class="text-xs ui-subtle"><Money :value="course.price" tone="muted" /><span v-if="course.duration_lessons"> · {{ course.duration_lessons }} {{ locale.t('education.lessonsUnit') }}</span></p>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="ghost" size="icon" @click="openEdit(course)"><Pencil class="h-4 w-4" /></Button>
                    <Button variant="ghost" size="icon" @click="remove(course)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                </div>
            </div>
        </div>

        <CourseFormDialog v-model:open="dialogOpen" :course="editing" :company-id="companyId" :tenant-slug="tenantSlug" @saved="load" />
    </Card>
</template>
