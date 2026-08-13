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

        // A shared-contact update (tapped the "request contact" keyboard button — see
        // AiWorkflow::requestContact()) has no text/caption of its own, so it would
        // otherwise be dropped by the empty-text guard below.
        $hasContact = is_array($message) && $this->ownContact($message) !== null;

        if (! is_array($message) || (! $hasContact && $this->text($message) === '')) {
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

        $tenant = Tenant::query()
            ->where('slug', $value)
            ->when(is_numeric($value), fn ($query) => $query->orWhere('id', $value))
            ->first();

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
        $repliedId = Arr::get($message, 'reply_to_message.message_id');
        $contact = $this->ownContact($message);
        $content = $contact ? '📱 Поделился(-ась) контактом' : $this->text($message);

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
                'phone_number' => $contact ? Arr::get($contact, 'phone_number') : null,
            ],
            'message' => [
                'id' => 'telegram-'.$chatId.'-'.$messageId,
                'content' => $content,
                'reply_to_external_id' => $repliedId ? 'telegram-'.$chatId.'-'.$repliedId : null,
            ],
        ];
    }

    private function text(array $message): string
    {
        return trim((string) (Arr::get($message, 'text') ?? Arr::get($message, 'caption') ?? ''));
    }

    /**
     * Only trusts a `contact` payload when it's the sender's own number (Telegram's
     * request_contact keyboard button enforces this client-side, but a raw API call
     * could in principle attach someone else's contact card — don't trust that as
     * the sender's phone).
     */
    private function ownContact(array $message): ?array
    {
        $contact = Arr::get($message, 'contact');

        if (! is_array($contact) || Arr::get($contact, 'user_id') !== Arr::get($message, 'from.id')) {
            return null;
        }

        return $contact;
    }
}