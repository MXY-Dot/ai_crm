<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPaymentProof;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Booking\AvailabilityCalculator;
use App\Support\Booking\BookingConflictException;
use App\Support\Booking\BookingService;
use App\Support\Payments\AlifPayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Booking::class);

        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'employee_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        // ТЗ раздел 21 -- a specialist's employee_id filter is enforced server-side,
        // not just left to whatever the client happened to request (see
        // BookingPolicy::view()'s matching row-level check for the /show endpoint).
        $employeeFilter = $user->role === User::ROLE_SPECIALIST ? $user->employee_id : ($data['employee_id'] ?? null);

        $bookings = Booking::query()
            ->with(['customer:id,name,phone', 'service:id,name,duration_minutes,price', 'employee:id,name', 'resource:id,name'])
            ->when($employeeFilter, fn ($q) => $q->where('employee_id', $employeeFilter))
            ->when($data['branch_id'] ?? null, fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->when($user->role === User::ROLE_SPECIALIST && ! $employeeFilter, fn ($q) => $q->whereRaw('1 = 0'))
            ->where('starts_at', '<', Carbon::parse($data['date_to']))
            ->where('ends_at', '>', Carbon::parse($data['date_from']))
            ->orderBy('starts_at')
            ->get();

        return response()->json($bookings);
    }

    public function show(Booking $booking): JsonResponse
    {
        Gate::authorize('view', $booking);

        return response()->json($booking->load([
            'customer:id,name,phone,email',
            'service:id,name,duration_minutes,price,prepayment_type,prepayment_value',
            'employee:id,name',
            'resource:id,name',
            'createdBy:id,name',
            'statusHistory.changedBy:id,name',
            'paymentProofs.reviewedBy:id,name',
        ]));
    }

    public function availability(Request $request, AvailabilityCalculator $calculator): JsonResponse
    {
        Gate::authorize('viewAny', Booking::class);

        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date'],
        ]);

        $service = Service::query()->findOrFail($data['service_id']);
        $company = Company::withoutGlobalScopes()->find($service->company_id);

        $slots = $calculator->slotsForDay($service, Carbon::parse($data['date']), $data['employee_id'] ?? null, $company->timezone ?: config('app.timezone'));

        return response()->json(['slots' => $slots]);
    }

    public function store(Request $request, BookingService $bookingService, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', Booking::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'starts_at' => ['required', 'date'],
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
                'source' => 'booking',
            ]);

            $customerId = $customer->id;
        }

        try {
            $booking = $bookingService->create([...$data, 'customer_id' => $customerId], $request->user());
        } catch (BookingConflictException $e) {
            throw ValidationException::withMessages(['starts_at' => $e->getMessage()]);
        }

        $audit->record('booking.created', $booking, $booking->toArray(), [], $request);

        return response()->json($booking->load(['customer:id,name,phone', 'service:id,name', 'employee:id,name']), 201);
    }

    public function reschedule(Request $request, Booking $booking, BookingService $bookingService, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $booking);

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'client_initiated' => ['nullable', 'boolean'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $booking->toArray();

        try {
            $booking = $bookingService->reschedule($booking, Carbon::parse($data['starts_at']), $request->user(), (bool) ($data['client_initiated'] ?? false), $data['comment'] ?? null);
        } catch (BookingConflictException $e) {
            throw ValidationException::withMessages(['starts_at' => $e->getMessage()]);
        }

        $audit->record('booking.rescheduled', $booking, $booking->toArray(), $before, $request);

        return response()->json($booking);
    }

    public function cancel(Request $request, Booking $booking, BookingService $bookingService, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $booking);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'client_initiated' => ['nullable', 'boolean'],
        ]);

        $before = $booking->toArray();

        try {
            $booking = $bookingService->cancel($booking, $request->user(), $data['reason'], (bool) ($data['client_initiated'] ?? false));
        } catch (BookingConflictException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        $audit->record('booking.cancelled', $booking, $booking->toArray(), $before, $request);

        return response()->json($booking);
    }

    public function updateStatus(Request $request, Booking $booking, BookingService $bookingService, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $booking);

        $data = $request->validate([
            'status' => ['required', Rule::in(Booking::STATUSES)],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $booking->toArray();
        $booking = $bookingService->updateStatus($booking, $data['status'], $request->user(), $data['comment'] ?? null);
        $audit->record('booking.status_changed', $booking, $booking->toArray(), $before, $request);

        return response()->json($booking);
    }

    public function storePaymentProof(Request $request, Booking $booking, BookingService $bookingService): JsonResponse
    {
        Gate::authorize('update', $booking);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:8192'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'operation_number' => ['nullable', 'string', 'max:80'],
        ]);

        $path = $data['file']->store('payment-proofs/'.$booking->tenant_id, 'public');

        $proof = $bookingService->storePaymentProof($booking, $path, $data['amount'] ?? null, $data['operation_number'] ?? null, $request->user());

        return response()->json($proof, 201);
    }

    /** See AlifPayClient's docblock -- creates a real invoice call, but nothing here has been tested against a real Alif endpoint yet. */
    public function initiateGatewayPayment(Request $request, Booking $booking, BookingService $bookingService, AlifPayClient $alif, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('managePayments', $booking);

        $data = $request->validate(['gateway' => ['required', Rule::in(['alif'])]]);

        $client = match ($data['gateway']) {
            'alif' => $alif,
        };

        try {
            $payment = $bookingService->initiateGatewayPayment($booking, $data['gateway'], $client, $request->user());
        } catch (BookingConflictException $e) {
            throw ValidationException::withMessages(['booking' => $e->getMessage()]);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['gateway' => 'Не удалось создать счёт на оплату: '.$e->getMessage()]);
        }

        $audit->record('booking.gateway_payment_initiated', $booking, ['gateway' => $data['gateway'], 'payment_id' => $payment->id], [], $request);

        return response()->json($payment, 201);
    }

    public function reviewPaymentProof(Request $request, Booking $booking, BookingPaymentProof $paymentProof, BookingService $bookingService, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('managePayments', $booking);
        abort_unless($paymentProof->booking_id === $booking->id, 404);

        $data = $request->validate([
            'decision' => ['required', Rule::in(BookingService::PROOF_DECISIONS)],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $booking->toArray();

        try {
            $booking = $bookingService->reviewPaymentProof($paymentProof, $data['decision'], $request->user(), $data['comment'] ?? null);
        } catch (BookingConflictException $e) {
            throw ValidationException::withMessages(['operation_number' => $e->getMessage()]);
        }

        $audit->record('booking.payment_proof_reviewed', $booking, $booking->toArray(), $before, $request);

        return response()->json($booking->load('paymentProofs.reviewedBy:id,name'));
    }

    /** ТЗ раздел 16 -- "отметить оплату наличными". */
    public function markCashPaid(Request $request, Booking $booking, BookingService $bookingService, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('managePayments', $booking);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);
        $before = $booking->toArray();

        try {
            $booking = $bookingService->markPaidCash($booking, $request->user(), $data['comment'] ?? null);
        } catch (BookingConflictException $e) {
            throw ValidationException::withMessages(['booking' => $e->getMessage()]);
        }

        $audit->record('booking.marked_cash_paid', $booking, $booking->toArray(), $before, $request);

        return response()->json($booking);
    }

    /** ТЗ раздел 19 -- запрос и обработка возврата предоплаты. */
    public function refund(Request $request, Booking $booking, BookingService $bookingService, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('managePayments', $booking);

        $data = $request->validate([
            'action' => ['required', Rule::in(['request', 'processing', 'refunded', 'rejected'])],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $booking->toArray();

        try {
            if ($data['action'] === 'request') {
                $booking = $bookingService->requestRefund($booking, $request->user(), $data['comment'] ?? null);
            } else {
                $status = ['processing' => 'refund_processing', 'refunded' => 'refunded', 'rejected' => 'refund_rejected'][$data['action']];
                $booking = $bookingService->updateRefundStatus($booking, $status, $request->user(), $data['comment'] ?? null);
            }
        } catch (BookingConflictException $e) {
            throw ValidationException::withMessages(['booking' => $e->getMessage()]);
        }

        $audit->record('booking.refund_'.$data['action'], $booking, $booking->toArray(), $before, $request);

        return response()->json($booking);
    }
}
