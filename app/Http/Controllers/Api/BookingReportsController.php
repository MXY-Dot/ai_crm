<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ТЗ раздел 22 — салонные отчёты (записи/выручка/загруженность/предоплаты/
 * возвраты/популярные услуги/повторные клиенты), отдельно от общей CRM-
 * аналитики в app/Support/Analytics/* — та про диалоги/лиды/AI, а не про
 * операционные показатели самого календаря записей.
 *
 * "Загруженность специалистов" is reported as booked hours per specialist in
 * the period, not a percentage against theoretical schedule capacity -- a real,
 * honest number (available today from Booking rows alone) rather than a
 * fragile day-by-day schedule/time-off reconstruction across an arbitrary
 * date range.
 */
class BookingReportsController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [User::ROLE_SUPER_ADMIN, User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_ACCOUNTANT], true), 403);

        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
        ]);

        // Reads the ResolveTenant-middleware-resolved tenant, not $user->tenant_id
        // directly -- a super_admin's own tenant_id is null, so the raw-user version
        // threw "No query results for model [Company]" for every super_admin viewing
        // this report, same bug class as CancellationPolicyController.
        $company = Company::withoutGlobalScopes()->where('tenant_id', $context->id())->firstOrFail();
        $from = Carbon::parse($data['date_from'])->startOfDay();
        $to = Carbon::parse($data['date_to'])->endOfDay();

        $bookings = Booking::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereBetween('starts_at', [$from, $to])
            ->get(['id', 'customer_id', 'service_id', 'employee_id', 'status', 'price', 'prepayment_amount', 'prepayment_status', 'reschedule_count', 'starts_at', 'ends_at']);

        $completedLike = [Booking::STATUS_CONFIRMED, Booking::STATUS_CLIENT_ARRIVED, Booking::STATUS_IN_PROGRESS, Booking::STATUS_COMPLETED];

        $counts = [
            'total' => $bookings->count(),
            'confirmed' => $bookings->whereIn('status', $completedLike)->count(),
            'completed' => $bookings->where('status', Booking::STATUS_COMPLETED)->count(),
            'cancelled' => $bookings->where('status', Booking::STATUS_CANCELLED)->count(),
            'no_show' => $bookings->where('status', Booking::STATUS_NO_SHOW)->count(),
            'reschedules' => (int) $bookings->sum('reschedule_count'),
        ];

        $money = [
            'revenue' => round((float) $bookings->where('status', Booking::STATUS_COMPLETED)->sum('price'), 2),
            'prepayments_received' => round((float) $bookings->where('prepayment_status', 'confirmed')->sum('prepayment_amount'), 2),
            'refunds' => round((float) $bookings->where('prepayment_status', 'refunded')->sum('prepayment_amount'), 2),
        ];

        $serviceNames = Service::withoutGlobalScopes()->where('company_id', $company->id)->pluck('name', 'id');
        $employeeNames = Employee::withoutGlobalScopes()->where('company_id', $company->id)->pluck('name', 'id');

        $popularServices = $bookings->groupBy('service_id')
            ->map(fn ($group, $serviceId) => [
                'service_id' => (int) $serviceId,
                'name' => $serviceNames[$serviceId] ?? '—',
                'count' => $group->count(),
                'revenue' => round((float) $group->where('status', Booking::STATUS_COMPLETED)->sum('price'), 2),
            ])
            ->sortByDesc('count')
            ->values()
            ->take(10);

        $employeeLoad = $bookings->whereIn('status', $completedLike)
            ->groupBy('employee_id')
            ->map(function ($group, $employeeId) use ($employeeNames) {
                $minutes = $group->sum(fn (Booking $b) => $b->starts_at->diffInMinutes($b->ends_at));

                return [
                    'employee_id' => (int) $employeeId,
                    'name' => $employeeNames[$employeeId] ?? '—',
                    'bookings' => $group->count(),
                    'hours' => round($minutes / 60, 1),
                ];
            })
            ->sortByDesc('hours')
            ->values();

        $repeatCustomers = $bookings->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->filter(fn ($group) => $group->count() > 1)
            ->count();

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'counts' => $counts,
            'money' => $money,
            'popular_services' => $popularServices,
            'employee_load' => $employeeLoad,
            'repeat_customers' => $repeatCustomers,
        ]);
    }
}
