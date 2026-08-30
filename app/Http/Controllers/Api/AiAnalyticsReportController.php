<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAnalyticsReport;
use App\Support\Analytics\AiReportGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAnalyticsReportController extends Controller
{
    public function index(): JsonResponse
    {
        $reports = AiAnalyticsReport::query()
            ->orderByDesc('period_end')
            ->limit(20)
            ->get(['id', 'period_type', 'period_start', 'period_end', 'content', 'generated_by', 'created_at']);

        return response()->json(['data' => $reports]);
    }

    /** Manual "Сформировать сейчас" button — same generator the scheduled command uses, just for the currently-active tenant and synchronous (an LLM call is a few seconds, acceptable for an explicit button click). */
    public function generate(Request $request, AiReportGenerator $generator, TenantContext $context): JsonResponse
    {
        $data = $request->validate(['type' => 'required|in:weekly,monthly']);

        $tenant = $context->tenant();
        abort_unless($tenant, 422, 'Tenant context is required.');

        $report = $generator->generateForTenant($tenant, $data['type']);

        return response()->json(['data' => $report]);
    }
}
