<?php

namespace App\Support\Commerce;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
