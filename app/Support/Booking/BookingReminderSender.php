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

        if ($this->sendMessage($tenant, $booking, $this->message($booking), 'reminder_24h') === null) {
            return false;
        }

        $booking->forceFill(['reminder_sent_at' => now()])->save();

        return true;
    }

    /**
     * ТЗ раздел 20 -- the remaining event-triggered reminders, beyond the original
     * 24h-before one above. Each is a thin wrapper around sendMessage() with its own
     * dedupe key in the newer reminders_sent JSON column (kept separate from
     * reminder_sent_at, which stays 24h-only for backward compatibility). All are
     * best-effort by design (see sendMessage()'s docblock) -- called straight from
     * BookingService's write methods, and must never be able to fail a real booking
     * action just because the customer has no messageable channel on file.
     */
    public function sendCreated(Tenant $tenant, Booking $booking): bool
    {
        return $this->sendOnce($tenant, $booking, 'created', $this->createdMessage($booking));
    }

    public function sendPaymentConfirmed(Tenant $tenant, Booking $booking): bool
    {
        return $this->sendOnce($tenant, $booking, 'payment_confirmed', $this->paymentConfirmedMessage($booking));
    }

    public function sendRescheduled(Tenant $tenant, Booking $booking, string $oldWhen): bool
    {
        // Reschedule can happen more than once per booking -- keyed by the new time so
        // each real reschedule still sends its own notice instead of only the first.
        return $this->sendOnce($tenant, $booking, 'rescheduled_'.$booking->starts_at->timestamp, $this->rescheduledMessage($booking, $oldWhen));
    }

    public function sendCancelled(Tenant $tenant, Booking $booking): bool
    {
        return $this->sendOnce($tenant, $booking, 'cancelled', $this->cancelledMessage($booking));
    }

    public function sendCompleted(Tenant $tenant, Booking $booking): bool
    {
        return $this->sendOnce($tenant, $booking, 'completed', $this->completedMessage($booking));
    }

    public function send3HoursBefore(Tenant $tenant, Booking $booking): bool
    {
        return $this->sendOnce($tenant, $booking, '3h_before', $this->threeHoursMessage($booking));
    }

    private function sendOnce(Tenant $tenant, Booking $booking, string $eventKey, string $text): bool
    {
        if (in_array($eventKey, $booking->reminders_sent ?? [], true)) {
            return false;
        }

        $booking->loadMissing(['customer', 'service', 'employee', 'company']);

        if ($this->sendMessage($tenant, $booking, $text, $eventKey) === null) {
            return false;
        }

        $sent = $booking->reminders_sent ?? [];
        $sent[] = $eventKey;
        $booking->forceFill(['reminders_sent' => array_values(array_unique($sent))])->save();

        return true;
    }

    /**
     * Shared send path for every reminder/notification, old and new: resolves the
     * customer's most recently messaged channel, sends through it, and records a real
     * Message row so the notice shows up in the Inbox thread like any other outbound
     * send. Returns the resulting external_id, or null when there's no messageable
     * channel or the send itself failed -- never throws, since a booking write (create/
     * reschedule/cancel/confirm payment) must always succeed regardless of whether the
     * customer can be proactively notified.
     */
    private function sendMessage(Tenant $tenant, Booking $booking, string $text, string $eventKey): ?string
    {
        $conversation = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $booking->customer_id)
            ->whereNotNull('external_id')
            ->with('channel')
            ->latest('last_message_at')
            ->first();

        $provider = $conversation?->channel?->provider;

        if (! $conversation || ! $provider) {
            return null;
        }

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
                'event' => $eventKey,
                'provider' => $provider,
                'error' => $error->getMessage(),
            ]);

            return null;
        }

        if ($externalId === null) {
            return null;
        }

        Message::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'system',
            'sender_name' => 'WERO',
            'body' => $text,
            'external_id' => $externalId,
            'sent_at' => now(),
            'meta' => ['event' => 'booking_'.$eventKey, 'booking_id' => $booking->id],
        ]);

        return $externalId;
    }

    private function whereWhomWhen(Booking $booking): array
    {
        $timezone = $booking->company?->timezone ?: config('app.timezone');
        $when = $booking->starts_at->copy()->setTimezone($timezone)->translatedFormat('d.m в H:i');
        $service = $booking->service?->name ?? 'услугу';
        $employee = $booking->employee?->name;
        $company = $booking->company?->name;

        return [$when, $service, $employee ? " к {$employee}" : '', $company ? " в «{$company}»" : ''];
    }

    private function createdMessage(Booking $booking): string
    {
        [$when, $service, $withWhom, $where] = $this->whereWhomWhen($booking);

        $paymentNote = $booking->prepayment_amount > 0
            ? ' Для подтверждения потребуется предоплата — свяжемся с вами по деталям оплаты.'
            : '';

        return "Вы записаны: «{$service}»{$withWhom}{$where} — {$when}.{$paymentNote}";
    }

    private function paymentConfirmedMessage(Booking $booking): string
    {
        [$when, $service, $withWhom, $where] = $this->whereWhomWhen($booking);

        return "Оплата получена, ваша запись подтверждена: «{$service}»{$withWhom}{$where} — {$when}.";
    }

    private function rescheduledMessage(Booking $booking, string $oldWhen): string
    {
        [$when, $service, $withWhom, $where] = $this->whereWhomWhen($booking);

        return "Ваша запись перенесена: «{$service}»{$withWhom}{$where} — было {$oldWhen}, стало {$when}.";
    }

    private function cancelledMessage(Booking $booking): string
    {
        [$when, $service, $withWhom, $where] = $this->whereWhomWhen($booking);

        return "Ваша запись отменена: «{$service}»{$withWhom}{$where} — {$when}.";
    }

    private function completedMessage(Booking $booking): string
    {
        $service = $booking->service?->name ?? 'услугу';
        $company = $booking->company?->name;
        $where = $company ? " в «{$company}»" : '';

        return "Спасибо, что посетили нас{$where}! Будем рады видеть вас снова — если хотите повторить «{$service}», просто напишите нам.";
    }

    private function threeHoursMessage(Booking $booking): string
    {
        [$when, $service, $withWhom, $where] = $this->whereWhomWhen($booking);

        return "Напоминаем: сегодня в {$when} у вас запись на «{$service}»{$withWhom}{$where}.";
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
