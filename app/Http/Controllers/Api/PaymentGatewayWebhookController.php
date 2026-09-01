<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingGatewayPayment;
use App\Models\OrderGatewayPayment;
use App\Support\Booking\BookingService;
use App\Support\Commerce\OrderService;
use App\Support\Payments\AlifPayClient;
use App\Support\Payments\PaymentGatewayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One shared URL per gateway (registered once with the gateway, like the Meta
 * webhooks), but unlike Meta there's no need to resolve "which tenant" from
 * the payload — BookingService::initiateGatewayPayment()/
 * OrderService::initiateGatewayPayment() create the gateway-payment row FIRST
 * and bake its own id into the webhook_url handed to the gateway at
 * invoice-creation time, so the callback already carries exactly which
 * payment it's for.
 *
 * The bare `{paymentId}` route (no type segment) is the original, Booking-only
 * URL — already baked into every in-flight Booking checkout link, so `$type`
 * defaults to 'booking' there and that behavior is unchanged. Order payments
 * use the newer `{type}/{paymentId}` route with `$type = 'order'`.
 */
class PaymentGatewayWebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, string $paymentId, BookingService $bookings, OrderService $orders, ?string $type = null): JsonResponse
    {
        $type ??= 'booking';

        [$payment, $tenant, $confirm] = match ($type) {
            'booking' => $this->resolveBooking((int) $paymentId, $bookings),
            'order' => $this->resolveOrder((int) $paymentId, $orders),
            default => abort(404),
        };

        if ($payment->gateway !== $gateway) {
            abort(404);
        }

        $client = $this->resolveClient($gateway);

        if (! $client->verifyWebhookSignature($tenant, $request->getContent(), (string) $request->header('Signature', ''))) {
            abort(401, 'Invalid webhook signature.');
        }

        $parsed = $client->parseWebhookPayload($request->all());
        $confirm($payment, $parsed);

        return response()->json(['ok' => true]);
    }

    /** @return array{0: BookingGatewayPayment, 1: \App\Models\Tenant, 2: callable} */
    private function resolveBooking(int $paymentId, BookingService $bookings): array
    {
        $payment = BookingGatewayPayment::query()->with('booking')->findOrFail($paymentId);

        if (! $payment->booking) {
            abort(404);
        }

        return [$payment, $payment->booking->tenant, fn ($payment, $parsed) => $bookings->confirmGatewayPayment($payment, $parsed)];
    }

    /** @return array{0: OrderGatewayPayment, 1: \App\Models\Tenant, 2: callable} */
    private function resolveOrder(int $paymentId, OrderService $orders): array
    {
        $payment = OrderGatewayPayment::query()->with('order')->findOrFail($paymentId);

        if (! $payment->order) {
            abort(404);
        }

        return [$payment, $payment->order->tenant, fn ($payment, $parsed) => $orders->confirmGatewayPayment($payment, $parsed)];
    }

    private function resolveClient(string $gateway): PaymentGatewayClient
    {
        return match ($gateway) {
            'alif' => app(AlifPayClient::class),
            default => abort(404, 'Unknown payment gateway.'),
        };
    }
}
