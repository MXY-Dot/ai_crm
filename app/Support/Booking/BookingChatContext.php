<?php

namespace App\Support\Booking;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Service;
use App\Support\Business\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ТЗ раздел 12 — "запись через AI-чат". Gate + real-data context shared by
 * AiChatBookingAssistant and (via businessProfile()) both AI reply engines'
 * prompts. Every query here is explicit tenant_id/company_id-filtered
 * withoutGlobalScopes() rather than relying on the BelongsToTenant global
 * scope — AiWorkflow runs inside ProcessAiReplyJob, a queued job with no
 * request-scoped TenantContext set (confirmed: neither the job nor
 * AiWorkflow itself ever calls TenantContext::set()), so the global scope
 * would silently no-op there.
 */
class BookingChatContext
{
    private const SEARCH_DAYS = 10;

    private const OFFER_LIMIT = 3;

    public function __construct(private readonly AvailabilityCalculator $calculator)
    {
    }

    /** booking_calendar module enabled AND at least one active service actually has an active employee who can perform it -- a toggled-on module with no real data configured yet should not activate this. */
    public function isAvailableFor(Company $company): bool
    {
        $moduleEnabled = CompanyModule::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('module_key', 'booking_calendar')
            ->where('enabled', true)
            ->exists();

        if (! $moduleEnabled) {
            return false;
        }

        return $this->activeServices($company)->isNotEmpty();
    }

    /** @return Collection<int, Service> */
    public function activeServices(Company $company): Collection
    {
        return Service::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->whereHas('employees', fn ($q) => $q->where('employees.is_active', true))
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    /** Injected into DifyClient::businessProfile() -- the one shared prompt-building point both the direct-LLM and Dify engines already read from, so real service names/prices reach the customer instead of the LLM guessing. Deliberately never mentions a specific time slot -- that only ever comes from nextAvailableSlots() below, never from the model's own text. */
    public function promptSection(Company $company): string
    {
        $services = $this->activeServices($company);

        if ($services->isEmpty()) {
            return '';
        }

        $lines = $services->map(fn (Service $service): string => sprintf(
            '- %s (%d мин, %s)',
            $service->name,
            $service->duration_minutes,
            Currency::format($service->price, $company->currency),
        ));

        return "Онлайн-запись доступна прямо в этом чате. Список услуг (используй ТОЛЬКО эти названия, не выдумывай другие):\n"
            .$lines->implode("\n")
            ."\nЕсли клиент хочет записаться — уточни услугу и удобный день, если это ещё не ясно. Никогда не называй клиенту конкретное свободное время сам — реальные свободные окна подбирает отдельная система и предложит их следующим сообщением.";
    }

    /**
     * Real free slots for $service starting from $from, scanning forward up to SEARCH_DAYS
     * calendar days, capped at OFFER_LIMIT results. Each slot also carries service_id/
     * service_name so AiChatBookingAssistant can persist it in Message.meta and act on
     * a customer's pick next turn without re-resolving anything. $employeeId pins the
     * search to one specific employee -- used when rescheduling an existing booking, to
     * keep the same master rather than potentially reassigning the customer to whoever's
     * free; null (any eligible employee) is the right default for a brand-new booking.
     *
     * @return array<int, array{employee_id:int, employee_name:string, service_id:int, service_name:string, starts_at:string, ends_at:string}>
     */
    public function nextAvailableSlots(Company $company, Service $service, Carbon $from, ?int $employeeId = null): array
    {
        $timezone = $company->timezone ?: config('app.timezone');
        $cursor = $from->copy();
        $slots = [];

        for ($i = 0; $i < self::SEARCH_DAYS && count($slots) < self::OFFER_LIMIT; $i++) {
            foreach ($this->calculator->slotsForDay($service, $cursor->copy(), $employeeId, $timezone) as $slot) {
                $slots[] = $slot + ['service_id' => $service->id, 'service_name' => $service->name];

                if (count($slots) >= self::OFFER_LIMIT) {
                    break;
                }
            }

            $cursor->addDay();
        }

        return $slots;
    }
}
