<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Chatwoot\ChatwootPayloadMapper;
use App\Support\Inbox\ChatwootWebhookHandler;
use App\Support\Integrations\TenantIntegrationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ChatwootWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        ChatwootWebhookHandler $handler,
        TenantIntegrationSettings $secrets,
        ChatwootPayloadMapper $mapper,
    ): JsonResponse {
        $tenant = $this->tenant($request);
        $this->guardSecret($request, $tenant, $secrets);

        $payload = $mapper->fromWebhook($request->all());

        $senderType = $payload['sender']['type'] ?? 'customer';

        if ($senderType === 'system') {
            return response()->json(['ok' => true, 'ignored' => true, 'reason' => 'system_message']);
        }

        // ЭТАП 3.9 — only message_created/message_updated actually carry a message
        // body; every other Chatwoot event type (conversation_status_changed,
        // assignee_changed, label_added, contact_updated, etc.) used to hit
        // handle() -> message()'s "body required" guard and 422. Route those to the
        // lightweight conversation-sync path instead of guessing at handle()'s way.
        if (! in_array($payload['event'] ?? 'message_created', ['message_created', 'message_updated'], true)) {
            $result = $handler->handleConversationEvent($tenant, $payload);

            return response()->json(['ok' => true] + $result);
        }

        $result = $handler->handle($tenant, $payload, $senderType === 'customer');

        return response()->json(['ok' => true] + $result, ! empty($result['duplicate']) ? 200 : 201);
    }

    private function guardSecret(Request $request, Tenant $tenant, TenantIntegrationSettings $secrets): void
    {
        $expected = $secrets->chatwootWebhookSecret($tenant);

        if ($expected === '' || hash_equals($expected, (string) $request->header('X-Webhook-Secret', ''))) {
            return;
        }

        $timestamp = (string) $request->header('X-Chatwoot-Timestamp', '');
        $signature = (string) $request->header('X-Chatwoot-Signature', '');
        $expectedSignature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $expected);

        if ($timestamp !== '' && hash_equals($expectedSignature, $signature)) {
            return;
        }

        abort(401, 'Invalid webhook secret.');
    }

    private function tenant(Request $request): Tenant
    {
        $value = $request->header('X-Tenant-Id') ?? $request->input('tenant_id') ?? $request->input('tenant_slug');

        if (! $value) {
            throw ValidationException::withMessages(['tenant' => 'Tenant context is required.']);
        }

        $tenant = Tenant::query()
            ->where('slug', $value)
            ->when(is_numeric($value), fn ($query) => $query->orWhere('id', $value))
            ->first();

        if (! $tenant) {
            throw ValidationException::withMessages(['tenant' => 'Tenant was not found.']);
        }

        return $tenant;
    }
}