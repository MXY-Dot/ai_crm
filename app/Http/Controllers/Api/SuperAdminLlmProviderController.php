<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiRun;
use App\Models\Tenant;
use App\Support\Ai\LlmClient;
use App\Support\Audit\AuditLogger;
use App\Support\Integrations\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * All 5 direct LLM providers are platform-managed here (see PlatformSettings) —
 * tenants never bring their own key, they just pick a model their plan allows
 * (AiWorkflow::PLAN_PROVIDERS) and WERO's own account is billed. Dify is shown
 * read-only (status only, its own .env-managed key, untouched by this screen).
 *
 * Every provider card also carries its own 30-day usage stats and a 14-day daily
 * request trend, so the admin can see spend/tokens without leaving WERO to check
 * each provider's own dashboard (an "Открыть сайт" link is offered per card too,
 * for whenever they do want the real thing).
 */
class SuperAdminLlmProviderController extends Controller
{
    private const LLM_PROVIDERS = [
        'groq' => 'Groq (GPT-OSS-120B)',
        'openai' => 'GPT (OpenAI)',
        'anthropic' => 'Claude (Anthropic)',
        'google' => 'Gemini (Google)',
        'deepseek' => 'DeepSeek',
    ];

    /** Each provider's real dashboard, for the optional "Открыть сайт" card button. */
    private const PROVIDER_URLS = [
        'groq' => 'https://console.groq.com/keys',
        'openai' => 'https://platform.openai.com/usage',
        'anthropic' => 'https://console.anthropic.com/settings/billing',
        'google' => 'https://aistudio.google.com/',
        'deepseek' => 'https://platform.deepseek.com/usage',
    ];

    public function __construct(
        private readonly LlmClient $llm,
        private readonly PlatformSettings $platform,
    ) {
    }

    public function index(): JsonResponse
    {
        $rows = AiRun::withoutGlobalScopes()
            ->whereNotNull('provider')
            ->where('created_at', '>=', now()->subDays(30))
            ->get(['tenant_id', 'provider', 'tokens_in', 'tokens_out', 'cost_usd', 'created_at']);

        $providers = collect(self::LLM_PROVIDERS)->map(function (string $label, string $provider) use ($rows): array {
            $key = $this->platform->llmApiKey($provider);

            return [
                'provider' => $provider,
                'label' => $label,
                'configured' => $key !== '',
                'key_mask' => $this->platform->mask($key),
                'external_url' => self::PROVIDER_URLS[$provider] ?? null,
                'stats' => $this->providerStats($rows->where('provider', $provider)),
            ];
        })->values();

        $difyKey = (string) config('services.dify.api_key', '');

        return response()->json([
            'providers' => $providers,
            'dify' => ['configured' => $difyKey !== '', 'key_mask' => $this->platform->mask($difyKey)],
            'primary_provider' => $this->platform->primaryLlmProvider(),
            'backup_provider' => $this->platform->backupLlmProvider(),
            'usage' => [
                'top_tenants' => $this->topTenants($rows),
                'requests_this_month' => $rows->where('created_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    public function updateKey(Request $request, string $provider, AuditLogger $audit): JsonResponse
    {
        $this->validateProvider($provider);

        $data = $request->validate(['api_key' => ['required', 'string', 'max:255']]);

        $this->platform->setLlmApiKey($provider, $data['api_key']);
        // Never logs the raw key — only its mask, same as the response below.
        $audit->record('platform_llm_key.updated', 'PlatformSettings', ['provider' => $provider, 'key_mask' => $this->platform->mask($data['api_key'])], [], $request);

        return response()->json(['ok' => true, 'provider' => $provider, 'key_mask' => $this->platform->mask($data['api_key'])]);
    }

    public function test(string $provider): JsonResponse
    {
        $this->validateProvider($provider);

        $key = $this->platform->llmApiKey($provider);

        if ($key === '') {
            return response()->json(['ok' => false, 'provider' => $provider, 'message' => 'Укажите API-ключ.'], 422);
        }

        try {
            $models = $this->llm->listModels(null, $provider, $key);
        } catch (Throwable $error) {
            return response()->json(['ok' => false, 'provider' => $provider, 'message' => $error->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'provider' => $provider, 'models' => $models]);
    }

    public function updatePrimary(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'primary_provider' => ['required', Rule::in(array_keys(self::LLM_PROVIDERS))],
            'backup_provider' => ['nullable', Rule::in(array_keys(self::LLM_PROVIDERS))],
        ]);

        $previous = ['primary_provider' => $this->platform->primaryLlmProvider(), 'backup_provider' => $this->platform->backupLlmProvider()];
        $this->platform->setPrimaryLlmProvider($data['primary_provider']);
        $this->platform->setBackupLlmProvider($data['backup_provider'] ?? null);
        $audit->record('platform_llm_provider.updated', 'PlatformSettings', $data, $previous, $request);

        return response()->json([
            'ok' => true,
            'primary_provider' => $data['primary_provider'],
            'backup_provider' => $data['backup_provider'] ?? null,
        ]);
    }

    private function validateProvider(string $provider): void
    {
        abort_unless(array_key_exists($provider, self::LLM_PROVIDERS), 404);
    }

    /** @param Collection<int, AiRun> $providerRows */
    private function providerStats(Collection $providerRows): array
    {
        $dailyStart = now()->subDays(13)->startOfDay();

        $daily = collect(range(0, 13))->map(function (int $offset) use ($providerRows, $dailyStart): array {
            $day = $dailyStart->copy()->addDays($offset);
            $dayRows = $providerRows->filter(fn (AiRun $run): bool => $run->created_at->isSameDay($day));

            return ['date' => $day->toDateString(), 'label' => $day->format('d.m'), 'requests' => $dayRows->count()];
        })->values();

        return [
            'requests_30d' => $providerRows->count(),
            'tokens_in_30d' => (int) $providerRows->sum('tokens_in'),
            'tokens_out_30d' => (int) $providerRows->sum('tokens_out'),
            'cost_usd_30d' => round((float) $providerRows->sum('cost_usd'), 4),
            'daily' => $daily,
        ];
    }

    /** @param Collection<int, AiRun> $rows */
    private function topTenants(Collection $rows): array
    {
        $topTenants = $rows->groupBy('tenant_id')->map(fn ($group, $tenantId): array => [
            'tenant_id' => (int) $tenantId,
            'requests_30d' => $group->count(),
            'cost_usd_30d' => round((float) $group->sum('cost_usd'), 4),
        ])->sortByDesc('requests_30d')->take(5)->values();

        $tenantNames = Tenant::query()->whereIn('id', $topTenants->pluck('tenant_id'))->pluck('name', 'id');

        return $topTenants->map(function (array $row) use ($tenantNames): array {
            $row['name'] = $tenantNames[$row['tenant_id']] ?? ('#'.$row['tenant_id']);

            return $row;
        })->values()->all();
    }
}
