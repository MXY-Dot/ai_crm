<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\TableReservation;
use App\Support\Audit\AuditLogger;
use App\Support\Restaurant\TableAvailabilityCalculator;
use App\Support\Restaurant\TableReservationConflictException;
use App\Support\Restaurant\TableReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TableReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', TableReservation::class);

        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'resource_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $reservations = TableReservation::query()
            ->with(['customer:id,name,phone', 'resource:id,name,capacity'])
            ->when($data['resource_id'] ?? null, fn ($q, $resourceId) => $q->where('resource_id', $resourceId))
            ->when($data['branch_id'] ?? null, fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->where('starts_at', '<', Carbon::parse($data['date_to']))
            ->where('ends_at', '>', Carbon::parse($data['date_from']))
            ->orderBy('starts_at')
            ->get();

        return response()->json($reservations);
    }

    public function show(TableReservation $tableReservation): JsonResponse
    {
        Gate::authorize('view', $tableReservation);

        return response()->json($tableReservation->load([
            'customer:id,name,phone,email',
            'resource:id,name,capacity',
            'createdBy:id,name',
            'statusHistory.changedBy:id,name',
            'orders:id,table_reservation_id,status,total',
        ]));
    }

    public function availability(Request $request, TableAvailabilityCalculator $calculator): JsonResponse
    {
        Gate::authorize('viewAny', TableReservation::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'party_size' => ['required', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer'],
            'date' => ['required', 'date'],
        ]);

        $company = Company::withoutGlobalScopes()->findOrFail($data['company_id']);

        $slots = $calculator->slotsForDay($company, Carbon::parse($data['date']), $data['party_size'], $data['branch_id'] ?? null, $company->timezone ?: config('app.timezone'));

        return response()->json(['slots' => $slots]);
    }

    public function store(Request $request, TableReservationService $reservations, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', TableReservation::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'resource_id' => ['required', 'integer', 'exists:resources,id'],
            'party_size' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:15'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:180'],
            'customer_phone' => ['required_without:customer_id', 'nullable', 'string', 'max:40'],
        ]);

        $customerId = $data['customer_id'] ?? null;
        if (! $customerId) {
            $customer = Customer::query()
                ->where('company_id', $data['company_id'])
                ->where('phone', $data['customer_phone'])
                ->first();

            $customer ??= Customer::create([
                'company_id' => $data['company_id'],
                'name' => $data['customer_name'],
                'phone' => $data['customer_phone'],
                'source' => 'table_reservation',
            ]);

            $customerId = $customer->id;
        }

        try {
            $reservation = $reservations->create([...$data, 'customer_id' => $customerId], $request->user());
        } catch (TableReservationConflictException $e) {
            throw ValidationException::withMessages(['starts_at' => $e->getMessage()]);
        }

        $audit->record('table_reservation.created', $reservation, $reservation->toArray(), [], $request);

        return response()->json($reservation->load(['customer:id,name,phone', 'resource:id,name']), 201);
    }

    public function reschedule(Request $request, TableReservation $tableReservation, TableReservationService $reservations, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $tableReservation);

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $tableReservation->toArray();

        try {
            $tableReservation = $reservations->reschedule($tableReservation, Carbon::parse($data['starts_at']), $request->user(), $data['comment'] ?? null);
        } catch (TableReservationConflictException $e) {
            throw ValidationException::withMessages(['starts_at' => $e->getMessage()]);
        }

        $audit->record('table_reservation.rescheduled', $tableReservation, $tableReservation->toArray(), $before, $request);

        return response()->json($tableReservation);
    }

    public function cancel(Request $request, TableReservation $tableReservation, TableReservationService $reservations, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $tableReservation);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $before = $tableReservation->toArray();

        try {
            $tableReservation = $reservations->cancel($tableReservation, $request->user(), $data['reason']);
        } catch (TableReservationConflictException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        $audit->record('table_reservation.cancelled', $tableReservation, $tableReservation->toArray(), $before, $request);

        return response()->json($tableReservation);
    }

    public function updateStatus(Request $request, TableReservation $tableReservation, TableReservationService $reservations, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $tableReservation);

        $data = $request->validate([
            'status' => ['required', Rule::in(TableReservation::STATUSES)],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $tableReservation->toArray();
        $tableReservation = $reservations->updateStatus($tableReservation, $data['status'], $request->user(), $data['comment'] ?? null);
        $audit->record('table_reservation.status_changed', $tableReservation, $tableReservation->toArray(), $before, $request);

        return response()->json($tableReservation);
    }
}
