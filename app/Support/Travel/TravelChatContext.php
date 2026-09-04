<?php

namespace App\Support\Travel;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Tour;
use App\Models\TourBooking;
use App\Models\TourDeparture;
use Illuminate\Support\Collection;

/**
 * ТЗ раздел 9/12 — "заявка на тур через AI-чат". Gate + real-data context
 * shared by TravelChatAssistant and (via promptSection())
 * DifyClient::businessProfile(), mirroring EducationChatContext's own
 * shape closely: Tour plays the same role Course does (named catalog
 * offering matched from the customer's own words), TourDeparture plays
 * the same role a CourseGroup does (the real, bookable thing actually
 * offered) -- except a departure's own "capacity" is consumed in whole
 * pax_count chunks, not one seat per booking, so openDeparturesForTour()
 * below sums booked pax rather than counting rows, same arithmetic
 * TourBookingService::book() itself already uses.
 */
class TravelChatContext
{
    /** tour_bookings module enabled AND at least one active tour exists. */
    public function isAvailableFor(Company $company): bool
    {
        $moduleEnabled = CompanyModule::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('module_key', 'tour_bookings')
            ->where('enabled', true)
            ->exists();

        if (! $moduleEnabled) {
            return false;
        }

        return $this->activeTours($company)->isNotEmpty();
    }

    /** @return Collection<int, Tour> */
    public function activeTours(Company $company): Collection
    {
        return Tour::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    /** Injected into DifyClient::businessProfile() alongside every other enabled module's own section. Deliberately never mentions a specific departure date/price/seat count itself -- those only ever come from openDeparturesForTour() below. */
    public function promptSection(Company $company): string
    {
        // Found live (same bug as TableReservationChatContext): this only checked
        // activeTours()->isEmpty(), never the tour_bookings module flag itself.
        // isAvailableFor() already covers both checks.
        if (! $this->isAvailableFor($company)) {
            return '';
        }

        $tours = $this->activeTours($company);

        $lines = $tours->map(fn (Tour $t): string => sprintf('- %s (%s, %s смн)', $t->name, $t->destination, number_format((float) $t->price, 0, ',', ' ')));

        return "Заявки на туры доступны прямо в этом чате. Список туров (используй ТОЛЬКО эти названия, не выдумывай другие):\n"
            .$lines->implode("\n")
            ."\nЕсли клиент хочет забронировать тур — уточни, какой тур и на сколько человек, если это ещё не ясно. Никогда не называй клиенту конкретные даты заезда или свободные места сам — это подбирает отдельная система и предложит следующим сообщением.";
    }

    /**
     * Real open departures (status still bookable AND enough free seats for
     * $paxCount) for $tour, each entry self-contained enough to persist in
     * Message.meta and act on next turn without re-resolving anything.
     *
     * @return array<int, array{departure_id:int, tour_name:string, departure_date:string, return_date:string, price:float, seats_remaining:?int}>
     */
    public function openDeparturesForTour(Company $company, Tour $tour, int $paxCount): array
    {
        $departures = TourDeparture::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('tour_id', $tour->id)
            ->whereIn('status', TourDeparture::BOOKABLE_STATUSES)
            ->withSum(['bookings as booked_pax' => fn ($q) => $q->whereIn('status', TourBooking::ACTIVE_STATUSES)], 'pax_count')
            ->orderBy('departure_date')
            ->get()
            ->filter(function (TourDeparture $d) use ($paxCount): bool {
                if ($d->capacity === null) {
                    return true;
                }

                $booked = (int) ($d->booked_pax ?? 0);

                return $d->capacity - $booked >= $paxCount;
            });

        return $departures->map(fn (TourDeparture $d): array => [
            'departure_id' => $d->id,
            'tour_name' => $tour->name,
            'departure_date' => $d->departure_date->toDateString(),
            'return_date' => $d->return_date->toDateString(),
            'price' => (float) ($d->price ?? $tour->price),
            'seats_remaining' => $d->capacity === null ? null : max(0, $d->capacity - (int) ($d->booked_pax ?? 0)),
        ])->values()->all();
    }
}
