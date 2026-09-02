<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\TourBooking;
use App\Support\Audit\AuditLogger;
use App\Support\Travel\TourBookingService;
use App\Support\Travel\TravelConflictException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TourBookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', TourBooking::class);

        $data = $request->validate(['tour_departure_id' => ['nullable', 'integer']]);

        $bookings = TourBooking::query()
            ->with(['customer:id,name,phone', 'tourDeparture:id,departure_date,tour_id'])
            ->when($data['tour_departure_id'] ?? null, fn ($q, $departureId) => $q->where('tour_departure_id', $departureId))
            ->latest()
            ->paginate(50);

        return response()->json($bookings);
    }

    public function show(TourBooking $tourBooking): JsonResponse
    {
        Gate::authorize('view', $tourBooking);

        return response()->json($tourBooking->load([
            'customer:id,name,phone,email',
            'tourDeparture:id,departure_date,tour_id',
            'statusHistory.changedBy:id,name',
            'orders:id,tour_booking_id,status,payment_status,total',
        ]));
    }

    public function store(Request $request, TourBookingService $bookings, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', TourBooking::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'tour_departure_id' => ['required', 'integer', 'exists:tour_departures,id'],
            'pax_count' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:180'],
            'customer_phone' => ['required_without:customer_id', 'nullable', 'string', 'max:40'],
        ]);

        $customerId = $data['customer_id'] ?? null;
        if (! $customerId) {
            $customer = Customer::query()
                ->where('company_id', $data['company_id'])
                ->where('phone', $data['customer_phone'])
                ->first();

            $customer ??= Customer::create([
                'company_id' => $data['company_id'],
                'name' => $data['customer_name'],
                'phone' => $data['customer_phone'],
                'source' => 'travel',
            ]);

            $customerId = $customer->id;
        }

        try {
            $booking = $bookings->book([...$data, 'customer_id' => $customerId], $request->user());
        } catch (TravelConflictException $e) {
            throw ValidationException::withMessages(['tour_departure_id' => $e->getMessage()]);
        }

        $audit->record('tour_booking.created', $booking, $booking->toArray(), [], $request);

        return response()->json($booking->load('customer:id,name,phone'), 201);
    }

    public function confirm(Request $request, TourBooking $tourBooking, TourBookingService $bookings, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $tourBooking);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);
        $before = $tourBooking->toArray();

        try {
            $tourBooking = $bookings->updateStatus($tourBooking, TourBooking::STATUS_CONFIRMED, $request->user(), $data['comment'] ?? null);
        } catch (TravelConflictException $e) {
            throw ValidationException::withMessages(['booking' => $e->getMessage()]);
        }

        $audit->record('tour_booking.confirmed', $tourBooking, $tourBooking->toArray(), $before, $request);

        return response()->json($tourBooking);
    }

    public function complete(Request $request, TourBooking $tourBooking, TourBookingService $bookings, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $tourBooking);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);
        $before = $tourBooking->toArray();

        try {
            $tourBooking = $bookings->updateStatus($tourBooking, TourBooking::STATUS_COMPLETED, $request->user(), $data['comment'] ?? null);
        } catch (TravelConflictException $e) {
            throw ValidationException::withMessages(['booking' => $e->getMessage()]);
        }

        $audit->record('tour_booking.completed', $tourBooking, $tourBooking->toArray(), $before, $request);

        return response()->json($tourBooking);
    }

    public function cancel(Request $request, TourBooking $tourBooking, TourBookingService $bookings, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $tourBooking);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $before = $tourBooking->toArray();

        try {
            $tourBooking = $bookings->cancel($tourBooking, $request->user(), $data['reason']);
        } catch (TravelConflictException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        $audit->record('tour_booking.cancelled', $tourBooking, $tourBooking->toArray(), $before, $request);

        return response()->json($tourBooking);
    }
}
