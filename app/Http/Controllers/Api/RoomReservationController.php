<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\RoomReservation;
use App\Models\RoomReservationPaymentProof;
use App\Support\Audit\AuditLogger;
use App\Support\Hotel\RoomAvailabilityCalculator;
use App\Support\Hotel\RoomReservationConflictException;
use App\Support\Hotel\RoomReservationService;
use App\Support\Payments\AlifPayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class RoomReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', RoomReservation::class);

        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'resource_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $reservations = RoomReservation::query()
            ->with(['customer:id,name,phone', 'resource:id,name,capacity'])
            ->when($data['resource_id'] ?? null, fn ($q, $resourceId) => $q->where('resource_id', $resourceId))
            ->when($data['branch_id'] ?? null, fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->where('starts_at', '<', Carbon::parse($data['date_to']))
            ->where('ends_at', '>', Carbon::parse($data['date_from']))
            ->orderBy('starts_at')
            ->get();

        return response()->json($reservations);
    }

    public function show(RoomReservation $roomReservation): JsonResponse
    {
        Gate::authorize('view', $roomReservation);

        return response()->json($roomReservation->load([
            'customer:id,name,phone,email',
            'resource:id,name,capacity',
            'createdBy:id,name',
            'statusHistory.changedBy:id,name',
            'paymentProofs.reviewedBy:id,name',
        ]));
    }

    public function availability(Request $request, RoomAvailabilityCalculator $calculator): JsonResponse
    {
        Gate::authorize('viewAny', RoomReservation::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'guests' => ['required', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
        ]);

        $company = Company::withoutGlobalScopes()->findOrFail($data['company_id']);

        $rooms = $calculator->availableRooms($company, Carbon::parse($data['check_in']), Carbon::parse($data['check_out']), $data['guests'], $data['branch_id'] ?? null);

        return response()->json(['rooms' => $rooms]);
    }

    public function store(Request $request, RoomReservationService $reservations, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', RoomReservation::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'resource_id' => ['required', 'integer', 'exists:resources,id'],
            'guests_count' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
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
                'source' => 'room_reservation',
            ]);

            $customerId = $customer->id;
        }

        try {
            $reservation = $reservations->create([...$data, 'customer_id' => $customerId], $request->user());
        } catch (RoomReservationConflictException $e) {
            throw ValidationException::withMessages(['starts_at' => $e->getMessage()]);
        }

        $audit->record('room_reservation.created', $reservation, $reservation->toArray(), [], $request);

        return response()->json($reservation->load(['customer:id,name,phone', 'resource:id,name']), 201);
    }

    public function reschedule(Request $request, RoomReservation $roomReservation, RoomReservationService $reservations, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $roomReservation);

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $roomReservation->toArray();

        try {
            $roomReservation = $reservations->reschedule($roomReservation, Carbon::parse($data['starts_at']), Carbon::parse($data['ends_at']), $request->user(), $data['comment'] ?? null);
        } catch (RoomReservationConflictException $e) {
            throw ValidationException::withMessages(['starts_at' => $e->getMessage()]);
        }

        $audit->record('room_reservation.rescheduled', $roomReservation, $roomReservation->toArray(), $before, $request);

        return response()->json($roomReservation);
    }

    public function cancel(Request $request, RoomReservation $roomReservation, RoomReservationService $reservations, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $roomReservation);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $before = $roomReservation->toArray();

        try {
            $roomReservation = $reservations->cancel($roomReservation, $request->user(), $data['reason']);
        } catch (RoomReservationConflictException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        $audit->record('room_reservation.cancelled', $roomReservation, $roomReservation->toArray(), $before, $request);

        return response()->json($roomReservation);
    }

    public function updateStatus(Request $request, RoomReservation $roomReservation, RoomReservationService $reservations, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $roomReservation);

        $data = $request->validate([
            'status' => ['required', Rule::in(RoomReservation::STATUSES)],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $roomReservation->toArray();
        $roomReservation = $reservations->updateStatus($roomReservation, $data['status'], $request->user(), $data['comment'] ?? null);
        $audit->record('room_reservation.status_changed', $roomReservation, $roomReservation->toArray(), $before, $request);

        return response()->json($roomReservation);
    }

    public function storePaymentProof(Request $request, RoomReservation $roomReservation, RoomReservationService $reservations): JsonResponse
    {
        Gate::authorize('update', $roomReservation);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:8192'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'operation_number' => ['nullable', 'string', 'max:80'],
        ]);

        $path = $data['file']->store('payment-proofs/'.$roomReservation->tenant_id, 'public');

        $proof = $reservations->storePaymentProof($roomReservation, $path, $data['amount'] ?? null, $data['operation_number'] ?? null, $request->user());

        return response()->json($proof, 201);
    }

    public function initiateGatewayPayment(Request $request, RoomReservation $roomReservation, RoomReservationService $reservations, AlifPayClient $alif, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('managePayments', $roomReservation);

        $data = $request->validate(['gateway' => ['required', Rule::in(['alif'])]]);

        $client = match ($data['gateway']) {
            'alif' => $alif,
        };

        try {
            $payment = $reservations->initiateGatewayPayment($roomReservation, $data['gateway'], $client, $request->user());
        } catch (RoomReservationConflictException $e) {
            throw ValidationException::withMessages(['booking' => $e->getMessage()]);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['gateway' => 'Не удалось создать счёт на оплату: '.$e->getMessage()]);
        }

        $audit->record('room_reservation.gateway_payment_initiated', $roomReservation, ['gateway' => $data['gateway'], 'payment_id' => $payment->id], [], $request);

        return response()->json($payment, 201);
    }

    public function reviewPaymentProof(Request $request, RoomReservation $roomReservation, RoomReservationPaymentProof $paymentProof, RoomReservationService $reservations, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('managePayments', $roomReservation);
        abort_unless($paymentProof->room_reservation_id === $roomReservation->id, 404);

        $data = $request->validate([
            'decision' => ['required', Rule::in(RoomReservationService::PROOF_DECISIONS)],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $roomReservation->toArray();

        try {
            $roomReservation = $reservations->reviewPaymentProof($paymentProof, $data['decision'], $request->user(), $data['comment'] ?? null);
        } catch (RoomReservationConflictException $e) {
            throw ValidationException::withMessages(['operation_number' => $e->getMessage()]);
        }

        $audit->record('room_reservation.payment_proof_reviewed', $roomReservation, $roomReservation->toArray(), $before, $request);

        return response()->json($roomReservation->load('paymentProofs.reviewedBy:id,name'));
    }

    public function markCashPaid(Request $request, RoomReservation $roomReservation, RoomReservationService $reservations, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('managePayments', $roomReservation);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);
        $before = $roomReservation->toArray();

        try {
            $roomReservation = $reservations->markPaidCash($roomReservation, $request->user(), $data['comment'] ?? null);
        } catch (RoomReservationConflictException $e) {
            throw ValidationException::withMessages(['booking' => $e->getMessage()]);
        }

        $audit->record('room_reservation.marked_cash_paid', $roomReservation, $roomReservation->toArray(), $before, $request);

        return response()->json($roomReservation);
    }

    public function refund(Request $request, RoomReservation $roomReservation, RoomReservationService $reservations, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('managePayments', $roomReservation);

        $data = $request->validate([
            'action' => ['required', Rule::in(['request', 'processing', 'refunded', 'rejected'])],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $roomReservation->toArray();

        try {
            if ($data['action'] === 'request') {
                $roomReservation = $reservations->requestRefund($roomReservation, $request->user(), $data['comment'] ?? null);
            } else {
                $status = ['processing' => 'refund_processing', 'refunded' => 'refunded', 'rejected' => 'refund_rejected'][$data['action']];
                $roomReservation = $reservations->updateRefundStatus($roomReservation, $status, $request->user(), $data['comment'] ?? null);
            }
        } catch (RoomReservationConflictException $e) {
            throw ValidationException::withMessages(['booking' => $e->getMessage()]);
        }

        $audit->record('room_reservation.refund_'.$data['action'], $roomReservation, $roomReservation->toArray(), $before, $request);

        return response()->json($roomReservation);
    }
}
