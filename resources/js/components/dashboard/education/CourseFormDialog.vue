<script setup lang="ts">
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input, InputGroup } from '../../ui/input';
import { Textarea } from '../../ui/textarea';

export type CourseRow = { id: number; name: string; description: string | null; category: string | null; price: number; duration_lessons: number | null; is_active: boolean };

const props = defineProps<{ open: boolean; course: CourseRow | null; companyId: number; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; saved: [] }>();
const locale = useLocaleStore();

const form = ref({ name: '', description: '', category: '', price: null as number | null, duration_lessons: null as number | null });
const saving = ref(false);

watch(() => props.open, (open) => {
    if (! open) return;
    form.value = props.course
        ? { name: props.course.name, description: props.course.description ?? '', category: props.course.category ?? '', price: props.course.price, duration_lessons: props.course.duration_lessons }
        : { name: '', description: '', category: '', price: null, duration_lessons: null };
});

async function submit(): Promise<void> {
    saving.value = true;
    try {
        const payload = { ...form.value, company_id: props.companyId };
        if (props.course) {
            await apiRequest(`/api/courses/${props.course.id}`, { method: 'PATCH', body: payload, tenant: props.tenantSlug });
        } else {
            await apiRequest('/api/courses', { method: 'POST', body: payload, tenant: props.tenantSlug });
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
        <DialogContent class="sm:max-w-sm">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ course ? locale.t('education.editCourse') : locale.t('education.addCourse') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <Input v-model="form.name" :placeholder="locale.t('education.courseName')" required />
                    <Textarea v-model="form.description" :placeholder="locale.t('education.courseDescription')" class="min-h-16" />
                    <Input v-model="form.category" :placeholder="locale.t('education.courseCategory')" />
                    <div class="grid grid-cols-2 gap-3">
                        <InputGroup v-model.number="form.price" type="number" min="0" step="0.01" :placeholder="locale.t('education.coursePrice')">
                            <template #suffix>{{ locale.t('commerce.currency') }}</template>
                        </InputGroup>
                        <InputGroup v-model.number="form.duration_lessons" type="number" min="1" :placeholder="locale.t('education.courseDuration')">
                            <template #suffix>{{ locale.t('education.lessonsUnit') }}</template>
                        </InputGroup>
                    </div>
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving">{{ locale.t('booking.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
