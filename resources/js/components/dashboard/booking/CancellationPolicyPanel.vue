<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Input } from '../../ui/input';
import { Skeleton } from '../../ui/skeleton';
import { Switch } from '../../ui/switch';

const props = defineProps<{ tenantSlug: string }>();
const locale = useLocaleStore();
const loading = ref(true);
const saving = ref(false);
const form = ref({ free_reschedule_hours: 48, late_reschedule_hours: 24, max_client_reschedules: 2, no_show_forfeits_prepayment: true, hold_minutes: 15 });

async function load(): Promise<void> {
    loading.value = true;
    try {
        const data = await apiRequest<typeof form.value>('/api/cancellation-policy', { tenant: props.tenantSlug });
        form.value = data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

async function save(): Promise<void> {
    saving.value = true;
    try {
        form.value = await apiRequest('/api/cancellation-policy', { method: 'PATCH', body: form.value, tenant: props.tenantSlug });
        toast.success(locale.t('booking.saved'));
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Card :title="locale.t('booking.tabPolicy')">
        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 4" :key="i" class="h-10 rounded-lg" />
        </div>
        <div v-else class="grid max-w-md gap-4 pb-4">
            <label class="grid gap-1 text-sm ui-text">{{ locale.t('booking.policyFree') }}
                <Input v-model.number="form.free_reschedule_hours" type="number" min="0" />
            </label>
            <label class="grid gap-1 text-sm ui-text">{{ locale.t('booking.policyLate') }}
                <Input v-model.number="form.late_reschedule_hours" type="number" min="0" />
            </label>
            <label class="grid gap-1 text-sm ui-text">{{ locale.t('booking.policyMax') }}
                <Input v-model.number="form.max_client_reschedules" type="number" min="0" />
            </label>
            <label class="grid gap-1 text-sm ui-text">{{ locale.t('booking.policyHold') }}
                <Input v-model.number="form.hold_minutes" type="number" min="10" max="60" />
            </label>
            <label class="flex items-center justify-between text-sm ui-text">{{ locale.t('booking.policyForfeit') }}
                <Switch v-model="form.no_show_forfeits_prepayment" />
            </label>
            <Button class="w-fit" :disabled="saving" @click="save">{{ locale.t('booking.save') }}</Button>
        </div>
    </Card>
</template>
