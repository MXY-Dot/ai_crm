<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class SuperAdminOverviewController extends Controller
{
    private const PLAN_PRICES = ['starter' => 29, 'pro' => 99, 'business' => 249];

    private const MONTHS_RU = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];

    public function index(): JsonResponse
    {
        $tenants = Tenant::query()->get(['id', 'name', 'status', 'settings', 'created_at', 'trial_ends_at']);

        $activeTenants = $tenants->where('status', 'active');
        $mrr = (int) $activeTenants->sum(fn (Tenant $t) => self::PLAN_PRICES[Arr::get($t->settings, 'billing.plan', 'starter')] ?? 0);

        $growth = collect(range(5, 0))->map(function (int $monthsAgo) use ($tenants) {
            $month = now()->subMonths($monthsAgo)->startOfMonth();
            $count = $tenants->filter(fn (Tenant $t) => $t->created_at->isSameMonth($month))->count();

            return ['label' => self::MONTHS_RU[$month->month - 1], 'count' => $count];
        })->values();

        $messageVolume = Message::withoutGlobalScopes()
            ->where('sent_at', '>=', now()->subDays(30))
            ->selectRaw('sender_type, count(*) as count')
            ->groupBy('sender_type')
            ->pluck('count', 'sender_type');

        $channels = Channel::withoutGlobalScopes()
            ->selectRaw("provider, count(*) as total, sum(case when status = 'connected' then 1 else 0 end) as active")
            ->groupBy('provider')
            ->get()
            ->map(fn ($row) => ['provider' => $row->provider, 'total' => (int) $row->total, 'active' => (int) $row->active]);

        $failedDocuments = KnowledgeDocument::withoutGlobalScopes()->where('status', 'failed')->count();
        $blockedTenants = $tenants->where('status', 'blocked')->count();
        $trialsExpiringSoon = $tenants->where('status', 'trial')
            ->filter(fn (Tenant $t) => $t->trial_ends_at && $t->trial_ends_at->isFuture() && $t->trial_ends_at->diffInDays(now()) <= 3);

        $alerts = [];
        foreach ($trialsExpiringSoon as $tenant) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => 'Пробный период скоро закончится',
                'message' => "{$tenant->name} — доступ до ".$tenant->trial_ends_at->format('d.m.Y'),
            ];
        }
        if ($failedDocuments > 0) {
            $alerts[] = [
                'severity' => 'error',
                'title' => 'Ошибки индексации знаний',
                'message' => "{$failedDocuments} ".self::pluralDocs($failedDocuments).' не проиндексированы на платформе',
            ];
        }
        if ($blockedTenants > 0) {
            $alerts[] = [
                'severity' => 'info',
                'title' => 'Заблокированные компании',
                'message' => "{$blockedTenants} ".self::pluralCompanies($blockedTenants).' заблокировано',
            ];
        }

        $recentSignups = Tenant::query()
            ->with(['companies' => fn ($q) => $q->limit(1)])
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Tenant $t) => [
                'id' => $t->id,
                'name' => $t->companies->first()?->name ?? $t->name,
                'plan' => Arr::get($t->settings, 'billing.plan', 'starter'),
                'status' => $t->status,
                'created_at' => $t->created_at,
            ]);

        return response()->json([
            'kpis' => [
                'total_companies' => $tenants->count(),
                'active_companies' => $activeTenants->count(),
                'mrr' => $mrr,
                'arr' => $mrr * 12,
            ],
            'growth' => $growth,
            'message_volume' => [
                'ai' => (int) ($messageVolume['ai'] ?? 0),
                'operator' => (int) ($messageVolume['operator'] ?? 0),
                'customer' => (int) ($messageVolume['customer'] ?? 0),
            ],
            'channels' => $channels,
            'alerts' => $alerts,
            'recent_signups' => $recentSignups,
        ]);
    }

    private static function pluralDocs(int $count): string
    {
        return $count === 1 ? 'документ' : ($count < 5 ? 'документа' : 'документов');
    }

    private static function pluralCompanies(int $count): string
    {
        return $count === 1 ? 'компания' : ($count < 5 ? 'компании' : 'компаний');
    }
}
