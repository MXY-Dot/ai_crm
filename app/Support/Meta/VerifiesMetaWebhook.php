<?php

namespace App\Support\Meta;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Shared GET-handshake + X-Hub-Signature-256 verification for the three Meta
 * webhook endpoints (WhatsApp/Instagram/Facebook). Unlike Telegram (one bot
 * per tenant, so the webhook URL itself carries ?tenant_slug=), Meta's
 * webhook is registered once per platform App in the Meta developer console
 * and carries events for every tenant's Page/WABA/IG account through this
 * single URL — verify_token/app_secret here are app-level, not per-tenant;
 * each controller resolves the actual tenant from the payload itself (page
 * id / phone_number_id / business account id) via MetaChannelResolver.
 */
trait VerifiesMetaWebhook
{
    /** Meta sends hub.mode/hub.verify_token/hub.challenge as a GET when a webhook is (re)subscribed in the App dashboard. PHP normalizes the dots in those query keys to underscores. */
    protected function handleSubscriptionVerification(Request $request): ?Response
    {
        if ($request->query('hub_mode') !== 'subscribe') {
            return null;
        }

        $expected = (string) config('services.meta.webhook_verify_token');
        $token = (string) $request->query('hub_verify_token');

        if ($expected === '' || ! hash_equals($expected, $token)) {
            abort(403, 'Invalid verify token.');
        }

        return response((string) $request->query('hub_challenge'), 200);
    }

    /**
     * $secretConfigKey defaults to the main app's secret (WhatsApp and Facebook
     * both ride the main CrmPublic app) — Instagram overrides this to
     * 'services.meta.instagram_app_secret' since "Instagram API with Instagram
     * Login" is a genuinely separate Meta app with its own credentials (see
     * InstagramClient's docblock), and Meta signs each webhook payload with
     * whichever app actually owns it. Found live: enabling this check with only
     * the main secret silently 401'd every real Instagram webhook call, because
     * the signature was computed with a different app's secret than the one
     * actually used to sign it.
     */
    protected function guardSignature(Request $request, string $secretConfigKey = 'services.meta.app_secret'): void
    {
        $secret = (string) config($secretConfigKey);

        if ($secret === '') {
            return;
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            abort(401, 'Invalid webhook signature.');
        }
    }
}
