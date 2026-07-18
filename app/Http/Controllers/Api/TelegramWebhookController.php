<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Inbox\ChatwootWebhookHandler;
use App\Support\Integrations\TenantIntegrationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, ChatwootWebhookHandler $handler, TenantIntegrationSettings $settings): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->guardSecret($request, $tenant, $settings);
        $message = $request->input('message') ?? $request->input('edited_message');

        if (! is_array($message) || $this->text($message) === '') {
            return response()->json(['ok' => true, 'ignored' => true, 'reason' => 'unsupported_update']);
        }

        $result = $handler->handle($tenant, $this->payload($message));

        return response()->json(['ok' => true] + $result, ! empty($result['duplicate']) ? 200 : 201);
    }

    private function guardSecret(Request $request, Tenant $tenant, TenantIntegrationSettings $settings): void
    {
        $expected = $settings->telegramWebhookSecret($tenant);

        if ($expected !== '' && ! hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''))) {
            abort(401, 'Invalid Telegram webhook secret.');
        }
    }

    private function tenant(Request $request): Tenant
    {
        $value = $request->header('X-Tenant-Id') ?? $request->input('tenant_id') ?? $request->input('tenant_slug');

        if (! $value) {
            throw ValidationException::withMessages(['tenant' => 'Tenant context is required.']);
        }

        $tenant = Tenant::query()->where('id', $value)->orWhere('slug', $value)->first();

        if (! $tenant) {
            throw ValidationException::withMessages(['tenant' => 'Tenant was not found.']);
        }

        return $tenant;
    }

    private function payload(array $message): array
    {
        $chatId = (string) Arr::get($message, 'chat.id', '');
        $messageId = (string) Arr::get($message, 'message_id', sha1(json_encode($message)));
        $name = trim(Arr::get($message, 'from.first_name', '').' '.Arr::get($message, 'from.last_name', ''));

        return [
            'event' => 'telegram_message',
            'provider' => 'telegram',
            'inbox' => ['id' => 'telegram', 'name' => 'Telegram Bot'],
            'conversation' => [
                'id' => 'telegram-'.$chatId,
                'subject' => 'Telegram chat '.$chatId,
                'status' => 'open',
                'priority' => 'normal',
            ],
            'sender' => [
                'name' => $name !== '' ? $name : (Arr::get($message, 'from.username') ?? 'Telegram user'),
                'type' => 'customer',
            ],
            'message' => [
                'id' => 'telegram-'.$chatId.'-'.$messageId,
                'content' => $this->text($message),
            ],
        ];
    }

    private function text(array $message): string
    {
        return trim((string) (Arr::get($message, 'text') ?? Arr::get($message, 'caption') ?? ''));
    }
}