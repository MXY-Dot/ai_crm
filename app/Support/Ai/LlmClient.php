<?php

namespace App\Support\Ai;

use App\Models\Tenant;
use App\Support\Emergency\HealthMonitor;
use App\Support\Integrations\PlatformSettings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Talks directly to model provider APIs, bypassing the AI engine entirely.
 * This is what actually generates a reply when an AI agent's model belongs to
 * a provider the tenant has connected here — the AI engine's own API has no way
 * to force a specific model per request, so this is the only way "pick GPT vs
 * DeepSeek vs Claude vs Gemini vs Groq" genuinely changes what answers the customer.
 *
 * OpenAI, DeepSeek and Groq share the same request/response schema ("openai-compatible").
 * Anthropic (Claude) and Google (Gemini) each use their own distinct schema, so
 * they get their own request/response handling below.
 *
 * Every provider is platform-managed (see PlatformSettings): WERO holds one key
 * per provider, tenants never bring their own — they just pick a model their plan
 * allows (gated in AiWorkflow::PLAN_PROVIDERS) and WERO's account is billed.
 */
class LlmClient
{
    private const OPENAI_COMPATIBLE_BASE_URLS = [
        'openai' => 'https://api.openai.com/v1',
        'deepseek' => 'https://api.deepseek.com/v1',
        'groq' => 'https://api.groq.com/openai/v1',
    ];

    /**
     * Rough default USD price per 1M tokens (input/output), used only to estimate
     * cost_usd for usage tracking/billing. Approximate per-provider defaults, not
     * tied to the exact model tier a tenant picked — good enough for relative
     * cost/usage comparisons, not an invoice-grade figure.
     */
    private const PRICING_PER_MILLION_TOKENS = [
        'openai' => ['in' => 0.15, 'out' => 0.60],
        'deepseek' => ['in' => 0.14, 'out' => 0.28],
        'anthropic' => ['in' => 3.00, 'out' => 15.00],
        'google' => ['in' => 0.075, 'out' => 0.30],
        'groq' => ['in' => 0.15, 'out' => 0.75],
    ];

    public function __construct(
        private readonly PlatformSettings $platform,
        private readonly HealthMonitor $health,
    ) {
    }

    public function providerForModel(string $model): ?string
    {
        return match (true) {
            str_starts_with($model, 'gpt-') || str_starts_with($model, 'o1-') || str_starts_with($model, 'o3-') => 'openai',
            str_starts_with($model, 'deepseek-') => 'deepseek',
            str_starts_with($model, 'claude-') => 'anthropic',
            str_starts_with($model, 'gemini-') => 'google',
            str_contains($model, 'gpt-oss') || str_starts_with($model, 'llama-') || str_starts_with($model, 'llama3') || str_starts_with($model, 'gemma') || str_starts_with($model, 'mixtral') => 'groq',
            default => null,
        };
    }

    /**
     * $tenant is unused (kept for call-site stability) — every provider's key comes
     * from PlatformSettings now, not from the tenant.
     */
    public function apiKey(?Tenant $tenant, string $provider): string
    {
        return $this->platform->llmApiKey($provider);
    }

    /**
     * $tenant may be null only when $apiKeyOverride is given (e.g. Super Admin
     * listing models for the platform-managed Groq key, which isn't tenant-scoped).
     */
    public function listModels(?Tenant $tenant, string $provider, ?string $apiKeyOverride = null): array
    {
        $apiKey = $apiKeyOverride !== null && trim($apiKeyOverride) !== ''
            ? $apiKeyOverride
            : ($tenant ? $this->apiKey($tenant, $provider) : '');

        if ($apiKey === '') {
            throw new RuntimeException('API key is required.');
        }

        return match ($provider) {
            'openai', 'deepseek', 'groq' => $this->listOpenAiCompatibleModels($provider, $apiKey),
            'anthropic' => $this->listAnthropicModels($apiKey),
            'google' => $this->listGoogleModels($apiKey),
            default => throw new RuntimeException('Unknown provider.'),
        };
    }

    /**
     * Speech-to-text for an incoming customer voice message, so the AI can actually
     * understand and answer it instead of just acknowledging "I got a voice note".
     * Always via Groq's Whisper endpoint specifically — cheap, fast, and Groq is
     * already the platform's established audio-capable provider (DeepSeek/OpenAI-
     * text/Anthropic/Google don't factor in here regardless of which one a given
     * tenant's chat model uses). Auto-detects language, no explicit `language` param
     * — customers write in Russian/Tajik/English interchangeably. Returns null on any
     * failure (missing/invalid key, network, empty result) so the caller degrades to
     * the existing generic "🎤 Голосовое сообщение" placeholder rather than blocking
     * the message.
     */
    public function transcribeAudio(string $absolutePath): ?string
    {
        $apiKey = $this->platform->llmApiKey('groq');

        if ($apiKey === '' || ! is_readable($absolutePath)) {
            return null;
        }

        try {
            $response = Http::timeout(30)->connectTimeout(5)->retry(1, 500)
                ->withToken($apiKey)
                ->attach('file', file_get_contents($absolutePath), basename($absolutePath))
                ->post('https://api.groq.com/openai/v1/audio/transcriptions', [
                    'model' => 'whisper-large-v3-turbo',
                    'response_format' => 'json',
                ]);
        } catch (Throwable $error) {
            Log::warning('Voice transcription request failed', ['error' => $error->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            $this->logHttpFailure('groq-whisper', $response);

            return null;
        }

        $text = trim((string) Arr::get($response->json(), 'text', ''));

        return $text !== '' ? $text : null;
    }

    /**
     * Single-turn completion. Returns null if the provider isn't configured or the
     * call failed; otherwise an array with the reply text plus best-effort usage
     * data (tokens_in/tokens_out/latency_ms/cost_usd) for the LLM usage/billing view.
     *
     * @return array{text: string, tokens_in: ?int, tokens_out: ?int, latency_ms: int, cost_usd: ?float}|null
     */
    public function complete(Tenant $tenant, string $provider, string $model, string $systemPrompt, string $userPrompt): ?array
    {
        $apiKey = $this->apiKey($tenant, $provider);

        if ($apiKey === '') {
            return null;
        }

        // Circuit breaker (ЭТАП 16.1/16.2): a provider that's already tripped
        // FAILURE_THRESHOLD consecutive failures is skipped entirely — no point
        // spending the request timeout confirming what the last 3 calls already
        // showed. Only ActiveHealthProbe's dedicated recovery check calls back in.
        if ($this->health->isOpen('llm:'.$provider)) {
            return null;
        }

        $startedAt = microtime(true);

        try {
            $result = match ($provider) {
                'openai', 'deepseek', 'groq' => $this->completeOpenAiCompatible($provider, $apiKey, $model, $systemPrompt, $userPrompt),
                'anthropic' => $this->completeAnthropic($apiKey, $model, $systemPrompt, $userPrompt),
                'google' => $this->completeGoogle($apiKey, $model, $systemPrompt, $userPrompt),
                default => null,
            };
        } catch (Throwable $error) {
            // Was completely silent before — a dead key or a flaky connection to the
            // provider both degrade identically (customer just gets the dumb local-mvp
            // fallback) with zero trace of why, which turned a real VPS network issue
            // into a multi-hour manual investigation to even confirm the key was fine.
            Log::warning('LLM completion request failed', [
                'provider' => $provider,
                'model' => $model,
                'tenant_id' => $tenant->id,
                'error' => $error->getMessage(),
            ]);

            $this->health->recordFailure('llm:'.$provider, null, 'request_failed', $error->getMessage());

            return null;
        }

        if ($result === null || trim((string) $result['text']) === '') {
            $this->health->recordFailure('llm:'.$provider, null, 'empty_or_http_error');

            return null;
        }

        $this->health->recordSuccess('llm:'.$provider, null);

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'text' => trim($result['text']),
            'tokens_in' => $result['tokens_in'],
            'tokens_out' => $result['tokens_out'],
            'latency_ms' => $latencyMs,
            'cost_usd' => $this->estimateCost($provider, $result['tokens_in'], $result['tokens_out']),
        ];
    }

    private function logHttpFailure(string $provider, Response $response): void
    {
        Log::warning('LLM completion request returned a non-2xx response', [
            'provider' => $provider,
            'status' => $response->status(),
            'body' => Str::limit($response->body(), 500),
        ]);
    }

    private function estimateCost(string $provider, ?int $tokensIn, ?int $tokensOut): ?float
    {
        $pricing = self::PRICING_PER_MILLION_TOKENS[$provider] ?? null;

        if (! $pricing || $tokensIn === null || $tokensOut === null) {
            return null;
        }

        return round(($tokensIn / 1_000_000 * $pricing['in']) + ($tokensOut / 1_000_000 * $pricing['out']), 6);
    }

    private function listOpenAiCompatibleModels(string $provider, string $apiKey): array
    {
        $baseUrl = self::OPENAI_COMPATIBLE_BASE_URLS[$provider];
        $response = Http::timeout(10)->connectTimeout(4)->acceptJson()->withToken($apiKey)->get($baseUrl.'/models');

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response, 'error.message'));
        }

        return collect(Arr::get($response->json(), 'data', []))->pluck('id')->filter()->values()->all();
    }

    private function listAnthropicModels(string $apiKey): array
    {
        $response = Http::timeout(10)->connectTimeout(4)->acceptJson()
            ->withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])
            ->get('https://api.anthropic.com/v1/models');

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response, 'error.message'));
        }

        return collect(Arr::get($response->json(), 'data', []))->pluck('id')->filter()->values()->all();
    }

    private function listGoogleModels(string $apiKey): array
    {
        $response = Http::timeout(10)->connectTimeout(4)->acceptJson()
            ->get('https://generativelanguage.googleapis.com/v1beta/models', ['key' => $apiKey]);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response, 'error.message'));
        }

        return collect(Arr::get($response->json(), 'models', []))
            ->pluck('name')
            ->filter()
            ->map(fn (string $name): string => Str::after($name, 'models/'))
            ->values()
            ->all();
    }

    /**
     * Timeout/retry here (and in completeAnthropic()/completeGoogle() below, same
     * values) is tuned for this VPS's known-flaky international connectivity —
     * verified live that this exact host+key+endpoint sometimes connects in
     * under 1s and sometimes hangs the full timeout with zero bytes back, on
     * consecutive attempts seconds apart (same root cause already diagnosed for
     * inbound Telegram webhook delays). More, shorter attempts recover faster
     * than one long one: 3 tries × 15s + 2×500ms sleep ≈ 46s worst case, still
     * comfortably under ProcessAiReplyJob's own 60s timeout.
     *
     * @return array{text: ?string, tokens_in: ?int, tokens_out: ?int}|null
     */
    private function completeOpenAiCompatible(string $provider, string $apiKey, string $model, string $systemPrompt, string $userPrompt): ?array
    {
        $baseUrl = self::OPENAI_COMPATIBLE_BASE_URLS[$provider];
        $response = Http::timeout(15)->connectTimeout(4)->retry(2, 500)->acceptJson()->withToken($apiKey)
            ->post($baseUrl.'/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.4,
                'max_tokens' => 600,
            ]);

        if (! $response->successful()) {
            $this->logHttpFailure($provider, $response);

            return null;
        }

        $json = $response->json();
        $text = Arr::get($json, 'choices.0.message.content');

        return [
            'text' => is_string($text) ? $text : null,
            'tokens_in' => Arr::get($json, 'usage.prompt_tokens'),
            'tokens_out' => Arr::get($json, 'usage.completion_tokens'),
        ];
    }

    /**
     * @return array{text: ?string, tokens_in: ?int, tokens_out: ?int}|null
     */
    private function completeAnthropic(string $apiKey, string $model, string $systemPrompt, string $userPrompt): ?array
    {
        $response = Http::timeout(15)->connectTimeout(4)->retry(2, 500)->acceptJson()
            ->withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'max_tokens' => 600,
                'temperature' => 0.4,
            ]);

        if (! $response->successful()) {
            $this->logHttpFailure('anthropic', $response);

            return null;
        }

        $json = $response->json();
        $text = Arr::get($json, 'content.0.text');

        return [
            'text' => is_string($text) ? $text : null,
            'tokens_in' => Arr::get($json, 'usage.input_tokens'),
            'tokens_out' => Arr::get($json, 'usage.output_tokens'),
        ];
    }

    /**
     * @return array{text: ?string, tokens_in: ?int, tokens_out: ?int}|null
     */
    private function completeGoogle(string $apiKey, string $model, string $systemPrompt, string $userPrompt): ?array
    {
        $response = Http::timeout(15)->connectTimeout(4)->retry(2, 500)->acceptJson()
            ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent?key='.urlencode($apiKey), [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $userPrompt]]],
                ],
                'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 600],
            ]);

        if (! $response->successful()) {
            $this->logHttpFailure('google', $response);

            return null;
        }

        $json = $response->json();
        $text = Arr::get($json, 'candidates.0.content.parts.0.text');

        return [
            'text' => is_string($text) ? $text : null,
            'tokens_in' => Arr::get($json, 'usageMetadata.promptTokenCount'),
            'tokens_out' => Arr::get($json, 'usageMetadata.candidatesTokenCount'),
        ];
    }

    private function errorMessage($response, string $path): string
    {
        $message = Arr::get($response->json(), $path);

        return is_string($message) && $message !== '' ? $message : 'HTTP '.$response->status();
    }
}
