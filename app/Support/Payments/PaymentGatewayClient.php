<?php

namespace App\Support\Payments;

use App\Models\Tenant;

/**
 * Contract every payment-gateway client (Alif, DC/Smartpay, ...) implements —
 * lets BookingService and PaymentGatewayWebhookController stay gateway-agnostic;
 * adding a second gateway later is a new class + one match() arm, not a rewrite.
 */
interface PaymentGatewayClient
{
    /**
     * @return array{external_id: string, checkout_url: string, raw: array}
     */
    public function createInvoice(Tenant $tenant, string $description, float $amount, string $webhookUrl, ?string $redirectUrl, ?string $customerPhone): array;

    public function verifyWebhookSignature(Tenant $tenant, string $rawBody, string $signatureHeader): bool;

    /**
     * @return array{external_id: string, status: 'succeeded'|'failed', amount: ?float}
     */
    public function parseWebhookPayload(array $payload): array;
}
