<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageLog;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Messaging\MessageLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Super-admin oversight of every outbound email/Telegram send, per company
 * -- an audit trail (who/what/when) for exactly the "financial or
 * misunderstanding problems" scenario the user asked to prevent, plus a
 * real per-company kill switch for each channel (see MessageLogger).
 */
class SuperAdminMessagingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MessageLog::query()->with('tenant:id,name');

        if ($tenantId = $request->query('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        if ($channel = $request->query('channel')) {
            $query->where('channel', $channel);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q->where('recipient', 'like', "%{$search}%")->orWhere('subject', 'like', "%{$search}%"));
        }

        $logs = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        return response()->json([
            'data' => collect($logs->items())->map(fn (MessageLog $log) => [
                'id' => $log->id,
                'tenant' => $log->tenant?->only(['id', 'name']),
                'channel' => $log->channel,
                'recipient' => $log->recipient,
                'subject' => $log->subject,
                'status' => $log->status,
                'error' => $log->error,
                'created_at' => $log->created_at,
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function companies(): JsonResponse
    {
        $tenants = Tenant::query()->orderBy('name')->get(['id', 'name', 'settings']);

        return response()->json($tenants->map(fn (Tenant $tenant) => [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'email_enabled' => (bool) Arr::get($tenant->settings ?? [], 'messaging.email_enabled', true),
            'telegram_enabled' => (bool) Arr::get($tenant->settings ?? [], 'messaging.telegram_enabled', true),
        ]));
    }

    public function toggleChannel(Request $request, Tenant $tenant, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['email', 'telegram'])],
            'enabled' => ['required', 'boolean'],
        ]);

        MessageLogger::setChannelEnabled($tenant, $data['channel'], $data['enabled']);

        $audit->record('tenant.messaging_toggled', $tenant, $data, [], $request, tenantId: $tenant->id);

        return response()->json(['ok' => true]);
    }
}
