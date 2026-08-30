<?php

namespace App\Support\Payments;

use App\Models\Tenant;
use App\Support\Integrations\TenantIntegrationSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * IMPORTANT — built from Alif Pay's publicly documented API at
 * docs.alifpay.uz. That documentation is for Alif's UZBEKISTAN entity;
 * Alif Bank Tajikistan (alif.tj) is a legally separate bank with its own
 * merchant-acquiring product ("Alif Business" -> POS/QR/online), and its
 * API is not publicly documented — only available after signing a real
 * merchant agreement with their business team. This client follows the
 * .uz API's exact documented request/response shape as the best available
 * reference (Alif markets itself as one regional group, so the shape is a
 * reasonable starting point), but:
 *   - the base URL below is very likely wrong for Tajikistan,
 *   - the webhook signature key (documented only as "the secret key") may
 *     not be the same value as the auth Token,
 *   - nothing here has been tested against a real endpoint.
 * Both `base_url` and the two credentials are tenant-editable (see
 * TenantIntegrationSettings) specifically so correcting them once real
 * Tajikistan merchant docs exist never needs a code deploy.
 */
class AlifPayClient implements PaymentGatewayClient
{
    public function __construct(private readonly TenantIntegrationSettings $settings)
    {
    }

    public function createInvoice(Tenant $tenant, string $description, float $amount, string $webhookUrl, ?string $redirectUrl, ?string $customerPhone): array
    {
        $token = $this->settings->alifPayToken($tenant);
        $baseUrl = $this->settings->alifPayBaseUrl($tenant);

        if ($token === '') {
            throw new RuntimeException('Alif Pay token is required.');
        }

        try {
            $response = Http::connectTimeout(5)->timeout(15)->acceptJson()
                ->withHeaders(['Token' => $token])
                ->post($baseUrl.'/invoice', array_filter([
                    'items' => [[
                        'name' => $description,
                        'amount' => 1,
                        // Documented unit is the smallest currency unit (called "tiyin" in
                        // the .uz docs) -- assumed to be diram (1/100 somoni) here.
                        'price' => (int) round($amount * 100),
                    ]],
                    'webhook_url' => $webhookUrl,
                    'redirect_url' => $redirectUrl,
                    'phone' => $customerPhone,
                ], fn ($value) => $value !== null));
        } catch (\Throwable $error) {
            throw new RuntimeException('Alif Pay request failed: '.$error->getMessage(), previous: $error);
        }

        $json = $response->json();

        if (! $response->successful() || Arr::get($json, 'error')) {
            throw new RuntimeException('Alif Pay returned an error: '.Arr::get($json, 'error.message', 'HTTP '.$response->status()));
        }

        $invoiceId = Arr::get($json, 'id') ?? Arr::get($json, 'msg.id');

        if (! $invoiceId) {
            throw new RuntimeException('Alif Pay did not return an invoice id.');
        }

        return [
            'external_id' => (string) $invoiceId,
            'checkout_url' => 'https://checkout.alifpay.uz/?invoice='.$invoiceId,
            'raw' => is_array($json) ? $json : [],
        ];
    }

    public function verifyWebhookSignature(Tenant $tenant, string $rawBody, string $signatureHeader): bool
    {
        $secret = $this->settings->alifPayWebhookSecret($tenant);

        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    public function parseWebhookPayload(array $payload): array
    {
        $status = Arr::get($payload, 'payment.status');
        $rawAmount = Arr::get($payload, 'payment.amount');

        return [
            'external_id' => (string) (Arr::get($payload, 'id') ?? Arr::get($payload, 'payment.id') ?? ''),
            'status' => $status === 'SUCCEEDED' ? 'succeeded' : 'failed',
            'amount' => $rawAmount !== null ? ((float) $rawAmount) / 100 : null,
        ];
    }
}
