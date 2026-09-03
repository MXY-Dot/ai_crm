<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { ChevronLeft, ChevronRight, Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import BookingDetailDialog from '../components/dashboard/booking/BookingDetailDialog.vue';
import NewBookingDialog from '../components/dashboard/booking/NewBookingDialog.vue';
import NewRoomReservationDialog from '../components/dashboard/booking/NewRoomReservationDialog.vue';
import NewTableReservationDialog from '../components/dashboard/booking/NewTableReservationDialog.vue';
import RoomReservationDetailDialog from '../components/dashboard/booking/RoomReservationDetailDialog.vue';
import TableReservationDetailDialog from '../components/dashboard/booking/TableReservationDetailDialog.vue';
import CalendarDayAgenda from '../components/dashboard/calendar/CalendarDayAgenda.vue';
import CalendarDayGrid from '../components/dashboard/calendar/CalendarDayGrid.vue';
import CalendarMonthView from '../components/dashboard/calendar/CalendarMonthView.vue';
import CalendarWeekView from '../components/dashboard/calendar/CalendarWeekView.vue';
import CourseGroupDetailDialog from '../components/dashboard/education/CourseGroupDetailDialog.vue';
import CourseGroupFormDialog from '../components/dashboard/education/CourseGroupFormDialog.vue';
import NewRepairOrderDialog from '../components/dashboard/autoservice/NewRepairOrderDialog.vue';
import RepairOrderDetailDialog from '../components/dashboard/autoservice/RepairOrderDetailDialog.vue';
import NewShipmentDialog from '../components/dashboard/logistics/NewShipmentDialog.vue';
import ShipmentDetailDialog from '../components/dashboard/logistics/ShipmentDetailDialog.vue';
import TourDepartureDetailDialog from '../components/dashboard/travel/TourDepartureDetailDialog.vue';
import TourDepartureFormDialog from '../components/dashboard/travel/TourDepartureFormDialog.vue';
import { HOUR_GRID_MODULES, MODULE_ACCENTS, MODULE_ICONS, toLocalDateString, type CalendarEvent, type CalendarResource } from '../lib/calendar';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

defineOptions({ layout: AppLayout });

const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { company, tenant, customers } = storeToRefs(store);
// crmDashboard's own `companyId` computed is a private local (never in its
// return object, so `store.companyId` is always undefined) -- derive it here,
// same as BookingCalendarPage.vue does.
const companyId = computed(() => company.value?.id ?? null);
const tenantSlug = computed(() => tenant.value?.slug ?? '');

type ModuleOption = { key: string; label: string };
type Branch = { id: number; name: string };
type Service = { id: number; name: string; duration_minutes: number; price: number };
type Employee = { id: number; name: string };

const date = ref(toLocalDateString(new Date()));
const viewMode = ref<'day' | 'week' | 'month'>('day');
const branchFilter = ref('all');
const branches = ref<Branch[]>([]);
const modules = ref<ModuleOption[]>([]);
// NewBookingDialog (booking_calendar's own create dialog) needs these to pick
// a service/specialist -- fetched eagerly alongside modules/branches, same as
// how branches are already fetched regardless of which module ends up active.
const services = ref<Service[]>([]);
const employees = ref<Employee[]>([]);
const activeModule = ref<string | null>(null);
const resources = ref<CalendarResource[]>([]);
const events = ref<CalendarEvent[]>([]);
const modulesLoading = ref(true);
const eventsLoading = ref(true);
// Only the very first load blanks the view with a skeleton -- every later
// reload (switching module/date/view/branch) keeps the current grid mounted
// and just dims it a touch while fresh data comes in, so filtering never
// makes the whole calendar flash away and reappear.
const initialLoad = ref(true);
const createOpen = ref(false);

const weekStart = computed(() => {
    const d = new Date(date.value + 'T00:00:00');
    const weekday = (d.getDay() + 6) % 7;
    d.setDate(d.getDate() - weekday);
    return toLocalDateString(d);
});
const monthKey = computed(() => date.value.slice(0, 7));
const useDayGrid = computed(() => activeModule.value !== null && HOUR_GRID_MODULES.has(activeModule.value) && resources.value.length > 0);

function fetchRange(): { from: string; to: string } {
    if (viewMode.value === 'week') {
        const end = new Date(weekStart.value + 'T00:00:00');
        end.setDate(end.getDate() + 7);
        return { from: weekStart.value + 'T00:00:00', to: toLocalDateString(end) + 'T00:00:00' };
    }
    if (viewMode.value === 'month') {
        const start = new Date(monthKey.value + '-01T00:00:00');
        start.setDate(start.getDate() - 7);
        const end = new Date(monthKey.value + '-01T00:00:00');
        end.setMonth(end.getMonth() + 1);
        end.setDate(end.getDate() + 7);
        return { from: toLocalDateString(start) + 'T00:00:00', to: toLocalDateString(end) + 'T00:00:00' };
    }
    // Day view alone can still show a multi-night/multi-day event that only
    // OVERLAPS today (e.g. a hotel stay that started yesterday) -- widen the
    // fetch window a bit either side so CalendarDayAgenda's own eventOnDate()
    // overlap check has the data to find it, same reasoning as BookingCalendarPage's own +/-7-day month padding.
    const start = new Date(date.value + 'T00:00:00');
    start.setDate(start.getDate() - 14);
    const end = new Date(date.value + 'T00:00:00');
    end.setDate(end.getDate() + 15);
    return { from: toLocalDateString(start) + 'T00:00:00', to: toLocalDateString(end) + 'T00:00:00' };
}

async function loadModules(): Promise<void> {
    modulesLoading.value = true;
    try {
        const [modulesRes, branchesRes, servicesRes, employeesRes] = await Promise.all([
            apiRequest<{ modules: ModuleOption[] }>('/api/calendar/modules', { tenant: tenantSlug.value }),
            apiRequest<{ data: Branch[] }>('/api/branches', { tenant: tenantSlug.value }),
            apiRequest<{ data: Service[] }>('/api/services', { tenant: tenantSlug.value }),
            apiRequest<{ data: Employee[] }>('/api/employees', { tenant: tenantSlug.value }),
        ]);
        modules.value = modulesRes.modules;
        branches.value = branchesRes.data;
        services.value = servicesRes.data;
        employees.value = employeesRes.data;
        if (! activeModule.value && modules.value.length) {
            activeModule.value = modules.value[0].key;
        }
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        modulesLoading.value = false;
    }
}

async function loadEvents(): Promise<void> {
    if (! activeModule.value) return;

    eventsLoading.value = true;
    try {
        const { from, to } = fetchRange();
        const params = new URLSearchParams({ module: activeModule.value, date_from: from, date_to: to });
        if (branchFilter.value !== 'all') params.set('branch_id', branchFilter.value);
        const data = await apiRequest<{ resources: CalendarResource[]; events: CalendarEvent[] }>('/api/calendar/events?' + params, { tenant: tenantSlug.value });
        resources.value = data.resources;
        events.value = data.events;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        eventsLoading.value = false;
        initialLoad.value = false;
    }
}

onMounted(async () => {
    await loadModules();
    await loadEvents();
});

watch([activeModule, date, viewMode, branchFilter], loadEvents);

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

// One open/id pair per module's own already-shipped detail dialog -- see
// this file's own imports. A single generic "detail dialog" component
// spanning 7 completely different entity shapes would need to reimplement
// every one of those dialogs' module-specific actions (reschedule/cancel/
// status/payment/etc.) as a big switch anyway, so reusing each module's
// real, already-tested dialog as-is is both less code and safer than a new
// abstraction.
const detailOpen = ref<Record<string, boolean>>({});
const detailId = ref<number | null>(null);

function openDetail(event: CalendarEvent): void {
    detailId.value = event.entity_id;
    // Replacing the whole object (not just setting one key) closes whichever
    // dialog might already be open before opening the new one.
    detailOpen.value = { [event.module]: true };
}

// Clicking the toolbar button opens a blank create dialog; clicking an empty
// cell in CalendarDayGrid (booking/table/course modules only -- see
// HOUR_GRID_MODULES) carries which resource/time was clicked so the dialog
// can pre-select it, same as NewBookingDialog already does for its own
// employee-column click-to-book flow.
const createResourceId = ref<number | string | null>(null);
const createIso = ref<string | null>(null);

function openCreate(): void {
    createResourceId.value = null;
    createIso.value = null;
    createOpen.value = true;
}

function openCreateFromSlot(payload: { resourceId: number | string; iso: string }): void {
    createResourceId.value = payload.resourceId;
    createIso.value = payload.iso;
    createOpen.value = true;
}
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('calendar.title') }}</h2>
            <p class="mt-1 text-sm ui-subtle">{{ locale.t('calendar.subtitle') }}</p>
        </div>

        <Skeleton v-if="modulesLoading" class="h-10 w-full rounded-xl" />
        <p v-else-if="! modules.length" class="text-sm ui-subtle">{{ locale.t('calendar.noModules') }}</p>

        <template v-else>
            <div class="flex flex-wrap items-center gap-3">
                <Select v-model="activeModule">
                    <SelectTrigger class="w-64">
                        <span class="flex items-center gap-2 truncate">
                            <span class="h-2 w-2 shrink-0 rounded-full" :class="activeModule ? MODULE_ACCENTS[activeModule] : ''" />
                            <component :is="activeModule ? MODULE_ICONS[activeModule] : undefined" class="h-4 w-4 shrink-0 ui-subtle" />
                            <SelectValue />
                        </span>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="m in modules" :key="m.key" :value="m.key">
                            <span class="flex items-center gap-2">
                                <span class="h-2 w-2 shrink-0 rounded-full" :class="MODULE_ACCENTS[m.key]" />
                                <component :is="MODULE_ICONS[m.key]" class="h-4 w-4 shrink-0" />
                                {{ m.label }}
                            </span>
                        </SelectItem>
                    </SelectContent>
                </Select>
                <div class="flex items-center gap-1">
                    <Button variant="outline" size="icon" @click="shiftDate(-1)"><ChevronLeft class="h-4 w-4" /></Button>
                    <DatePicker v-model="date" class="w-40" />
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
                <Button size="sm" :disabled="! activeModule" @click="openCreate">
                    <Plus class="h-4 w-4" />{{ locale.t('calendar.newRecord') }}
                </Button>
                <div class="ml-auto flex items-center gap-1 rounded-lg border border-border p-0.5">
                    <Button :variant="viewMode === 'day' ? 'secondary' : 'ghost'" size="sm" @click="viewMode = 'day'">{{ locale.t('booking.viewDay') }}</Button>
                    <Button :variant="viewMode === 'week' ? 'secondary' : 'ghost'" size="sm" @click="viewMode = 'week'">{{ locale.t('booking.viewWeek') }}</Button>
                    <Button :variant="viewMode === 'month' ? 'secondary' : 'ghost'" size="sm" @click="viewMode = 'month'">{{ locale.t('booking.viewMonth') }}</Button>
                </div>
            </div>

            <Skeleton v-if="eventsLoading && initialLoad" class="h-96 rounded-xl" />
            <Transition v-else name="calendar-fade" mode="out-in">
                <div :key="viewMode" class="transition-opacity duration-200" :class="{ 'opacity-50': eventsLoading }">
                    <template v-if="viewMode === 'day'">
                        <CalendarDayGrid
                            v-if="useDayGrid"
                            :date="date"
                            :resources="resources"
                            :events="events"
                            @open="openDetail"
                            @create="openCreateFromSlot"
                        />
                        <CalendarDayAgenda v-else :date="date" :events="events" :resources="resources" @open="openDetail" />
                    </template>
                    <CalendarWeekView
                        v-else-if="viewMode === 'week'"
                        :week-start="weekStart"
                        :events="events"
                        :resources="resources"
                        @select-day="selectDay"
                        @open="openDetail"
                    />
                    <CalendarMonthView v-else :month="monthKey" :events="events" @select-day="selectDay" />
                </div>
            </Transition>
        </template>

        <BookingDetailDialog v-model:open="detailOpen.booking_calendar" :booking-id="detailId" :tenant-slug="tenantSlug" @changed="loadEvents" />
        <TableReservationDetailDialog v-model:open="detailOpen.table_reservations" :reservation-id="detailId" :tenant-slug="tenantSlug" @changed="loadEvents" />
        <RoomReservationDetailDialog v-model:open="detailOpen.room_booking" :reservation-id="detailId" :tenant-slug="tenantSlug" @changed="loadEvents" />
        <CourseGroupDetailDialog v-model:open="detailOpen.course_scheduling" :group-id="detailId" :company-id="companyId as number" :tenant-slug="tenantSlug" @changed="loadEvents" />
        <TourDepartureDetailDialog v-model:open="detailOpen.tour_bookings" :departure-id="detailId" :company-id="companyId as number" :tenant-slug="tenantSlug" @changed="loadEvents" />
        <RepairOrderDetailDialog v-model:open="detailOpen.vehicle_service" :repair-order-id="detailId" :tenant-slug="tenantSlug" @changed="loadEvents" />
        <ShipmentDetailDialog v-model:open="detailOpen.shipment_tracking" :shipment-id="detailId" :tenant-slug="tenantSlug" @changed="loadEvents" />

        <NewBookingDialog
            v-if="activeModule === 'booking_calendar'"
            v-model:open="createOpen"
            :company-id="companyId as number"
            :tenant-slug="tenantSlug"
            :services="services"
            :employees="employees"
            :customers="(customers as unknown as Array<{ id: number; name: string; phone: string | null }>)"
            :initial-date="date"
            :initial-employee-id="(createResourceId as number | null)"
            :initial-iso="createIso"
            @created="loadEvents"
        />
        <NewTableReservationDialog
            v-else-if="activeModule === 'table_reservations'"
            v-model:open="createOpen"
            :company-id="companyId as number"
            :tenant-slug="tenantSlug"
            :customers="(customers as unknown as Array<{ id: number; name: string; phone: string | null }>)"
            :initial-date="date"
            :initial-iso="createIso"
            @created="loadEvents"
        />
        <NewRoomReservationDialog
            v-else-if="activeModule === 'room_booking'"
            v-model:open="createOpen"
            :company-id="companyId as number"
            :tenant-slug="tenantSlug"
            :customers="(customers as unknown as Array<{ id: number; name: string; phone: string | null }>)"
            @created="loadEvents"
        />
        <CourseGroupFormDialog v-else-if="activeModule === 'course_scheduling'" v-model:open="createOpen" :group="null" :company-id="companyId as number" :tenant-slug="tenantSlug" @saved="loadEvents" />
        <TourDepartureFormDialog v-else-if="activeModule === 'tour_bookings'" v-model:open="createOpen" :departure="null" :company-id="companyId as number" :tenant-slug="tenantSlug" @saved="loadEvents" />
        <NewRepairOrderDialog v-else-if="activeModule === 'vehicle_service'" v-model:open="createOpen" :company-id="companyId as number" :tenant-slug="tenantSlug" @created="loadEvents" />
        <NewShipmentDialog v-else-if="activeModule === 'shipment_tracking'" v-model:open="createOpen" :company-id="companyId as number" :tenant-slug="tenantSlug" @created="loadEvents" />
    </section>
</template>

<style scoped>
.calendar-fade-enter-active,
.calendar-fade-leave-active {
    transition: opacity 0.18s ease;
}
.calendar-fade-enter-from,
.calendar-fade-leave-to {
    opacity: 0;
}
</style>
