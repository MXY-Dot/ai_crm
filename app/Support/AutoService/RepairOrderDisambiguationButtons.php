<?php

namespace App\Support\AutoService;

/**
 * Real tap buttons for RepairOrderChatAssistant's own "which vehicle/which
 * order do you mean" disambiguation prompts -- see
 * App\Support\Booking\BookingOfferButtons's own docblock for why this stays
 * a separate file per module. Unlike the other 5 reservation-shaped
 * modules, RepairOrder has no "here are N new open slots" offer at all (a
 * repair shop doesn't pre-register bookable time slots the way a
 * salon/restaurant/hotel does), only these two disambiguation shapes -- so
 * there's no companion *OfferButtons::build() in this file, just these two.
 */
class RepairOrderDisambiguationButtons
{
    /**
     * @param array<int, array{make:string, model:string, plate_number:string}> $vehicles
     * @return array<int, array{id:string, title:string, description?:string}>
     */
    public static function forVehicles(array $vehicles): array
    {
        return collect($vehicles)->map(fn (array $v, int $i): array => [
            'id' => (string) ($i + 1),
            'title' => mb_strimwidth($v['make'].' '.$v['model'], 0, 24, '…'),
            'description' => $v['plate_number'],
        ])->values()->all();
    }

    /**
     * @param array<int, array{label:string}> $orders
     * @return array<int, array{id:string, title:string}>
     */
    public static function forOrders(array $orders): array
    {
        return collect($orders)->map(fn (array $o, int $i): array => [
            'id' => (string) ($i + 1),
            'title' => mb_strimwidth($o['label'], 0, 24, '…'),
        ])->values()->all();
    }
}
