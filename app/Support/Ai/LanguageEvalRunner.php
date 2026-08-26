<?php

namespace App\Support\Ai;

use App\Models\AiEvalExample;
use App\Models\AiEvalResult;
use App\Models\AiSystemPrompt;
use App\Models\Tenant;
use App\Support\Integrations\PlatformSettings;
use App\Support\Language\TajikTextNormalizer;
use Illuminate\Support\Str;

/**
 * Runs every stored AiEvalExample against both Groq and DeepSeek using the
 * exact same LlmClient::complete() production code path AiWorkflow itself
 * uses (same circuit breaker, same LlmCallFailure logging) -- deliberately
 * NOT a separate mock/simulated path, so results reflect what a real
 * customer would actually get. Eval examples are never used as few-shot
 * prompt content (that's language_examples' job) -- only as fixed inputs
 * here, per the user's explicit requirement to keep the two completely
 * separate.
 */
class LanguageEvalRunner
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly TajikTextNormalizer $normalizer,
        private readonly PlatformSettings $platform,
    ) {
    }

    /** @return string the run_id grouping every AiEvalResult this call produced */
    public function run(): string
    {
        $runId = (string) Str::uuid();
        $tenant = Tenant::query()->first();

        if (! $tenant) {
            return $runId;
        }

        $systemPrompt = $this->buildSystemPrompt();
        $examples = AiEvalExample::query()->orderBy('id')->get();

        foreach ($examples as $example) {
            foreach (['groq', 'deepseek'] as $provider) {
                $this->runOne($tenant, $systemPrompt, $example, $provider, $runId);
            }
        }

        return $runId;
    }

    private function runOne(Tenant $tenant, string $systemPrompt, AiEvalExample $example, string $provider, string $runId): void
    {
        $model = $this->platform->defaultModelFor($provider);
        $normalized = $this->normalizer->normalize($example->input_text);

        // original_text goes to the model untouched (requirement #3) -- normalized_text
        // isn't sent here at all, it's only for example/knowledge retrieval in the real
        // reply path (AiWorkflow), which this eval run intentionally bypasses to isolate
        // just the system-prompt + model behavior being measured.
        $result = $this->llm->complete($tenant, $provider, $model, $systemPrompt, $normalized['original_text']);

        AiEvalResult::query()->create([
            'ai_eval_example_id' => $example->id,
            'run_id' => $runId,
            'provider' => $provider,
            'model' => $model,
            'response_text' => $result['text'] ?? null,
            'latency_ms' => $result['latency_ms'] ?? null,
            'tokens_in' => $result['tokens_in'] ?? null,
            'tokens_out' => $result['tokens_out'] ?? null,
            'success' => $result !== null,
            'error_message' => $result === null ? 'No response from provider — see LlmCallFailure/laravel.log for the real cause.' : null,
        ]);
    }

    private function buildSystemPrompt(): string
    {
        $active = AiSystemPrompt::active();

        return $active?->content ?? '';
    }
}
