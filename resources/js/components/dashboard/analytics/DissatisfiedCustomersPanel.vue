<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { CheckCircle2, MessageSquare, UserPlus } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Skeleton } from '../../ui/skeleton';

export type DissatisfiedCustomerRow = {
    conversation_id: number;
    customer_name: string | null;
    customer_phone: string | null;
    channel: string | null;
    date: string | null;
    reason: string | null;
    summary: string | null;
    status: string | null;
    assigned_user_id: number | null;
    assigned_user_name: string | null;
};

const props = defineProps<{ data: DissatisfiedCustomerRow[] | null; loading: boolean }>();
const emit = defineEmits<{ changed: [] }>();
const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { user, tenant } = storeToRefs(store);
const busyId = ref<number | null>(null);

function openChat(): void {
    router.visit('/inbox');
}

async function assignToMe(row: DissatisfiedCustomerRow): Promise<void> {
    if (! user.value?.id) return;
    busyId.value = row.conversation_id;
    try {
        await apiRequest(`/api/conversations/${row.conversation_id}/assignment`, {
            method: 'PATCH',
            body: { assigned_user_id: user.value.id },
            tenant: tenant.value?.slug,
        });
        toast.success(locale.t('booking.saved'));
        emit('changed');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busyId.value = null;
    }
}

async function markResolved(row: DissatisfiedCustomerRow): Promise<void> {
    busyId.value = row.conversation_id;
    try {
        await apiRequest(`/api/conversations/${row.conversation_id}/resolve`, { method: 'POST', tenant: tenant.value?.slug });
        toast.success(locale.t('booking.saved'));
        emit('changed');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busyId.value = null;
    }
}
</script>

<template>
    <Card :title="locale.t('analytics.dissatisfied.title')" :subtitle="locale.t('analytics.dissatisfied.subtitle')">
        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-20 rounded-lg" />
        </div>
        <p v-else-if="! data || ! data.length" class="pb-4 text-sm ui-subtle">{{ locale.t('analytics.dissatisfied.empty') }}</p>
        <div v-else class="divide-y divide-border pb-2">
            <div v-for="row in data" :key="row.conversation_id" class="grid gap-2 py-3 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="font-medium ui-text">{{ row.customer_name || '—' }} <span class="font-normal ui-subtle">· {{ row.customer_phone || '—' }}</span></p>
                    <span class="text-xs ui-subtle">{{ row.date ? new Date(row.date).toLocaleString() : '' }}</span>
                </div>
                <p class="text-xs ui-subtle">{{ locale.t('analytics.dissatisfied.reason') }}: {{ row.reason || locale.t('analytics.dissatisfied.noReason') }}</p>
                <p v-if="row.summary" class="text-xs ui-subtle">{{ row.summary }}</p>
                <p class="text-xs ui-subtle">{{ row.assigned_user_name || locale.t('analytics.dissatisfied.unassigned') }}</p>
                <div class="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" @click="openChat"><MessageSquare class="h-4 w-4" />{{ locale.t('analytics.dissatisfied.openChat') }}</Button>
                    <Button size="sm" variant="outline" :disabled="busyId === row.conversation_id" @click="assignToMe(row)"><UserPlus class="h-4 w-4" />{{ locale.t('analytics.dissatisfied.assign') }}</Button>
                    <Button size="sm" variant="outline" :disabled="busyId === row.conversation_id || row.status === 'closed'" @click="markResolved(row)"><CheckCircle2 class="h-4 w-4" />{{ locale.t('analytics.dissatisfied.markResolved') }}</Button>
                </div>
            </div>
        </div>
    </Card>
</template>
