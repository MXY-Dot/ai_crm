<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingGatewayPayment;
use App\Support\Booking\BookingService;
use App\Support\Payments\AlifPayClient;
use App\Support\Payments\PaymentGatewayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One shared URL per gateway (registered once with the gateway, like the Meta
 * webhooks), but unlike Meta there's no need to resolve "which tenant" from
 * the payload — BookingService::initiateGatewayPayment() creates the
 * BookingGatewayPayment row FIRST and bakes its own id into the webhook_url
 * handed to the gateway at invoice-creation time, so the callback already
 * carries exactly which payment it's for.
 */
class PaymentGatewayWebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, int $paymentId, BookingService $bookings): JsonResponse
    {
        $payment = BookingGatewayPayment::query()->with('booking')->findOrFail($paymentId);

        if ($payment->gateway !== $gateway || ! $payment->booking) {
            abort(404);
        }

        $client = $this->resolveClient($gateway);
        $tenant = $payment->booking->tenant;

        if (! $client->verifyWebhookSignature($tenant, $request->getContent(), (string) $request->header('Signature', ''))) {
            abort(401, 'Invalid webhook signature.');
        }

        $parsed = $client->parseWebhookPayload($request->all());
        $bookings->confirmGatewayPayment($payment, $parsed);

        return response()->json(['ok' => true]);
    }

    private function resolveClient(string $gateway): PaymentGatewayClient
    {
        return match ($gateway) {
            'alif' => app(AlifPayClient::class),
            default => abort(404, 'Unknown payment gateway.'),
        };
    }
}
