<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TourBooking;
use App\Models\TourDeparture;
use App\Support\Audit\AuditLogger;
use App\Support\Travel\TourDepartureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TourDepartureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', TourDeparture::class);

        $data = $request->validate([
            'tour_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(TourDeparture::STATUSES)],
        ]);

        $departures = TourDeparture::query()
            ->withSum(['bookings as booked_seats' => fn ($q) => $q->whereIn('status', TourBooking::ACTIVE_STATUSES)], 'pax_count')
            ->with('tour:id,name,price')
            ->when($data['tour_id'] ?? null, fn ($q, $tourId) => $q->where('tour_id', $tourId))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('departure_date')
            ->paginate(50);

        return response()->json($departures);
    }

    public function show(TourDeparture $tourDeparture): JsonResponse
    {
        Gate::authorize('view', $tourDeparture);

        return response()->json($tourDeparture->load([
            'tour:id,name,destination,price,duration_days',
            'createdBy:id,name',
            'bookings.customer:id,name,phone',
        ]));
    }

    public function store(Request $request, TourDepartureService $departures, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', TourDeparture::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'tour_id' => ['required', 'integer', 'exists:tours,id'],
            'departure_date' => ['required', 'date'],
            'return_date' => ['nullable', 'date', 'after:departure_date'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $departure = $departures->create($data, $request->user());
        $audit->record('tour_departure.created', $departure, $departure->toArray(), [], $request);

        return response()->json($departure->load('tour:id,name'), 201);
    }

    public function update(Request $request, TourDeparture $tourDeparture, TourDepartureService $departures, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $tourDeparture);

        $data = $request->validate([
            'departure_date' => ['sometimes', 'date'],
            'return_date' => ['nullable', 'date'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(TourDeparture::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = $tourDeparture->toArray();
        $tourDeparture = $departures->update($tourDeparture, $data);
        $audit->record('tour_departure.updated', $tourDeparture, $tourDeparture->toArray(), $before, $request);

        return response()->json($tourDeparture);
    }
}
