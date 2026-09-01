<?php

namespace App\Support\Restaurant;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\TableReservation;
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
 * ТЗ раздел 9/20 — reservation-shaped counterpart to
 * App\Support\Booking\BookingReminderSender, deliberately covering only the
 * event-triggered notices (created/rescheduled/cancelled/completed) this
 * round -- no scheduled "N hours before" cron reminder yet (Booking's
 * SendThreeHourRemindersCommand has no reservation equivalent), since that's
 * a separate scheduled-command addition beyond this module's first slice.
 */
class TableReservationReminderSender
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly WhatsAppClient $whatsapp,
        private readonly FacebookClient $facebook,
        private readonly InstagramClient $instagram,
    ) {
    }

    public function sendCreated(Tenant $tenant, TableReservation $reservation): bool
    {
        return $this->sendOnce($tenant, $reservation, 'created', $this->createdMessage($reservation));
    }

    public function sendRescheduled(Tenant $tenant, TableReservation $reservation, string $oldWhen): bool
    {
        return $this->sendOnce($tenant, $reservation, 'rescheduled_'.$reservation->starts_at->timestamp, $this->rescheduledMessage($reservation, $oldWhen));
    }

    public function sendCancelled(Tenant $tenant, TableReservation $reservation): bool
    {
        return $this->sendOnce($tenant, $reservation, 'cancelled', $this->cancelledMessage($reservation));
    }

    public function sendCompleted(Tenant $tenant, TableReservation $reservation): bool
    {
        return $this->sendOnce($tenant, $reservation, 'completed', $this->completedMessage($reservation));
    }

    private function sendOnce(Tenant $tenant, TableReservation $reservation, string $eventKey, string $text): bool
    {
        if (in_array($eventKey, $reservation->reminders_sent ?? [], true)) {
            return false;
        }

        $reservation->loadMissing(['customer', 'resource', 'company']);

        if ($this->sendMessage($tenant, $reservation, $text, $eventKey) === null) {
            return false;
        }

        $sent = $reservation->reminders_sent ?? [];
        $sent[] = $eventKey;
        $reservation->forceFill(['reminders_sent' => array_values(array_unique($sent))])->save();

        return true;
    }

    private function sendMessage(Tenant $tenant, TableReservation $reservation, string $text, string $eventKey): ?string
    {
        $conversation = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $reservation->customer_id)
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
            Log::warning('TableReservationReminderSender: send failed', [
                'reservation_id' => $reservation->id,
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
            'meta' => ['event' => 'table_reservation_'.$eventKey, 'table_reservation_id' => $reservation->id],
        ]);

        return $externalId;
    }

    /** @return array{0: string, 1: int, 2: string} [when, partySize, where] */
    private function whereWhenParty(TableReservation $reservation): array
    {
        $timezone = $reservation->company?->timezone ?: config('app.timezone');
        $when = $reservation->starts_at->copy()->setTimezone($timezone)->translatedFormat('d.m в H:i');
        $company = $reservation->company?->name;

        return [$when, $reservation->party_size, $company ? " в «{$company}»" : ''];
    }

    private function createdMessage(TableReservation $reservation): string
    {
        [$when, $partySize, $where] = $this->whereWhenParty($reservation);

        return "Столик забронирован{$where} — {$when}, гостей: {$partySize}.";
    }

    private function rescheduledMessage(TableReservation $reservation, string $oldWhen): string
    {
        [$when, , $where] = $this->whereWhenParty($reservation);

        return "Ваша бронь столика перенесена{$where} — было {$oldWhen}, стало {$when}.";
    }

    private function cancelledMessage(TableReservation $reservation): string
    {
        [$when, , $where] = $this->whereWhenParty($reservation);

        return "Ваша бронь столика отменена{$where} — {$when}.";
    }

    private function completedMessage(TableReservation $reservation): string
    {
        $company = $reservation->company?->name;
        $where = $company ? " в «{$company}»" : '';

        return "Спасибо, что посетили нас{$where}! Будем рады видеть вас снова.";
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
