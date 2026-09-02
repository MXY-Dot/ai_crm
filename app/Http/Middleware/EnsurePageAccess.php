<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Support\Authorization\RolePages;
use App\Support\Dashboard\DashboardData;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsurePageAccess
{
    /**
     * Route name => the ModuleRegistry key that gates that whole settings
     * page. Company owners toggle these off in /settings when a module
     * doesn't apply to their business -- visiting the page directly by URL
     * after that shouldn't still work, it should 404 like the page never
     * existed (see ModuleRegistry for the full module catalog).
     */
    private const MODULE_ROUTES = [
        'booking-settings' => 'booking_calendar',
        'orders' => 'orders',
        'catalog-settings' => 'catalog_products',
        'restaurant-settings' => 'table_reservations',
        'hotel-settings' => 'room_booking',
        'auto-service-settings' => 'vehicle_service',
        'education-settings' => 'course_scheduling',
        'travel-settings' => 'tour_bookings',
        'logistics-settings' => 'shipment_tracking',
    ];

    public function __construct(private readonly DashboardData $dashboard)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $page = $request->route()?->getName();

        if ($user && $page && ! RolePages::allowed($user->role, $page)) {
            return redirect()->route('dashboard');
        }

        $moduleKey = $page ? self::MODULE_ROUTES[$page] ?? null : null;

        if ($user && $moduleKey && ! $this->moduleEnabled($user, $moduleKey)) {
            return Inertia::render('NotFoundPage')->toResponse($request)->setStatusCode(404);
        }

        return $next($request);
    }

    private function moduleEnabled($user, string $moduleKey): bool
    {
        $tenant = $this->dashboard->tenantFor($user);
        $companyId = $tenant ? Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id') : null;

        if (! $companyId) {
            return false;
        }

        return CompanyModule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('module_key', $moduleKey)
            ->where('enabled', true)
            ->exists();
    }
}
