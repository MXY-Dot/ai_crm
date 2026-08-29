<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { ChevronLeft, ChevronRight, Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import BookingCalendarGrid, { type BookingRow } from '../components/dashboard/booking/BookingCalendarGrid.vue';
import BookingDetailDialog from '../components/dashboard/booking/BookingDetailDialog.vue';
import NewBookingDialog from '../components/dashboard/booking/NewBookingDialog.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

defineOptions({ layout: AppLayout });

const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { company, customers, tenant } = storeToRefs(store);
// crmDashboard's own `companyId` computed is a private local (never in its
// return object, so `store.companyId` is always undefined) -- derive it here.
const companyId = computed(() => company.value?.id ?? null);
const tenantSlug = computed(() => tenant.value?.slug ?? '');

type Service = { id: number; name: string; duration_minutes: number; price: number };
type Employee = { id: number; name: string };

const date = ref(new Date().toISOString().slice(0, 10));
const employeeFilter = ref('all');
const employees = ref<Employee[]>([]);
const services = ref<Service[]>([]);
const bookings = ref<BookingRow[]>([]);
const loading = ref(true);
const newBookingOpen = ref(false);
const detailOpen = ref(false);
const selectedBookingId = ref<number | null>(null);
const newBookingSeed = ref<{ employeeId: number | null; iso: string | null }>({ employeeId: null, iso: null });

const visibleEmployees = computed(() => employeeFilter.value === 'all' ? employees.value : employees.value.filter((e) => String(e.id) === employeeFilter.value));

async function loadStatic(): Promise<void> {
    try {
        const [employeesRes, servicesRes] = await Promise.all([
            apiRequest<{ data: Employee[] }>('/api/employees', { tenant: tenantSlug.value }),
            apiRequest<{ data: Service[] }>('/api/services', { tenant: tenantSlug.value }),
        ]);
        employees.value = employeesRes.data;
        services.value = servicesRes.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}

async function loadBookings(): Promise<void> {
    loading.value = true;
    try {
        const from = date.value + 'T00:00:00';
        const to = date.value + 'T23:59:59';
        const data = await apiRequest<BookingRow[]>('/api/bookings?' + new URLSearchParams({ date_from: from, date_to: to }), { tenant: tenantSlug.value });
        bookings.value = data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    await loadStatic();
    await loadBookings();
});

watch(date, loadBookings);

function shiftDate(days: number): void {
    const d = new Date(date.value + 'T00:00:00');
    d.setDate(d.getDate() + days);
    date.value = d.toISOString().slice(0, 10);
}

function openCreate(employeeId?: number, iso?: string): void {
    newBookingSeed.value = { employeeId: employeeId ?? null, iso: iso ?? null };
    newBookingOpen.value = true;
}

function openDetail(id: number): void {
    selectedBookingId.value = id;
    detailOpen.value = true;
}
</script>

<template>
    <section class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('booking.calendarTitle') }}</h2>
                <p class="mt-1 text-sm ui-subtle">{{ locale.t('booking.calendarSubtitle') }}</p>
            </div>
            <Button v-if="services.length && employees.length" @click="openCreate()"><Plus class="h-4 w-4" />{{ locale.t('booking.newBooking') }}</Button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-1">
                <Button variant="outline" size="icon" @click="shiftDate(-1)"><ChevronLeft class="h-4 w-4" /></Button>
                <Input v-model="date" type="date" class="w-40" />
                <Button variant="outline" size="icon" @click="shiftDate(1)"><ChevronRight class="h-4 w-4" /></Button>
                <Button variant="outline" size="sm" @click="date = new Date().toISOString().slice(0, 10)">{{ locale.t('booking.today') }}</Button>
            </div>
            <Select v-model="employeeFilter">
                <SelectTrigger class="w-56"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{ locale.t('booking.allEmployees') }}</SelectItem>
                    <SelectItem v-for="e in employees" :key="e.id" :value="String(e.id)">{{ e.name }}</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <p v-if="! employees.length || ! services.length" class="text-sm ui-subtle">{{ locale.t('booking.notEnoughData') }}</p>
        <Skeleton v-else-if="loading" class="h-96 rounded-xl" />
        <BookingCalendarGrid
            v-else
            :date="date"
            :employees="visibleEmployees"
            :bookings="bookings"
            @create-at="(employeeId, iso) => openCreate(employeeId, iso)"
            @open="openDetail"
        />

        <NewBookingDialog
            v-model:open="newBookingOpen"
            :company-id="companyId as number"
            :tenant-slug="tenantSlug"
            :services="services"
            :employees="employees"
            :customers="customers as unknown as Array<{ id: number; name: string; phone: string | null }>"
            :initial-date="date"
            :initial-employee-id="newBookingSeed.employeeId"
            :initial-iso="newBookingSeed.iso"
            @created="loadBookings"
        />
        <BookingDetailDialog v-model:open="detailOpen" :booking-id="selectedBookingId" :tenant-slug="tenantSlug" @changed="loadBookings" />
    </section>
</template>
