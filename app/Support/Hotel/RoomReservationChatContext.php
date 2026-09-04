<?php

namespace App\Support\Hotel;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Resource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ТЗ раздел 9/12 — "бронь номера через AI-чат". Gate + real-data context
 * shared by RoomReservationChatAssistant and (via promptSection()) both AI
 * reply engines' prompts, mirroring App\Support\Restaurant\
 * TableReservationChatContext's exact shape and the same reasoning for why
 * every query here is explicit company_id-filtered rather than relying on
 * the BelongsToTenant global scope (AiWorkflow runs inside
 * ProcessAiReplyJob, a queued job with no request-scoped TenantContext
 * set). Gates on the `room_booking` CompanyModule key (see
 * App\Support\Business\ModuleRegistry::MODULES) — NOT 'room_reservations',
 * which doesn't exist in the registry.
 *
 * Unlike Booking/TableReservation, a room reservation has no single "slot" —
 * a stay is a [check-in, check-out) date range with no time-of-day
 * component, and RoomAvailabilityCalculator::availableRooms() answers "which
 * rooms are free for this exact range", not "search forward for the next
 * free time". So there is no SEARCH_DAYS day-stepping loop here: the
 * customer must give both dates before any real offer can be computed, and
 * availableRooms() below is a single, direct query.
 */
class RoomReservationChatContext
{
    private const OFFER_LIMIT = 3;

    public function __construct(private readonly RoomAvailabilityCalculator $calculator)
    {
    }

    /** room_booking module enabled AND at least one active room actually exists -- a toggled-on module with no real rooms configured yet should not activate this. */
    public function isAvailableFor(Company $company): bool
    {
        $moduleEnabled = CompanyModule::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('module_key', 'room_booking')
            ->where('enabled', true)
            ->exists();

        if (! $moduleEnabled) {
            return false;
        }

        return $this->activeRooms($company)->isNotEmpty();
    }

    /** @return Collection<int, Resource> */
    public function activeRooms(Company $company): Collection
    {
        return Resource::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('type', 'room')
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    /** Injected into DifyClient::businessProfile() alongside Booking's/TableReservation's own sections -- deliberately never mentions a specific free room or price itself, only that the largest room's capacity exists, same "never let the model invent availability" discipline as the other two contexts. */
    public function promptSection(Company $company): string
    {
        // Found live (same bug as TableReservationChatContext): this only checked
        // activeRooms()->isEmpty(), never the room_booking module flag itself.
        // isAvailableFor() already covers both checks.
        if (! $this->isAvailableFor($company)) {
            return '';
        }

        $rooms = $this->activeRooms($company);

        $maxCapacity = (int) $rooms->max('capacity');

        return "Бронирование номеров доступно прямо в этом чате. Самый большой номер рассчитан максимум на {$maxCapacity} гостей."
            ."\nЕсли клиент хочет забронировать номер — уточни дату заезда, дату выезда и количество гостей, если это ещё не ясно. Никогда не называй клиенту конкретный свободный номер или цену сам — реальные свободные номера подбирает отдельная система и предложит их следующим сообщением.";
    }

    /**
     * Real free rooms for the exact [$checkIn, $checkOut) range and $guests
     * count, each entry enriched with the stay's own starts_at/ends_at/
     * nights/total_amount so RoomReservationChatAssistant can persist a pick
     * in Message.meta and act on it next turn without re-resolving anything
     * (mirrors the self-contained slot shape TableReservationChatContext's
     * own nextAvailableSlots() already returns).
     *
     * @return array<int, array{resource_id:int, resource_name:string, capacity:int|null, price_per_night:float, starts_at:string, ends_at:string, nights:int, total_amount:float}>
     */
    public function availableRooms(Company $company, Carbon $checkIn, Carbon $checkOut, int $guests, ?int $branchId = null): array
    {
        $rooms = $this->calculator->availableRooms($company, $checkIn, $checkOut, $guests, $branchId);
        $nights = max(1, $checkIn->copy()->startOfDay()->diffInDays($checkOut->copy()->startOfDay()));

        return array_slice(array_map(fn (array $room): array => $room + [
            'starts_at' => $checkIn->toIso8601String(),
            'ends_at' => $checkOut->toIso8601String(),
            'nights' => $nights,
            'total_amount' => round($room['price_per_night'] * $nights, 2),
        ], $rooms), 0, self::OFFER_LIMIT);
    }
}
