<?php

namespace App\Support\Commerce;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderGatewayPayment;
use App\Models\OrderItem;
use App\Models\OrderPaymentProof;
use App\Models\OrderReturn;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use App\Support\Payments\PaymentGatewayClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Каталог + заказы + возвраты + доставка -- write-service mirroring
 * App\Support\Booking\BookingService's own discipline (single write path per
 * concept, DB::transaction() per write, row locks against concurrent writes,
 * every status change logged, $actor nullable for non-interactive callers).
 */
class OrderService
{
    /**
     * $actor is null only for non-interactive writes (mirrors BookingService's
     * own convention) -- $data must then carry an explicit 'tenant_id'.
     * Every line item's price is resolved from the real, current Product::price
     * here, never trusted from the caller -- same "never let a write depend on
     * a value the customer could have sent" discipline as the rest of this app.
     *
     * @param array{tenant_id?:int, company_id:int, branch_id?:?int, customer_id:int, items: array<int, array{product_id:int, quantity:int}>, notes?:?string, delivery_fee?:float, discount_amount?:float, placed_via?:string} $data
     */
    public function createOrder(array $data, ?User $actor): Order
    {
        if ($data['items'] === []) {
            throw new OrderException('В заказе нет ни одной позиции.');
        }

        return DB::transaction(function () use ($data, $actor) {
            $lineItems = [];
            $subtotal = 0.0;

            foreach ($data['items'] as $line) {
                // Row-locked so two concurrent orders for the same low-stock product can
                // never both succeed -- same double-write-prevention discipline as
                // BookingService::assertNoConflict()'s employee/resource row locks.
                $product = Product::query()->whereKey($line['product_id'])->lockForUpdate()->firstOrFail();
                $quantity = (int) $line['quantity'];

                if ($quantity < 1) {
                    throw new OrderException("Некорректное количество для «{$product->name}».");
                }

                if ($product->track_stock && $product->stock_quantity !== null && $product->stock_quantity < $quantity) {
                    throw new OrderException("Недостаточно «{$product->name}» на складе (в наличии: {$product->stock_quantity}).");
                }

                $lineTotal = round($product->price * $quantity, 2);
                $subtotal += $lineTotal;

                $lineItems[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'line_total' => $lineTotal,
                ];

                if ($product->track_stock && $product->stock_quantity !== null) {
                    $product->decrement('stock_quantity', $quantity);
                }
            }

            $deliveryFee = round((float) ($data['delivery_fee'] ?? 0), 2);
            $discount = round((float) ($data['discount_amount'] ?? 0), 2);
            $total = max(0, round($subtotal + $deliveryFee - $discount, 2));
            $tenantId = $actor?->tenant_id ?? $data['tenant_id'] ?? null;

            $order = Order::create([
                'tenant_id' => $tenantId,
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'status' => Order::STATUS_PENDING,
                'subtotal' => round($subtotal, 2),
                'delivery_fee' => $deliveryFee,
                'discount_amount' => $discount,
                'total' => $total,
                'payment_status' => 'unpaid',
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor?->id,
                'placed_via' => $data['placed_via'] ?? 'manual',
                'order_type' => $data['order_type'] ?? 'delivery',
                'table_reservation_id' => $data['table_reservation_id'] ?? null,
            ]);

            foreach ($lineItems as $item) {
                OrderItem::create(['order_id' => $order->id, ...$item]);
            }

            $this->logStatus($order, null, Order::STATUS_PENDING, $actor, $actor ? 'Заказ создан' : 'Заказ создан автоматически');

            return $order;
        });
    }

    /** Never accepts 'cancelled' as a target here -- cancelOrder() is the only path that cancels, since it also restocks and requires a reason. */
    public function updateStatus(Order $order, string $newStatus, ?User $actor, ?string $comment = null): Order
    {
        if ($newStatus === Order::STATUS_CANCELLED) {
            throw new OrderException('Для отмены заказа используйте отдельное действие «Отменить».');
        }

        return DB::transaction(function () use ($order, $newStatus, $actor, $comment) {
            if (! in_array($order->status, Order::ACTIVE_STATUSES, true)) {
                throw new OrderException('Этот заказ уже завершён или отменён.');
            }

            $oldStatus = $order->status;
            $order->update(['status' => $newStatus]);
            $this->logStatus($order, $oldStatus, $newStatus, $actor, $comment);

            return $order->fresh();
        });
    }

    public function cancelOrder(Order $order, ?User $actor, string $reason): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            if (! in_array($order->status, Order::ACTIVE_STATUSES, true)) {
                throw new OrderException('Этот заказ уже завершён или отменён.');
            }

            $oldStatus = $order->status;

            // Every reserved unit is still genuinely unsold at this point (an order can
            // only reach here while still active), so restocking is always correct.
            foreach ($order->items as $item) {
                if (! $item->product_id) {
                    continue;
                }

                $product = Product::query()->whereKey($item->product_id)->lockForUpdate()->first();
                if ($product && $product->track_stock && $product->stock_quantity !== null) {
                    $product->increment('stock_quantity', $item->quantity);
                }
            }

            $order->update(['status' => Order::STATUS_CANCELLED, 'cancelled_reason' => $reason]);
            $this->logStatus($order, $oldStatus, Order::STATUS_CANCELLED, $actor, $reason);

            return $order->fresh();
        });
    }

    public function requestReturn(Order $order, string $reason, ?User $actor): OrderReturn
    {
        return OrderReturn::create([
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
            'company_id' => $order->company_id,
            'customer_id' => $order->customer_id,
            'reason' => $reason,
            'status' => OrderReturn::STATUS_REQUESTED,
            'requested_by_user_id' => $actor?->id,
        ]);
    }

    public function reviewReturn(OrderReturn $return, string $decision, ?User $actor, ?string $comment = null, ?float $refundAmount = null): OrderReturn
    {
        if (! in_array($decision, [OrderReturn::STATUS_APPROVED, OrderReturn::STATUS_REJECTED], true)) {
            throw new OrderException('Недопустимое решение по возврату.');
        }

        if ($return->status !== OrderReturn::STATUS_REQUESTED) {
            throw new OrderException('Этот возврат уже рассмотрен.');
        }

        $return->update([
            'status' => $decision,
            'refund_amount' => $decision === OrderReturn::STATUS_APPROVED ? $refundAmount : null,
            'reviewed_by_user_id' => $actor?->id,
            'reviewed_at' => Carbon::now(),
            'comment' => $comment,
        ]);

        return $return->fresh();
    }

    public function markReturnRefunded(OrderReturn $return): OrderReturn
    {
        if ($return->status !== OrderReturn::STATUS_APPROVED) {
            throw new OrderException('Возврат ещё не одобрен.');
        }

        $return->update(['status' => OrderReturn::STATUS_REFUNDED]);

        return $return->fresh();
    }

    public function updateDelivery(Order $order, array $data): OrderDelivery
    {
        return OrderDelivery::query()->updateOrCreate(
            ['order_id' => $order->id],
            ['tenant_id' => $order->tenant_id, 'company_id' => $order->company_id, ...$data]
        );
    }

    /**
     * ТЗ раздел 16 -- ручное подтверждение оплаты по скриншоту, mirrors
     * BookingService::storePaymentProof(). Order keeps fulfillment status
     * (Order::status) and payment status (Order::payment_status) as two
     * independent fields, unlike Booking -- only payment_status changes here.
     */
    public function storePaymentProof(Order $order, string $filePath, ?float $amount, ?string $operationNumber, User $actor): OrderPaymentProof
    {
        return DB::transaction(function () use ($order, $filePath, $amount, $operationNumber) {
            $proof = OrderPaymentProof::create([
                'order_id' => $order->id,
                'file_path' => $filePath,
                'amount' => $amount,
                'operation_number' => $operationNumber,
                'status' => 'pending',
            ]);

            $order->update(['payment_status' => 'review']);

            return $proof;
        });
    }

    public const PROOF_DECISIONS = ['confirmed', 'rejected', 'resubmission_requested'];

    public function reviewPaymentProof(OrderPaymentProof $proof, string $decision, User $actor, ?string $comment = null): Order
    {
        if (! in_array($decision, self::PROOF_DECISIONS, true)) {
            throw new OrderException('Недопустимое решение по оплате.');
        }

        return DB::transaction(function () use ($proof, $decision, $actor, $comment) {
            if ($proof->status !== 'pending') {
                throw new OrderException('Этот скриншот уже проверен.');
            }

            if ($decision === 'confirmed' && $proof->operation_number) {
                $reused = OrderPaymentProof::query()
                    ->where('operation_number', $proof->operation_number)
                    ->where('status', 'confirmed')
                    ->where('id', '!=', $proof->id)
                    ->exists();

                if ($reused) {
                    throw new OrderException('Этот номер операции уже был использован для другой оплаты.');
                }
            }

            $proof->update([
                'status' => $decision,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => Carbon::now(),
                'comment' => $comment,
            ]);

            $order = $proof->order;
            $order->update(['payment_status' => $decision === 'confirmed' ? 'paid' : 'unpaid']);

            return $order->fresh();
        });
    }

    /** Staff bypasses the screenshot flow entirely because the customer paid in person. */
    public function markPaidCash(Order $order, User $actor, ?string $comment = null): Order
    {
        if ($order->payment_status !== 'unpaid' && $order->payment_status !== 'review') {
            throw new OrderException('Оплату для этого заказа отмечать уже поздно — проверьте текущий статус.');
        }

        $order->update(['payment_status' => 'paid']);

        return $order->fresh();
    }

    /**
     * A refund of the order's own payment -- distinct from OrderReturn's refund
     * (that one is tied to a physical product return; this one is "customer paid,
     * wants their money back" with no return involved, e.g. the order gets
     * cancelled before shipping). Both paths are kept independent on purpose.
     */
    public function requestRefund(Order $order, User $actor, ?string $reason = null): Order
    {
        if (in_array($order->payment_status, ['refund_pending', 'refund_processing'], true)) {
            return $order;
        }

        if ($order->payment_status !== 'paid') {
            throw new OrderException('Возврат можно оформить только для оплаченного заказа.');
        }

        $order->update(['payment_status' => 'refund_pending']);

        return $order->fresh();
    }

    public const REFUND_STATUSES = ['refund_processing', 'refunded', 'refund_rejected'];

    public function updateRefundStatus(Order $order, string $status, User $actor, ?string $comment = null): Order
    {
        if (! in_array($status, self::REFUND_STATUSES, true)) {
            throw new OrderException('Недопустимый статус возврата.');
        }

        if (! in_array($order->payment_status, ['refund_pending', 'refund_processing'], true)) {
            throw new OrderException('Для этого заказа не был запрошен возврат.');
        }

        $order->update(['payment_status' => $status]);

        return $order->fresh();
    }

    /**
     * Deliberately not one DB::transaction() around the whole method -- the external
     * HTTP call to the gateway sits in the middle of it, and an open transaction
     * should never span a slow external request (same reasoning as
     * BookingService::initiateGatewayPayment(), which this mirrors). The local
     * OrderGatewayPayment row is created FIRST so its own id can be embedded in the
     * webhook_url handed to the gateway.
     */
    public function initiateGatewayPayment(Order $order, string $gateway, PaymentGatewayClient $client, ?User $actor): OrderGatewayPayment
    {
        if ($order->payment_status !== 'unpaid' || ! in_array($order->status, Order::ACTIVE_STATUSES, true)) {
            throw new OrderException('Для этого заказа оплата через шлюз недоступна в текущем статусе.');
        }

        $amount = $order->total;

        $payment = OrderGatewayPayment::create([
            'order_id' => $order->id,
            'gateway' => $gateway,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        $webhookUrl = url('/api/payments/'.$gateway.'/webhook/order/'.$payment->id);
        $description = 'Заказ #'.$order->id;

        try {
            $invoice = $client->createInvoice($order->tenant, $description, $amount, $webhookUrl, null, $order->customer?->phone);
        } catch (Throwable $error) {
            $payment->update(['status' => 'failed']);

            throw $error;
        }

        return DB::transaction(function () use ($payment, $invoice) {
            $payment->update([
                'external_id' => $invoice['external_id'],
                'checkout_url' => $invoice['checkout_url'],
                'raw_response' => $invoice['raw'],
            ]);

            return $payment->fresh();
        });
    }

    /** Idempotent: a gateway retrying the same webhook after we already processed it (status no longer 'pending') is a silent no-op, not an error. */
    public function confirmGatewayPayment(OrderGatewayPayment $payment, array $parsedWebhook): Order
    {
        return DB::transaction(function () use ($payment, $parsedWebhook) {
            $payment = OrderGatewayPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== 'pending') {
                return $payment->order;
            }

            $payment->update([
                'status' => $parsedWebhook['status'],
                'webhook_payload' => $parsedWebhook,
                'paid_at' => $parsedWebhook['status'] === 'succeeded' ? Carbon::now() : null,
            ]);

            $order = $payment->order;

            if ($parsedWebhook['status'] === 'succeeded') {
                $order->update(['payment_status' => 'paid']);
            }

            return $order->fresh();
        });
    }

    private function logStatus(Order $order, ?string $old, string $new, ?User $actor, ?string $comment): void
    {
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'old_status' => $old,
            'new_status' => $new,
            'changed_by_user_id' => $actor?->id,
            'comment' => $comment,
        ]);
    }
}
