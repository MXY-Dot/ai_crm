<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderPaymentProof;
use App\Models\OrderReturn;
use App\Support\Audit\AuditLogger;
use App\Support\Commerce\OrderException;
use App\Support\Commerce\OrderService;
use App\Support\Payments\AlifPayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Order::class);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(Order::STATUSES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'customer_id' => ['nullable', 'integer'],
        ]);

        $orders = Order::query()
            ->with(['customer:id,name,phone', 'items:id,order_id,product_name,quantity,unit_price,line_total'])
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['customer_id'] ?? null, fn ($q, $customerId) => $q->where('customer_id', $customerId))
            ->when($data['date_from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)))
            ->when($data['date_to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->latest('id')
            ->paginate(25);

        return response()->json($orders);
    }

    /** Returns queue -- defaults to just-requested returns (the review queue), or filter to any status. */
    public function indexReturns(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Order::class);

        $data = $request->validate(['status' => ['nullable', Rule::in(OrderReturn::STATUSES)]]);

        $returns = OrderReturn::query()
            ->with(['order:id,total', 'customer:id,name,phone'])
            ->where('status', $data['status'] ?? OrderReturn::STATUS_REQUESTED)
            ->latest('id')
            ->paginate(25);

        return response()->json($returns);
    }

    public function show(Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        return response()->json($order->load([
            'customer:id,name,phone,email',
            'items.product:id,name',
            'createdBy:id,name',
            'statusHistory.changedBy:id,name',
            'delivery',
            'returns.requestedBy:id,name',
            'returns.reviewedBy:id,name',
            'paymentProofs.reviewedBy:id,name',
        ]));
    }

    public function store(Request $request, OrderService $orders, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', Order::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:180'],
            'customer_phone' => ['required_without:customer_id', 'nullable', 'string', 'max:40'],
            'order_type' => ['nullable', Rule::in(Order::ORDER_TYPES)],
            'table_reservation_id' => ['nullable', 'integer', 'exists:table_reservations,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
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
                'source' => 'order',
            ]);

            $customerId = $customer->id;
        }

        try {
            $order = $orders->createOrder([...$data, 'customer_id' => $customerId], $request->user());
        } catch (OrderException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        $audit->record('order.created', $order, $order->toArray(), [], $request);

        return response()->json($order->load(['customer:id,name,phone', 'items']), 201);
    }

    public function updateStatus(Request $request, Order $order, OrderService $orders, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $order);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_diff(Order::STATUSES, [Order::STATUS_CANCELLED]))],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $order->toArray();

        try {
            $order = $orders->updateStatus($order, $data['status'], $request->user(), $data['comment'] ?? null);
        } catch (OrderException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        $audit->record('order.status_changed', $order, $order->toArray(), $before, $request);

        return response()->json($order);
    }

    public function cancel(Request $request, Order $order, OrderService $orders, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $order);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $before = $order->toArray();

        try {
            $order = $orders->cancelOrder($order, $request->user(), $data['reason']);
        } catch (OrderException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        $audit->record('order.cancelled', $order, $order->toArray(), $before, $request);

        return response()->json($order);
    }

    public function storeReturn(Request $request, Order $order, OrderService $orders, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $order);

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $return = $orders->requestReturn($order, $data['reason'], $request->user());

        $audit->record('order.return_requested', $order, $return->toArray(), [], $request);

        return response()->json($return, 201);
    }

    public function reviewReturn(Request $request, Order $order, OrderReturn $orderReturn, OrderService $orders, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageReturns', $order);
        abort_unless($orderReturn->order_id === $order->id, 404);

        $data = $request->validate([
            'decision' => ['required', Rule::in([OrderReturn::STATUS_APPROVED, OrderReturn::STATUS_REJECTED])],
            'comment' => ['nullable', 'string', 'max:500'],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $before = $orderReturn->toArray();

        try {
            $orderReturn = $orders->reviewReturn($orderReturn, $data['decision'], $request->user(), $data['comment'] ?? null, $data['refund_amount'] ?? null);
        } catch (OrderException $e) {
            throw ValidationException::withMessages(['decision' => $e->getMessage()]);
        }

        $audit->record('order.return_reviewed', $order, $orderReturn->toArray(), $before, $request);

        return response()->json($orderReturn);
    }

    public function markReturnRefunded(Request $request, Order $order, OrderReturn $orderReturn, OrderService $orders, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageReturns', $order);
        abort_unless($orderReturn->order_id === $order->id, 404);

        $before = $orderReturn->toArray();

        try {
            $orderReturn = $orders->markReturnRefunded($orderReturn);
        } catch (OrderException $e) {
            throw ValidationException::withMessages(['return' => $e->getMessage()]);
        }

        $audit->record('order.return_refunded', $order, $orderReturn->toArray(), $before, $request);

        return response()->json($orderReturn);
    }

    public function updateDelivery(Request $request, Order $order, OrderService $orders, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $order);

        $data = $request->validate([
            'method' => ['required', Rule::in(OrderDelivery::METHODS)],
            'address' => ['nullable', 'string', 'max:2000'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'carrier' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(OrderDelivery::STATUSES)],
            'estimated_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($data['status'] === 'delivered') {
            $data['delivered_at'] = Carbon::now();
        }

        $delivery = $orders->updateDelivery($order, $data);

        $audit->record('order.delivery_updated', $order, $delivery->toArray(), [], $request);

        return response()->json($delivery);
    }

    public function storePaymentProof(Request $request, Order $order, OrderService $orders): JsonResponse
    {
        Gate::authorize('update', $order);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:8192'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'operation_number' => ['nullable', 'string', 'max:80'],
        ]);

        $path = $data['file']->store('payment-proofs/'.$order->tenant_id, 'public');

        $proof = $orders->storePaymentProof($order, $path, $data['amount'] ?? null, $data['operation_number'] ?? null, $request->user());

        return response()->json($proof, 201);
    }

    public function reviewPaymentProof(Request $request, Order $order, OrderPaymentProof $paymentProof, OrderService $orders, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('managePayments', $order);
        abort_unless($paymentProof->order_id === $order->id, 404);

        $data = $request->validate([
            'decision' => ['required', Rule::in(OrderService::PROOF_DECISIONS)],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $order->toArray();

        try {
            $order = $orders->reviewPaymentProof($paymentProof, $data['decision'], $request->user(), $data['comment'] ?? null);
        } catch (OrderException $e) {
            throw ValidationException::withMessages(['operation_number' => $e->getMessage()]);
        }

        $audit->record('order.payment_proof_reviewed', $order, $order->toArray(), $before, $request);

        return response()->json($order->load('paymentProofs.reviewedBy:id,name'));
    }

    /** See AlifPayClient's docblock -- creates a real invoice call, but nothing here has been tested against a real Alif endpoint yet. */
    public function initiateGatewayPayment(Request $request, Order $order, OrderService $orders, AlifPayClient $alif, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('managePayments', $order);

        $data = $request->validate(['gateway' => ['required', Rule::in(['alif'])]]);

        $client = match ($data['gateway']) {
            'alif' => $alif,
        };

        try {
            $payment = $orders->initiateGatewayPayment($order, $data['gateway'], $client, $request->user());
        } catch (OrderException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['gateway' => 'Не удалось создать счёт на оплату: '.$e->getMessage()]);
        }

        $audit->record('order.gateway_payment_initiated', $order, ['gateway' => $data['gateway'], 'payment_id' => $payment->id], [], $request);

        return response()->json($payment, 201);
    }

    public function markCashPaid(Request $request, Order $order, OrderService $orders, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('managePayments', $order);

        $before = $order->toArray();

        try {
            $order = $orders->markPaidCash($order, $request->user());
        } catch (OrderException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        $audit->record('order.marked_cash_paid', $order, $order->toArray(), $before, $request);

        return response()->json($order);
    }

    public function refundPayment(Request $request, Order $order, OrderService $orders, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('managePayments', $order);

        $data = $request->validate([
            'action' => ['required', Rule::in(['request', 'processing', 'refunded', 'rejected'])],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $order->toArray();

        try {
            if ($data['action'] === 'request') {
                $order = $orders->requestRefund($order, $request->user(), $data['comment'] ?? null);
            } else {
                $status = ['processing' => 'refund_processing', 'refunded' => 'refunded', 'rejected' => 'refund_rejected'][$data['action']];
                $order = $orders->updateRefundStatus($order, $status, $request->user(), $data['comment'] ?? null);
            }
        } catch (OrderException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        $audit->record('order.payment_refund_updated', $order, $order->toArray(), $before, $request);

        return response()->json($order);
    }
}
