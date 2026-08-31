<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { DatePicker } from '../../ui/date-picker';
import { Skeleton } from '../../ui/skeleton';

type Report = {
    period: { from: string; to: string };
    counts: { total: number; confirmed: number; completed: number; cancelled: number; no_show: number; reschedules: number };
    money: { revenue: number; prepayments_received: number; refunds: number };
    popular_services: { service_id: number; name: string; count: number; revenue: number }[];
    employee_load: { employee_id: number; name: string; bookings: number; hours: number }[];
    repeat_customers: number;
};

const props = defineProps<{ tenantSlug: string }>();
const locale = useLocaleStore();
const loading = ref(true);
const report = ref<Report | null>(null);

function defaultFrom(): string {
    const d = new Date();
    d.setDate(d.getDate() - 30);
    return d.toISOString().slice(0, 10);
}

const dateFrom = ref(defaultFrom());
const dateTo = ref(new Date().toISOString().slice(0, 10));

async function load(): Promise<void> {
    loading.value = true;
    try {
        report.value = await apiRequest<Report>(`/api/booking-reports?date_from=${dateFrom.value}&date_to=${dateTo.value}`, { tenant: props.tenantSlug });
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <Card :title="locale.t('booking.reportsTitle')">
        <div class="flex flex-wrap items-end gap-2 pb-4">
            <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('booking.reportsFrom') }}
                <DatePicker v-model="dateFrom" class="h-9" />
            </label>
            <label class="grid gap-1 text-xs ui-subtle">{{ locale.t('booking.reportsTo') }}
                <DatePicker v-model="dateTo" class="h-9" />
            </label>
            <Button size="sm" :disabled="loading" @click="load">{{ locale.t('booking.reportsRefresh') }}</Button>
        </div>

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 4" :key="i" class="h-10 rounded-lg" />
        </div>
        <div v-else-if="report" class="grid gap-6 pb-2 text-sm">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('booking.reportsTotal') }}</p>
                    <p class="text-lg font-semibold ui-text">{{ report.counts.total }}</p>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('booking.reportsCompleted') }}</p>
                    <p class="text-lg font-semibold ui-text">{{ report.counts.completed }}</p>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('booking.reportsCancelled') }}</p>
                    <p class="text-lg font-semibold ui-text">{{ report.counts.cancelled }}</p>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('booking.reportsNoShow') }}</p>
                    <p class="text-lg font-semibold ui-text">{{ report.counts.no_show }}</p>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('booking.reportsReschedules') }}</p>
                    <p class="text-lg font-semibold ui-text">{{ report.counts.reschedules }}</p>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('booking.reportsRepeatCustomers') }}</p>
                    <p class="text-lg font-semibold ui-text">{{ report.repeat_customers }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('booking.reportsRevenue') }}</p>
                    <p class="text-lg font-semibold ui-text">{{ report.money.revenue }}</p>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('booking.reportsPrepaymentsReceived') }}</p>
                    <p class="text-lg font-semibold ui-text">{{ report.money.prepayments_received }}</p>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <p class="text-xs ui-subtle">{{ locale.t('booking.reportsRefunds') }}</p>
                    <p class="text-lg font-semibold ui-text">{{ report.money.refunds }}</p>
                </div>
            </div>

            <div class="grid gap-2">
                <p class="text-xs font-medium ui-subtle">{{ locale.t('booking.reportsPopularServices') }}</p>
                <div v-if="! report.popular_services.length" class="text-xs ui-subtle">{{ locale.t('booking.reportsNoData') }}</div>
                <div v-for="s in report.popular_services" :key="s.service_id" class="flex items-center justify-between border-b border-border py-1.5 text-xs last:border-0">
                    <span class="ui-text">{{ s.name }}</span>
                    <span class="ui-subtle">{{ s.count }} · {{ s.revenue }}</span>
                </div>
            </div>

            <div class="grid gap-2">
                <p class="text-xs font-medium ui-subtle">{{ locale.t('booking.reportsEmployeeLoad') }}</p>
                <div v-if="! report.employee_load.length" class="text-xs ui-subtle">{{ locale.t('booking.reportsNoData') }}</div>
                <div v-for="e in report.employee_load" :key="e.employee_id" class="flex items-center justify-between border-b border-border py-1.5 text-xs last:border-0">
                    <span class="ui-text">{{ e.name }}</span>
                    <span class="ui-subtle">{{ e.bookings }} · {{ e.hours }} {{ locale.t('booking.reportsHours') }}</span>
                </div>
            </div>
        </div>
    </Card>
</template>
