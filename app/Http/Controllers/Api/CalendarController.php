<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\CourseGroup;
use App\Models\Employee;
use App\Models\RepairOrder;
use App\Models\Resource;
use App\Models\RoomReservation;
use App\Models\Shipment;
use App\Models\TableReservation;
use App\Models\Tenant;
use App\Models\Tour;
use App\Models\TourDeparture;
use App\Models\User;
use App\Support\Business\ModuleRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Unified calendar feed across every reservation-shaped business module (ТЗ
 * раздел 9) — one shared calendar page (day/week/month + module switcher,
 * see /calendar) reads from this single endpoint instead of every module
 * needing its own bespoke calendar UI, so a new module's data becomes
 * visible on the calendar for free once mapped here. Every branch below
 * returns the SAME normalized shape ({resources, events}) regardless of how
 * different the underlying model's own date semantics are:
 *   - Booking/TableReservation: a real single time slot.
 *   - RoomReservation: a multi-night stay (no time-of-day component).
 *   - CourseGroup: a recurring WEEKLY pattern, projected here into real
 *     dated occurrences within the requested range — there is no per-
 *     occurrence "Lesson" row anywhere in the schema (see CourseGroup's own
 *     docblock), so this projection is the only place individual class
 *     dates ever exist, computed fresh on every request, never stored.
 *   - TourDeparture: a date-only (no time-of-day) departure/return range.
 *   - RepairOrder/Shipment: neither has a real scheduled slot or a resource
 *     that competes for time — each becomes a single all-day "due" marker
 *     anchored on `promised_at`/`estimated_delivery_at` (falling back to
 *     `created_at` when unset), with no resource column. Forcing either
 *     into an hour-grid would invent scheduling precision that doesn't
 *     exist in the underlying data — an all-day marker is the honest
 *     representation, not a compromise.
 * Each branch reuses the exact same Policy `viewAny` gate and (where one
 * exists) the same specialist row-restriction its own module's list
 * controller already enforces — this is a read surface, not a new
 * authorization model.
 */
class CalendarController extends Controller
{
    public const MODULES = [
        'booking_calendar', 'table_reservations', 'room_booking',
        'course_scheduling', 'tour_bookings', 'vehicle_service', 'shipment_tracking',
    ];

    public function events(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module' => ['required', 'string', Rule::in(self::MODULES)],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $from = Carbon::parse($data['date_from']);
        $to = Carbon::parse($data['date_to']);
        $branchId = $data['branch_id'] ?? null;

        $result = match ($data['module']) {
            'booking_calendar' => $this->bookingEvents($request, $from, $to, $branchId),
            'table_reservations' => $this->tableEvents($from, $to, $branchId),
            'room_booking' => $this->roomEvents($from, $to, $branchId),
            'course_scheduling' => $this->courseEvents($from, $to, $branchId),
            'tour_bookings' => $this->tourEvents($from, $to, $branchId),
            'vehicle_service' => $this->repairOrderEvents($from, $to, $branchId),
            'shipment_tracking' => $this->shipmentEvents($from, $to, $branchId),
        };

        return response()->json($result);
    }

    /** Which of MODULES the calling company actually has enabled — drives the frontend's module switcher so it never offers a tab with nothing behind it. Same tenant/company resolution as CompanyModuleController::index(). */
    public function modules(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        $company = Company::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $enabled = CompanyModule::query()
            ->where('company_id', $company->id)
            ->whereIn('module_key', self::MODULES)
            ->where('enabled', true)
            ->pluck('module_key')
            ->all();

        return response()->json([
            'modules' => collect(self::MODULES)
                ->filter(fn (string $key) => in_array($key, $enabled, true))
                ->map(fn (string $key) => ['key' => $key, 'label' => ModuleRegistry::MODULES[$key]])
                ->values(),
        ]);
    }

    private function bookingEvents(Request $request, Carbon $from, Carbon $to, ?int $branchId): array
    {
        Gate::authorize('viewAny', Booking::class);

        $user = $request->user();
        $employeeFilter = $user->role === User::ROLE_SPECIALIST ? $user->employee_id : null;

        $bookings = Booking::query()
            ->with(['customer:id,name', 'service:id,name'])
            ->when($employeeFilter, fn ($q) => $q->where('employee_id', $employeeFilter))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($user->role === User::ROLE_SPECIALIST && ! $employeeFilter, fn ($q) => $q->whereRaw('1 = 0'))
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->get();

        $resources = Employee::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($employeeFilter, fn ($q) => $q->where('id', $employeeFilter))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $events = $bookings->map(fn (Booking $b) => [
            'id' => 'booking-'.$b->id,
            'entity_id' => $b->id,
            'module' => 'booking_calendar',
            'resource_id' => $b->employee_id,
            'starts_at' => $b->starts_at->toIso8601String(),
            'ends_at' => $b->ends_at->toIso8601String(),
            'status' => $b->status,
            'title' => $b->customer?->name ?? '—',
            'subtitle' => $b->service?->name ?? '',
            'created_at' => $b->created_at->toIso8601String(),
        ])->values();

        return ['resources' => $resources, 'events' => $events];
    }

    private function tableEvents(Carbon $from, Carbon $to, ?int $branchId): array
    {
        Gate::authorize('viewAny', TableReservation::class);

        $reservations = TableReservation::query()
            ->with(['customer:id,name', 'resource:id,name'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->get();

        $resources = Resource::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('type', 'table')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $events = $reservations->map(fn (TableReservation $r) => [
            'id' => 'table-'.$r->id,
            'entity_id' => $r->id,
            'module' => 'table_reservations',
            'resource_id' => $r->resource_id,
            'starts_at' => $r->starts_at->toIso8601String(),
            'ends_at' => $r->ends_at->toIso8601String(),
            'status' => $r->status,
            'title' => $r->customer?->name ?? '—',
            'subtitle' => 'Гостей: '.$r->party_size,
            'created_at' => $r->created_at->toIso8601String(),
        ])->values();

        return ['resources' => $resources, 'events' => $events];
    }

    private function roomEvents(Carbon $from, Carbon $to, ?int $branchId): array
    {
        Gate::authorize('viewAny', RoomReservation::class);

        $reservations = RoomReservation::query()
            ->with(['customer:id,name', 'resource:id,name'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->get();

        $resources = Resource::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('type', 'room')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $events = $reservations->map(fn (RoomReservation $r) => [
            'id' => 'room-'.$r->id,
            'entity_id' => $r->id,
            'module' => 'room_booking',
            'resource_id' => $r->resource_id,
            'starts_at' => $r->starts_at->toIso8601String(),
            'ends_at' => $r->ends_at->toIso8601String(),
            'status' => $r->status,
            'title' => $r->customer?->name ?? '—',
            'subtitle' => $r->nights().' ноч., гостей: '.$r->guests_count,
            'created_at' => $r->created_at->toIso8601String(),
        ])->values();

        return ['resources' => $resources, 'events' => $events];
    }

    /**
     * CourseGroup has no per-occurrence row anywhere — `schedule` is a
     * weekly {weekday, start_time, end_time} pattern (weekday 0=Monday,
     * same convention as EmployeeSchedule and CourseGroupFormDialog.vue's
     * own WEEKDAYS array). This walks every day in the requested range and
     * emits one synthetic event per group per matching weekday, bounded by
     * the group's own starts_on/ends_on and only for groups still holding a
     * real weekly slot (ACTIVE_STATUSES).
     */
    private function courseEvents(Carbon $from, Carbon $to, ?int $branchId): array
    {
        Gate::authorize('viewAny', CourseGroup::class);

        $groups = CourseGroup::query()
            ->with(['course:id,name', 'employee:id,name'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', CourseGroup::ACTIVE_STATUSES)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<', $to))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from))
            ->get();

        $resources = $groups->map(fn (CourseGroup $g) => ['id' => $g->id, 'name' => $g->name])->values();

        $events = collect();
        $rangeStart = $from->copy()->startOfDay();
        $rangeEnd = $to->copy()->startOfDay();

        foreach ($groups as $group) {
            $schedule = $group->schedule ?? [];
            $windowStart = $group->starts_on ? $group->starts_on->copy()->max($rangeStart) : $rangeStart;
            $windowEnd = $group->ends_on ? $group->ends_on->copy()->min($rangeEnd) : $rangeEnd;

            for ($day = $windowStart->copy(); $day->lte($windowEnd); $day->addDay()) {
                $weekday = $day->dayOfWeekIso - 1; // 0=Monday..6=Sunday

                foreach ($schedule as $slot) {
                    if ((int) ($slot['weekday'] ?? -1) !== $weekday) {
                        continue;
                    }

                    [$startHour, $startMinute] = array_pad(explode(':', $slot['start_time'] ?? '00:00'), 2, '00');
                    [$endHour, $endMinute] = array_pad(explode(':', $slot['end_time'] ?? '00:00'), 2, '00');

                    $starts = $day->copy()->setTime((int) $startHour, (int) $startMinute);
                    $ends = $day->copy()->setTime((int) $endHour, (int) $endMinute);

                    $events->push([
                        'id' => 'course-'.$group->id.'-'.$day->toDateString(),
                        'entity_id' => $group->id,
                        'module' => 'course_scheduling',
                        'resource_id' => $group->id,
                        'starts_at' => $starts->toIso8601String(),
                        'ends_at' => $ends->toIso8601String(),
                        'status' => $group->status,
                        'title' => $group->course?->name ?? $group->name,
                        'subtitle' => $group->employee?->name ?? '',
                        'created_at' => $group->created_at->toIso8601String(),
                    ]);
                }
            }
        }

        return ['resources' => $resources, 'events' => $events->values()];
    }

    private function tourEvents(Carbon $from, Carbon $to, ?int $branchId): array
    {
        Gate::authorize('viewAny', TourDeparture::class);

        $departures = TourDeparture::query()
            ->with(['tour:id,name'])
            ->withCount('bookings')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('departure_date', '<', $to)
            ->where('return_date', '>=', $from)
            ->orderBy('departure_date')
            ->get();

        $resources = Tour::query()
            ->when($branchId, fn ($q) => $q->whereHas('departures', fn ($d) => $d->where('branch_id', $branchId)))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $events = $departures->map(fn (TourDeparture $d) => [
            'id' => 'tour-'.$d->id,
            'entity_id' => $d->id,
            'module' => 'tour_bookings',
            'resource_id' => $d->tour_id,
            'starts_at' => $d->departure_date->startOfDay()->toIso8601String(),
            'ends_at' => $d->return_date->endOfDay()->toIso8601String(),
            'status' => $d->status,
            'title' => $d->tour?->name ?? '—',
            'subtitle' => 'Мест: '.$d->bookings_count.'/'.$d->capacity,
            'created_at' => $d->created_at->toIso8601String(),
        ])->values();

        return ['resources' => $resources, 'events' => $events];
    }

    /** No resource column -- see this class's own docblock for why a single all-day marker (promised_at, falling back to created_at) is the honest representation here. */
    private function repairOrderEvents(Carbon $from, Carbon $to, ?int $branchId): array
    {
        Gate::authorize('viewAny', RepairOrder::class);

        $orders = RepairOrder::query()
            ->with(['customer:id,name', 'vehicle:id,make,model,plate_number'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', RepairOrder::ACTIVE_STATUSES)
            ->get()
            ->filter(function (RepairOrder $o) use ($from, $to) {
                $anchor = $o->promised_at ?? $o->created_at;

                return $anchor->gte($from) && $anchor->lt($to);
            });

        $events = $orders->map(function (RepairOrder $o) {
            $anchor = $o->promised_at ?? $o->created_at;
            $vehicleLabel = $o->vehicle ? trim($o->vehicle->make.' '.$o->vehicle->model.' · '.$o->vehicle->plate_number) : '—';

            return [
                'id' => 'repair-'.$o->id,
                'entity_id' => $o->id,
                'module' => 'vehicle_service',
                'resource_id' => null,
                'starts_at' => $anchor->copy()->startOfDay()->toIso8601String(),
                'ends_at' => $anchor->copy()->endOfDay()->toIso8601String(),
                'status' => $o->status,
                'title' => $vehicleLabel,
                'subtitle' => $o->customer?->name ?? '',
                'created_at' => $o->created_at->toIso8601String(),
            ];
        })->values();

        return ['resources' => [], 'events' => $events];
    }

    /** Same reasoning as repairOrderEvents() — no resource column, single all-day marker on estimated_delivery_at (falling back to created_at). */
    private function shipmentEvents(Carbon $from, Carbon $to, ?int $branchId): array
    {
        Gate::authorize('viewAny', Shipment::class);

        $shipments = Shipment::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', Shipment::ACTIVE_STATUSES)
            ->get()
            ->filter(function (Shipment $s) use ($from, $to) {
                $anchor = $s->estimated_delivery_at ?? $s->created_at;

                return $anchor->gte($from) && $anchor->lt($to);
            });

        $events = $shipments->map(function (Shipment $s) {
            $anchor = $s->estimated_delivery_at ?? $s->created_at;

            return [
                'id' => 'shipment-'.$s->id,
                'entity_id' => $s->id,
                'module' => 'shipment_tracking',
                'resource_id' => null,
                'starts_at' => $anchor->copy()->startOfDay()->toIso8601String(),
                'ends_at' => $anchor->copy()->endOfDay()->toIso8601String(),
                'status' => $s->status,
                'title' => $s->tracking_number,
                'subtitle' => $s->recipient_name ?? '',
                'created_at' => $s->created_at->toIso8601String(),
            ];
        })->values();

        return ['resources' => [], 'events' => $events];
    }
}
