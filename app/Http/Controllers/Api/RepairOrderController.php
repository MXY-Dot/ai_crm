<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\RepairOrder;
use App\Support\AutoService\RepairOrderConflictException;
use App\Support\AutoService\RepairOrderService;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RepairOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', RepairOrder::class);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(RepairOrder::STATUSES)],
            'vehicle_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $repairOrders = RepairOrder::query()
            ->with(['customer:id,name,phone', 'vehicle:id,make,model,plate_number', 'employee:id,name'])
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['vehicle_id'] ?? null, fn ($q, $vehicleId) => $q->where('vehicle_id', $vehicleId))
            ->when($data['branch_id'] ?? null, fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->latest()
            ->paginate(50);

        return response()->json($repairOrders);
    }

    public function show(RepairOrder $repairOrder): JsonResponse
    {
        Gate::authorize('view', $repairOrder);

        return response()->json($repairOrder->load([
            'customer:id,name,phone,email',
            'vehicle:id,make,model,year,plate_number,vin,color',
            'employee:id,name',
            'createdBy:id,name',
            'statusHistory.changedBy:id,name',
            'orders:id,repair_order_id,status,payment_status,total',
        ]));
    }

    public function store(Request $request, RepairOrderService $repairOrders, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', RepairOrder::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'problem_description' => ['required', 'string', 'max:2000'],
            'estimated_total' => ['nullable', 'numeric', 'min:0'],
            'promised_at' => ['nullable', 'date'],
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
                'source' => 'auto_service',
            ]);

            $customerId = $customer->id;
        }

        try {
            $repairOrder = $repairOrders->create([...$data, 'customer_id' => $customerId], $request->user());
        } catch (RepairOrderConflictException $e) {
            throw ValidationException::withMessages(['vehicle_id' => $e->getMessage()]);
        }

        $audit->record('repair_order.created', $repairOrder, $repairOrder->toArray(), [], $request);

        return response()->json($repairOrder->load(['customer:id,name,phone', 'vehicle:id,make,model,plate_number']), 201);
    }

    public function updateStatus(Request $request, RepairOrder $repairOrder, RepairOrderService $repairOrders, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $repairOrder);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_diff(RepairOrder::STATUSES, [RepairOrder::STATUS_CANCELLED]))],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $repairOrder->toArray();

        try {
            $repairOrder = $repairOrders->updateStatus($repairOrder, $data['status'], $request->user(), $data['comment'] ?? null);
        } catch (RepairOrderConflictException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        $audit->record('repair_order.status_changed', $repairOrder, $repairOrder->toArray(), $before, $request);

        return response()->json($repairOrder);
    }

    public function updateDetails(Request $request, RepairOrder $repairOrder, RepairOrderService $repairOrders, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $repairOrder);

        $data = $request->validate([
            'diagnosis_notes' => ['nullable', 'string', 'max:2000'],
            'estimated_total' => ['nullable', 'numeric', 'min:0'],
            'promised_at' => ['nullable', 'date'],
        ]);

        $before = $repairOrder->toArray();
        $repairOrder = $repairOrders->updateDetails($repairOrder, $data, $request->user());
        $audit->record('repair_order.details_updated', $repairOrder, $repairOrder->toArray(), $before, $request);

        return response()->json($repairOrder);
    }

    public function cancel(Request $request, RepairOrder $repairOrder, RepairOrderService $repairOrders, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $repairOrder);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $before = $repairOrder->toArray();

        try {
            $repairOrder = $repairOrders->cancel($repairOrder, $request->user(), $data['reason']);
        } catch (RepairOrderConflictException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        $audit->record('repair_order.cancelled', $repairOrder, $repairOrder->toArray(), $before, $request);

        return response()->json($repairOrder);
    }
}
