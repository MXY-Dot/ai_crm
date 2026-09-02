<script setup lang="ts">
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input, InputGroup } from '../../ui/input';
import { Textarea } from '../../ui/textarea';

export type TourRow = { id: number; name: string; destination: string | null; description: string | null; category: string | null; price: number; duration_days: number | null; is_active: boolean };

const props = defineProps<{ open: boolean; tour: TourRow | null; companyId: number; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; saved: [] }>();
const locale = useLocaleStore();

const form = ref({ name: '', destination: '', description: '', category: '', price: null as number | null, duration_days: null as number | null });
const saving = ref(false);

watch(() => props.open, (open) => {
    if (! open) return;
    form.value = props.tour
        ? { name: props.tour.name, destination: props.tour.destination ?? '', description: props.tour.description ?? '', category: props.tour.category ?? '', price: props.tour.price, duration_days: props.tour.duration_days }
        : { name: '', destination: '', description: '', category: '', price: null, duration_days: null };
});

async function submit(): Promise<void> {
    saving.value = true;
    try {
        const payload = { ...form.value, company_id: props.companyId };
        if (props.tour) {
            await apiRequest(`/api/tours/${props.tour.id}`, { method: 'PATCH', body: payload, tenant: props.tenantSlug });
        } else {
            await apiRequest('/api/tours', { method: 'POST', body: payload, tenant: props.tenantSlug });
        }
        toast.success(locale.t('travel.saved'));
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
                    <DialogTitle>{{ tour ? locale.t('travel.editTour') : locale.t('travel.addTour') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <Input v-model="form.name" :placeholder="locale.t('travel.tourName')" required />
                    <Input v-model="form.destination" :placeholder="locale.t('travel.tourDestination')" />
                    <Textarea v-model="form.description" :placeholder="locale.t('education.courseDescription')" class="min-h-16" />
                    <Input v-model="form.category" :placeholder="locale.t('education.courseCategory')" />
                    <div class="grid grid-cols-2 gap-3">
                        <InputGroup v-model.number="form.price" type="number" min="0" step="0.01" :placeholder="locale.t('travel.tourPrice')">
                            <template #suffix>{{ locale.t('commerce.currency') }}</template>
                        </InputGroup>
                        <InputGroup v-model.number="form.duration_days" type="number" min="1" :placeholder="locale.t('travel.tourDuration')">
                            <template #suffix>{{ locale.t('travel.daysUnit') }}</template>
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
