<?php

namespace App\Support\Logistics;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Shipment;

/**
 * ТЗ раздел 9/12 — "отслеживание отправления через AI-чат". Gate + prompt-
 * injection shared by LogisticsChatAssistant and (via promptSection())
 * DifyClient::businessProfile(), mirroring the shape every other module's
 * own ChatContext already uses. Genuinely the simplest of the six chat
 * assistants this session: a shipment isn't a catalog offering (no Course/
 * Tour/Service-equivalent to match by name) and `customer_id` is OPTIONAL
 * on Shipment itself (see its own docblock -- "a one-time sender is the
 * common case for a courier"), so there's no "list the customer's own
 * shipments" collection to build the way every other module's context
 * does. The tracking number IS the whole lookup key, same trust model
 * TrackShipmentController's own docblock already establishes for the
 * public tracking page -- see findForTracking()'s own docblock for why
 * that lookup is deliberately GLOBAL (matching the public endpoint) while
 * findForCancel() is deliberately scoped to this company only.
 */
class LogisticsChatContext
{
    /** shipment_tracking module enabled -- no "at least one active X" check makes sense here, a shipment isn't pre-registered inventory the way a table/room/course/tour is. */
    public function isAvailableFor(Company $company): bool
    {
        return CompanyModule::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('module_key', 'shipment_tracking')
            ->where('enabled', true)
            ->exists();
    }

    /** Injected into DifyClient::businessProfile() alongside every other enabled module's own section. Deliberately never invents a tracking status itself -- only findForTracking() below ever produces one. */
    public function promptSection(Company $company): string
    {
        if (! $this->isAvailableFor($company)) {
            return '';
        }

        return "Отслеживание отправлений по трек-номеру (формата WERO-XXXXXXXX) доступно прямо в этом чате. Если клиент спрашивает про доставку/отправление — уточни трек-номер, если он его ещё не назвал. Никогда не называй клиенту статус отправления или дату доставки сам — это подбирает отдельная система.";
    }

    /**
     * Same GLOBAL (no tenant/company scope), same field disclosure as
     * TrackShipmentController's own public endpoint -- "the tracking number
     * itself is the credential", identical trust model, this is just a
     * conversational front-end to the same public capability, not a new
     * privilege boundary. Deliberately does NOT expose sender/recipient
     * name/phone/price/notes, same as that controller's own docblock.
     *
     * Real bug found live testing (2026-09-03), same one existed in
     * TrackShipmentController's own already-shipped code and fixed
     * identically there: an eager-load constraint closure
     * (`fn ($q) => $q->oldest('id')`) does NOT override a relation's own
     * baked-in ordering -- Shipment::trackingEvents() is defined with
     * ->latest('id') (matching every other status-history relation's
     * convention this session), and Eloquent ADDS the closure's ORDER BY
     * rather than replacing it, so the relation's own DESC wins and the
     * events came back newest-first despite the closure asking for
     * oldest-first. Fixed by re-sorting the already-loaded collection in
     * PHP instead of fighting SQL ordering precedence.
     */
    public function findForTracking(string $trackingNumber): ?Shipment
    {
        $shipment = Shipment::withoutGlobalScopes()
            ->where('tracking_number', $trackingNumber)
            ->with('trackingEvents')
            ->first();

        if ($shipment) {
            $shipment->setRelation('trackingEvents', $shipment->trackingEvents->sortBy('id')->values());
        }

        return $shipment;
    }

    /**
     * Unlike tracking (read-only, globally trusted by number alone),
     * cancelling is a real mutation -- scoped to THIS company only, so a
     * tracking number from a different tenant can never be cancelled
     * through a chat bot that isn't actually that shipment's own company.
     * The service layer itself has no ownership check (see
     * ShipmentService's own docblock, "lightest write-service"), so this
     * scoping is the only thing enforcing it.
     */
    public function findForCancel(Company $company, string $trackingNumber): ?Shipment
    {
        return Shipment::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('tracking_number', $trackingNumber)
            ->first();
    }
}
