<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Shipment;
use App\Support\Audit\AuditLogger;
use App\Support\Logistics\ShipmentException;
use App\Support\Logistics\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShipmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Shipment::class);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(Shipment::STATUSES)],
            'search' => ['nullable', 'string', 'max:80'],
        ]);

        $shipments = Shipment::query()
            ->with('customer:id,name,phone')
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['search'] ?? null, fn ($q, $search) => $q->where(function ($q2) use ($search) {
                $q2->where('tracking_number', 'like', "%{$search}%")
                    ->orWhere('sender_name', 'like', "%{$search}%")
                    ->orWhere('recipient_name', 'like', "%{$search}%");
            }))
            ->latest()
            ->paginate(50);

        return response()->json($shipments);
    }

    public function show(Shipment $shipment): JsonResponse
    {
        Gate::authorize('view', $shipment);

        return response()->json($shipment->load(['customer:id,name,phone', 'createdBy:id,name', 'trackingEvents.changedBy:id,name']));
    }

    public function store(Request $request, ShipmentService $shipments, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', Shipment::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'sender_name' => ['required', 'string', 'max:180'],
            'sender_phone' => ['required', 'string', 'max:40'],
            'recipient_name' => ['required', 'string', 'max:180'],
            'recipient_phone' => ['required', 'string', 'max:40'],
            'origin_address' => ['nullable', 'string', 'max:255'],
            'destination_address' => ['nullable', 'string', 'max:255'],
            'service_type' => ['nullable', Rule::in(Shipment::SERVICE_TYPES)],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'estimated_delivery_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! empty($data['customer_id'])) {
            Customer::query()->where('company_id', $data['company_id'])->findOrFail($data['customer_id']);
        }

        $shipment = $shipments->create($data, $request->user());
        $audit->record('shipment.created', $shipment, $shipment->toArray(), [], $request);

        return response()->json($shipment, 201);
    }

    public function updateStatus(Request $request, Shipment $shipment, ShipmentService $shipments, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $shipment);

        $data = $request->validate([
            'status' => ['required', Rule::in(Shipment::STATUSES)],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $shipment->toArray();

        try {
            $shipment = $shipments->updateStatus($shipment, $data['status'], $request->user(), $data['location'] ?? null, $data['description'] ?? null);
        } catch (ShipmentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        $audit->record('shipment.status_changed', $shipment, $shipment->toArray(), $before, $request);

        return response()->json($shipment);
    }
}
