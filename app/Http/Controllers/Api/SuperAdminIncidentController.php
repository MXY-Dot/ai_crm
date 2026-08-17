<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HealthComponent;
use App\Models\Incident;
use App\Support\Emergency\HealthMonitor;
use Illuminate\Http\JsonResponse;

/**
 * Platform-wide Incident Dashboard (ЭТАП 16.13/16.14) — 🟢/🔴 per platform
 * component (5 LLM providers, db, queue) plus a rolled-up Dify row, since Dify is
 * per-tenant BYOK rather than one platform-wide status (see HealthMonitor's
 * docblock). Backed entirely by health_components/incidents — no separate
 * "monitoring" system, this table IS the monitoring state.
 */
class SuperAdminIncidentController extends Controller
{
    public function __construct(private readonly HealthMonitor $health)
    {
    }

    public function index(): JsonResponse
    {
        $platformComponents = $this->health->snapshot()
            ->whereNull('tenant_id')
            ->map(fn (HealthComponent $row): array => [
                'component' => $row->component,
                'status' => $row->status,
                'consecutive_failures' => $row->consecutive_failures,
                'last_failure_at' => $row->last_failure_at,
                'last_success_at' => $row->last_success_at,
                'last_error' => $row->last_error,
            ])
            ->values();

        $difyRows = HealthComponent::query()->where('component', 'like', 'dify:%')->get();

        $difyRollup = [
            'component' => 'dify',
            'tenants_total' => $difyRows->count(),
            'tenants_down' => $difyRows->where('status', 'down')->count(),
        ];

        $incidents = Incident::query()
            ->with('tenant:id,name')
            ->orderByDesc('started_at')
            ->limit(50)
            ->get();

        return response()->json([
            'components' => $platformComponents,
            'dify' => $difyRollup,
            'incidents' => $incidents,
            'summary' => [
                'open_incidents' => Incident::query()->where('status', 'open')->count(),
                'components_down' => $platformComponents->where('status', 'down')->count() + ($difyRollup['tenants_down'] > 0 ? 1 : 0),
            ],
        ]);
    }

    public function show(Incident $incident): JsonResponse
    {
        return response()->json($incident->load('tenant:id,name'));
    }
}
