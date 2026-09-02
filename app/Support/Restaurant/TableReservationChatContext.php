<?php

namespace App\Support\Restaurant;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Resource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ТЗ раздел 9/12 — "бронь столика через AI-чат". Gate + real-data context
 * shared by TableReservationChatAssistant and (via promptSection()) both AI
 * reply engines' prompts, mirroring App\Support\Booking\BookingChatContext's
 * exact shape and the same reasoning for why every query here is explicit
 * company_id-filtered rather than relying on the BelongsToTenant global
 * scope: AiWorkflow runs inside ProcessAiReplyJob, a queued job with no
 * request-scoped TenantContext set, so the global scope would silently
 * no-op there. Filtering by `company_id` (a specific, already-resolved row
 * id, never reused across tenants) is safe regardless -- same reasoning
 * TableAvailabilityCalculator::slotsForDay() (reused as-is below, no
 * changes needed) already relies on.
 */
class TableReservationChatContext
{
    private const SEARCH_DAYS = 7;

    private const OFFER_LIMIT = 3;

    public function __construct(private readonly TableAvailabilityCalculator $calculator)
    {
    }

    /** table_reservations module enabled AND at least one active table actually exists -- a toggled-on module with no real tables configured yet should not activate this. */
    public function isAvailableFor(Company $company): bool
    {
        $moduleEnabled = CompanyModule::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('module_key', 'table_reservations')
            ->where('enabled', true)
            ->exists();

        if (! $moduleEnabled) {
            return false;
        }

        return $this->activeTables($company)->isNotEmpty();
    }

    /** @return Collection<int, Resource> */
    public function activeTables(Company $company): Collection
    {
        return Resource::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('type', 'table')
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    /** Injected into DifyClient::businessProfile() alongside BookingChatContext's own section -- deliberately never mentions a specific free time itself, only that booking is possible and the largest table's capacity, same "never let the model invent a slot" discipline as BookingChatContext::promptSection(). */
    public function promptSection(Company $company): string
    {
        $tables = $this->activeTables($company);

        if ($tables->isEmpty()) {
            return '';
        }

        $maxCapacity = (int) $tables->max('capacity');

        return "Бронирование столиков доступно прямо в этом чате. Самый большой столик рассчитан максимум на {$maxCapacity} гостей."
            ."\nЕсли клиент хочет забронировать столик — уточни количество гостей и удобный день/время, если это ещё не ясно. Никогда не называй клиенту конкретное свободное время сам — реальные свободные столики подбирает отдельная система и предложит их следующим сообщением.";
    }

    /**
     * Real free tables for $partySize starting from $from, scanning forward
     * up to SEARCH_DAYS calendar days, capped at OFFER_LIMIT results. Mirrors
     * BookingChatContext::nextAvailableSlots()'s own day-stepping loop.
     *
     * @return array<int, array{resource_id:int, resource_name:string, capacity:int, starts_at:string, ends_at:string}>
     */
    public function nextAvailableSlots(Company $company, int $partySize, Carbon $from): array
    {
        $timezone = $company->timezone ?: config('app.timezone');
        $cursor = $from->copy();
        $slots = [];

        for ($i = 0; $i < self::SEARCH_DAYS && count($slots) < self::OFFER_LIMIT; $i++) {
            foreach ($this->calculator->slotsForDay($company, $cursor->copy(), $partySize, null, $timezone) as $slot) {
                $slots[] = $slot;

                if (count($slots) >= self::OFFER_LIMIT) {
                    break;
                }
            }

            $cursor->addDay();
        }

        return $slots;
    }
}
