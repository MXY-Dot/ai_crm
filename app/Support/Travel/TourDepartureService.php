<?php

namespace App\Support\Travel;

use App\Models\TourDeparture;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ТЗ раздел 9 (Туристическая компания) — create/update for tour departures.
 * Deliberately the lightest write-service in this session: no schedule to
 * validate and no double-booking guard (a departure doesn't compete for a
 * shared teacher/room the way CourseGroup does) -- just a plain
 * DB::transaction()-wrapped create/edit, row-locked only so a concurrent
 * capacity edit can't race a booking (see TourBookingService::book()).
 */
class TourDepartureService
{
    /**
     * @param array{tenant_id?:int, company_id:int, branch_id?:?int, tour_id:int, departure_date:string, return_date?:?string, capacity?:?int, price?:?float, notes?:?string} $data
     */
    public function create(array $data, ?User $actor): TourDeparture
    {
        $tenantId = $actor?->tenant_id ?? $data['tenant_id'] ?? null;

        return TourDeparture::create([
            'tenant_id' => $tenantId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'] ?? null,
            'tour_id' => $data['tour_id'],
            'departure_date' => $data['departure_date'],
            'return_date' => $data['return_date'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'price' => $data['price'] ?? null,
            'status' => TourDeparture::STATUS_OPEN,
            'notes' => $data['notes'] ?? null,
            'created_by_user_id' => $actor?->id,
        ]);
    }

    /** General edit, including status -- a departure's fields are administrative settings changed together, same reasoning as CourseGroupService::update(). */
    public function update(TourDeparture $departure, array $data): TourDeparture
    {
        return DB::transaction(function () use ($departure, $data) {
            DB::table('tour_departures')->where('id', $departure->id)->lockForUpdate()->first();

            $departure->update(array_intersect_key($data, array_flip([
                'departure_date', 'return_date', 'capacity', 'price', 'status', 'notes',
            ])));

            return $departure->refresh();
        });
    }
}
