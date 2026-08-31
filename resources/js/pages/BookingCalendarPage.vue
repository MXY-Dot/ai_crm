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
import BookingMonthView from '../components/dashboard/booking/BookingMonthView.vue';
import BookingWeekView from '../components/dashboard/booking/BookingWeekView.vue';
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
type Employee = { id: number; name: string; branch_id: number | null };
type Branch = { id: number; name: string };

// Deliberately NOT toISOString().slice(0, 10) -- that reads the UTC calendar date, which
// is the wrong day for roughly a third of the day in any timezone ahead of UTC (e.g.
// Asia/Dushanbe, UTC+5, WERO's actual target market): local midnight there is still
// 19:00 the previous day in UTC. Extract the LOCAL calendar date instead.
function toLocalDateString(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const date = ref(toLocalDateString(new Date()));
const viewMode = ref<'day' | 'week' | 'month'>('day');
const employeeFilter = ref('all');
const branchFilter = ref('all');
const employees = ref<Employee[]>([]);
const services = ref<Service[]>([]);
const branches = ref<Branch[]>([]);
const bookings = ref<BookingRow[]>([]);
const loading = ref(true);
const newBookingOpen = ref(false);
const detailOpen = ref(false);
const selectedBookingId = ref<number | null>(null);
const newBookingSeed = ref<{ employeeId: number | null; iso: string | null }>({ employeeId: null, iso: null });

const visibleEmployees = computed(() => employees.value
    .filter((e) => employeeFilter.value === 'all' || String(e.id) === employeeFilter.value)
    .filter((e) => branchFilter.value === 'all' || String(e.branch_id) === branchFilter.value));

// Monday-first week, matching BookingWeekView/BookingMonthView's own grid math.
const weekStart = computed(() => {
    const d = new Date(date.value + 'T00:00:00');
    const weekday = (d.getDay() + 6) % 7;
    d.setDate(d.getDate() - weekday);
    return toLocalDateString(d);
});
const monthKey = computed(() => date.value.slice(0, 7));

function fetchRange(): { from: string; to: string } {
    if (viewMode.value === 'week') {
        const end = new Date(weekStart.value + 'T00:00:00');
        end.setDate(end.getDate() + 7);
        return { from: weekStart.value + 'T00:00:00', to: toLocalDateString(end) + 'T00:00:00' };
    }
    if (viewMode.value === 'month') {
        // A padded window wide enough to cover BookingMonthView's own leading/trailing grid days.
        const start = new Date(monthKey.value + '-01T00:00:00');
        start.setDate(start.getDate() - 7);
        const end = new Date(monthKey.value + '-01T00:00:00');
        end.setMonth(end.getMonth() + 1);
        end.setDate(end.getDate() + 7);
        return { from: toLocalDateString(start) + 'T00:00:00', to: toLocalDateString(end) + 'T00:00:00' };
    }
    return { from: date.value + 'T00:00:00', to: date.value + 'T23:59:59' };
}

async function loadStatic(): Promise<void> {
    try {
        const [employeesRes, servicesRes, branchesRes] = await Promise.all([
            apiRequest<{ data: Employee[] }>('/api/employees', { tenant: tenantSlug.value }),
            apiRequest<{ data: Service[] }>('/api/services', { tenant: tenantSlug.value }),
            apiRequest<{ data: Branch[] }>('/api/branches', { tenant: tenantSlug.value }),
        ]);
        employees.value = employeesRes.data;
        services.value = servicesRes.data;
        branches.value = branchesRes.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}

async function loadBookings(): Promise<void> {
    loading.value = true;
    try {
        const { from, to } = fetchRange();
        const params = new URLSearchParams({ date_from: from, date_to: to });
        if (branchFilter.value !== 'all') params.set('branch_id', branchFilter.value);
        const data = await apiRequest<BookingRow[]>('/api/bookings?' + params, { tenant: tenantSlug.value });
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

watch([date, viewMode, branchFilter], loadBookings);

function selectDay(iso: string): void {
    date.value = iso;
    viewMode.value = 'day';
}

function shiftDate(direction: number): void {
    const d = new Date(date.value + 'T00:00:00');
    if (viewMode.value === 'week') {
        d.setDate(d.getDate() + direction * 7);
    } else if (viewMode.value === 'month') {
        d.setMonth(d.getMonth() + direction);
    } else {
        d.setDate(d.getDate() + direction);
    }
    date.value = toLocalDateString(d);
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
                <Button variant="outline" size="sm" @click="date = toLocalDateString(new Date())">{{ locale.t('booking.today') }}</Button>
            </div>
            <Select v-if="branches.length" v-model="branchFilter">
                <SelectTrigger class="w-48"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{ locale.t('booking.allBranches') }}</SelectItem>
                    <SelectItem v-for="b in branches" :key="b.id" :value="String(b.id)">{{ b.name }}</SelectItem>
                </SelectContent>
            </Select>
            <Select v-model="employeeFilter">
                <SelectTrigger class="w-56"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{ locale.t('booking.allEmployees') }}</SelectItem>
                    <SelectItem v-for="e in employees" :key="e.id" :value="String(e.id)">{{ e.name }}</SelectItem>
                </SelectContent>
            </Select>
            <div class="ml-auto flex items-center gap-1 rounded-lg border border-border p-0.5">
                <Button :variant="viewMode === 'day' ? 'secondary' : 'ghost'" size="sm" @click="viewMode = 'day'">{{ locale.t('booking.viewDay') }}</Button>
                <Button :variant="viewMode === 'week' ? 'secondary' : 'ghost'" size="sm" @click="viewMode = 'week'">{{ locale.t('booking.viewWeek') }}</Button>
                <Button :variant="viewMode === 'month' ? 'secondary' : 'ghost'" size="sm" @click="viewMode = 'month'">{{ locale.t('booking.viewMonth') }}</Button>
            </div>
        </div>

        <p v-if="! employees.length || ! services.length" class="text-sm ui-subtle">{{ locale.t('booking.notEnoughData') }}</p>
        <Skeleton v-else-if="loading" class="h-96 rounded-xl" />
        <BookingCalendarGrid
            v-else-if="viewMode === 'day'"
            :date="date"
            :employees="visibleEmployees"
            :bookings="bookings"
            @create-at="(employeeId, iso) => openCreate(employeeId, iso)"
            @open="openDetail"
        />
        <BookingWeekView
            v-else-if="viewMode === 'week'"
            :week-start="weekStart"
            :employees="visibleEmployees"
            :bookings="bookings"
            @select-day="selectDay"
            @open="openDetail"
        />
        <BookingMonthView
            v-else
            :month="monthKey"
            :bookings="bookings"
            @select-day="selectDay"
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
