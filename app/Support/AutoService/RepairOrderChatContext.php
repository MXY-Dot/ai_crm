<?php

namespace App\Support\AutoService;

use App\Models\Company;
use App\Models\CompanyModule;

/**
 * ТЗ раздел 9/12 — "запись на ремонт через AI-чат". Gate + prompt-injection
 * shared by RepairOrderChatAssistant and (via promptSection())
 * DifyClient::businessProfile(), mirroring the shape every other module's
 * own ChatContext already uses. Genuinely simpler than Booking/Table/Room's
 * own contexts in one respect: there is no AvailabilityCalculator here at
 * all (see RepairOrderService's own docblock -- a repair job has no time
 * slot, just a vehicle's own "one active job at a time" constraint), so
 * this class carries no nextAvailableSlots()-equivalent method -- the chat
 * assistant creates a RepairOrder directly rather than offering anything
 * for the customer to pick from.
 */
class RepairOrderChatContext
{
    /** vehicle_service module enabled -- unlike Booking/Table/Room, no "at least one active resource" check makes sense here: a repair shop doesn't pre-register customer vehicles, they're created on demand from whatever the customer describes in chat. */
    public function isAvailableFor(Company $company): bool
    {
        return CompanyModule::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('module_key', 'vehicle_service')
            ->where('enabled', true)
            ->exists();
    }

    /** Injected into DifyClient::businessProfile() alongside every other enabled module's own section. Deliberately never invents a price/completion date -- those are set by staff after real diagnosis, not knowable at intake time. */
    public function promptSection(Company $company): string
    {
        if (! $this->isAvailableFor($company)) {
            return '';
        }

        return "Приём автомобилей на ремонт доступен прямо в этом чате. Если клиент хочет записать машину на ремонт — уточни марку/модель машины, гос. номер и в чём проблема, если это ещё не ясно. Никогда не называй клиенту стоимость ремонта или дату готовности сам — это определяет мастер после диагностики.";
    }
}
