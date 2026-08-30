<?php

namespace App\Support\Booking;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\FacebookClient;
use App\Support\InstagramClient;
use App\Support\TelegramClient;
use App\Support\WhatsAppClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reminds a customer ~REMINDER_HOURS_BEFORE their booking starts. There's no
 * "preferred contact channel" field on Customer, so the channel is resolved
 * from their most recent Conversation's Channel (whichever of Telegram/
 * WhatsApp/Instagram/Facebook they last actually messaged through) — a
 * Chatwoot- or website-widget-routed customer has no way to be proactively
 * messaged (no outbound API for either), so those are skipped, not failed.
 */
class BookingReminderSender
{
    public const REMINDER_HOURS_BEFORE = 24;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly WhatsAppClient $whatsapp,
        private readonly FacebookClient $facebook,
        private readonly InstagramClient $instagram,
    ) {
    }

    public function send(Tenant $tenant, Booking $booking): bool
    {
        $booking->loadMissing(['customer', 'service', 'employee', 'company']);

        $conversation = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $booking->customer_id)
            ->whereNotNull('external_id')
            ->with('channel')
            ->latest('last_message_at')
            ->first();

        $provider = $conversation?->channel?->provider;

        if (! $conversation || ! $provider) {
            return false;
        }

        $text = $this->message($booking);

        try {
            $externalId = match ($provider) {
                'telegram' => $this->sendTelegram($tenant, $conversation, $text),
                'whatsapp' => $this->sendWhatsapp($tenant, $conversation, $text),
                'instagram' => $this->sendInstagram($tenant, $conversation, $text),
                'facebook' => $this->sendFacebook($tenant, $conversation, $text),
                default => null,
            };
        } catch (Throwable $error) {
            Log::warning('BookingReminderSender: send failed', [
                'booking_id' => $booking->id,
                'provider' => $provider,
                'error' => $error->getMessage(),
            ]);

            return false;
        }

        if ($externalId === null) {
            return false;
        }

        // Recorded as a real Message (sender_type 'system', same enum
        // ChatwootWebhookHandler already accepts) so the reminder shows up in
        // the conversation thread in Inbox, same as any other outbound send.
        Message::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'system',
            'sender_name' => 'WERO',
            'body' => $text,
            'external_id' => $externalId,
            'sent_at' => now(),
            'meta' => ['event' => 'booking_reminder', 'booking_id' => $booking->id],
        ]);

        $booking->forceFill(['reminder_sent_at' => now()])->save();

        return true;
    }

    private function message(Booking $booking): string
    {
        $timezone = $booking->company?->timezone ?: config('app.timezone');
        $when = $booking->starts_at->copy()->setTimezone($timezone)->translatedFormat('d.m в H:i');
        $service = $booking->service?->name ?? 'услугу';
        $employee = $booking->employee?->name;
        $company = $booking->company?->name;

        $where = $company ? " в «{$company}»" : '';
        $withWhom = $employee ? " к {$employee}" : '';

        return "Напоминаем: {$when} у вас запись на «{$service}»{$withWhom}{$where}. Если планы изменились — сообщите нам, пожалуйста, заранее.";
    }

    private function sendTelegram(Tenant $tenant, Conversation $conversation, string $text): ?string
    {
        $chatId = str_replace('telegram-', '', (string) $conversation->external_id);
        $payload = $this->telegram->sendMessage($tenant, $chatId, $text);
        $messageId = Arr::get($payload, 'result.message_id');

        return $messageId ? 'telegram-'.$chatId.'-'.$messageId : null;
    }

    private function sendWhatsapp(Tenant $tenant, Conversation $conversation, string $text): ?string
    {
        $to = str_replace('whatsapp-', '', (string) $conversation->external_id);
        $payload = $this->whatsapp->sendMessage($tenant, $to, $text);
        $messageId = Arr::get($payload, 'messages.0.id');

        return 'whatsapp-'.$to.'-'.($messageId ?? Str::random(12));
    }

    private function sendInstagram(Tenant $tenant, Conversation $conversation, string $text): ?string
    {
        $igsid = str_replace('instagram-', '', (string) $conversation->external_id);
        $this->instagram->sendMessage($tenant, $igsid, $text);

        return 'instagram-'.$igsid.'-'.Str::random(12);
    }

    private function sendFacebook(Tenant $tenant, Conversation $conversation, string $text): ?string
    {
        $psid = str_replace('facebook-', '', (string) $conversation->external_id);
        $this->facebook->sendMessage($tenant, $psid, $text);

        return 'facebook-'.$psid.'-'.Str::random(12);
    }
}
