<?php

namespace App\Support\Hotel;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\RoomReservation;
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
 * App\Support\Booking\BookingReminderSender / App\Support\Restaurant\
 * TableReservationReminderSender, same event-triggered-only scope (no
 * scheduled "N hours before" cron reminder this round).
 */
class RoomReservationReminderSender
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly WhatsAppClient $whatsapp,
        private readonly FacebookClient $facebook,
        private readonly InstagramClient $instagram,
    ) {
    }

    public function sendCreated(Tenant $tenant, RoomReservation $reservation): bool
    {
        return $this->sendOnce($tenant, $reservation, 'created', $this->createdMessage($reservation));
    }

    public function sendPaymentConfirmed(Tenant $tenant, RoomReservation $reservation): bool
    {
        return $this->sendOnce($tenant, $reservation, 'payment_confirmed', $this->paymentConfirmedMessage($reservation));
    }

    public function sendRescheduled(Tenant $tenant, RoomReservation $reservation, string $oldWhen): bool
    {
        return $this->sendOnce($tenant, $reservation, 'rescheduled_'.$reservation->starts_at->timestamp, $this->rescheduledMessage($reservation, $oldWhen));
    }

    public function sendCancelled(Tenant $tenant, RoomReservation $reservation): bool
    {
        return $this->sendOnce($tenant, $reservation, 'cancelled', $this->cancelledMessage($reservation));
    }

    public function sendCheckedOut(Tenant $tenant, RoomReservation $reservation): bool
    {
        return $this->sendOnce($tenant, $reservation, 'checked_out', $this->checkedOutMessage($reservation));
    }

    private function sendOnce(Tenant $tenant, RoomReservation $reservation, string $eventKey, string $text): bool
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

    private function sendMessage(Tenant $tenant, RoomReservation $reservation, string $text, string $eventKey): ?string
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
            Log::warning('RoomReservationReminderSender: send failed', [
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
            'meta' => ['event' => 'room_reservation_'.$eventKey, 'room_reservation_id' => $reservation->id],
        ]);

        return $externalId;
    }

    /** @return array{0: string, 1: string, 2: string} [checkIn, checkOut, where] */
    private function whenWhere(RoomReservation $reservation): array
    {
        $timezone = $reservation->company?->timezone ?: config('app.timezone');
        $checkIn = $reservation->starts_at->copy()->setTimezone($timezone)->translatedFormat('d.m.Y');
        $checkOut = $reservation->ends_at->copy()->setTimezone($timezone)->translatedFormat('d.m.Y');
        $company = $reservation->company?->name;

        return [$checkIn, $checkOut, $company ? " в «{$company}»" : ''];
    }

    private function createdMessage(RoomReservation $reservation): string
    {
        [$checkIn, $checkOut, $where] = $this->whenWhere($reservation);
        $room = $reservation->resource?->name ?? 'номер';

        $paymentNote = $reservation->prepayment_amount > 0
            ? ' Для подтверждения потребуется предоплата — свяжемся с вами по деталям оплаты.'
            : '';

        return "Бронь{$where} подтверждена: {$room}, заезд {$checkIn}, выезд {$checkOut}.{$paymentNote}";
    }

    private function paymentConfirmedMessage(RoomReservation $reservation): string
    {
        [$checkIn, $checkOut, $where] = $this->whenWhere($reservation);
        $room = $reservation->resource?->name ?? 'номер';

        return "Оплата получена, бронь{$where} подтверждена: {$room}, заезд {$checkIn}, выезд {$checkOut}.";
    }

    private function rescheduledMessage(RoomReservation $reservation, string $oldWhen): string
    {
        [$checkIn, $checkOut, $where] = $this->whenWhere($reservation);

        return "Ваша бронь номера{$where} изменена — было {$oldWhen}, теперь заезд {$checkIn}, выезд {$checkOut}.";
    }

    private function cancelledMessage(RoomReservation $reservation): string
    {
        [$checkIn, $checkOut, $where] = $this->whenWhere($reservation);

        return "Ваша бронь номера{$where} отменена — заезд {$checkIn}, выезд {$checkOut}.";
    }

    private function checkedOutMessage(RoomReservation $reservation): string
    {
        $company = $reservation->company?->name;
        $where = $company ? " в «{$company}»" : '';

        return "Спасибо, что останавливались у нас{$where}! Будем рады видеть вас снова.";
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
