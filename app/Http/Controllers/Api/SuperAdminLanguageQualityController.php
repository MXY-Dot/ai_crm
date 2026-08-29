<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiEvalExample;
use App\Models\AiEvalResult;
use App\Models\AiSystemPrompt;
use App\Models\LanguageExample;
use App\Support\Ai\LanguageEvalRunner;
use App\Support\Audit\AuditLogger;
use App\Support\Integrations\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super Admin -> Качество AI -> Языковые датасеты. Deliberately NOT under
 * /super-admin/llm-providers -- this is language-quality content (prompt
 * versions, few-shot examples, eval set/results, and the base knowledge
 * document below), not provider credentials/keys. Everything an admin can
 * use to shape what the AI knows and how it answers across every tenant on
 * the platform lives on this one page.
 */
class SuperAdminLanguageQualityController extends Controller
{
    public function __construct(private readonly PlatformSettings $platform)
    {
    }

    public function index(): JsonResponse
    {
        $prompts = AiSystemPrompt::query()->latest('id')->limit(20)->get();

        $examples = LanguageExample::withoutGlobalScopes()
            ->with(['tenant:id,name', 'company:id,name'])
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (LanguageExample $e): array => [
                'id' => $e->id,
                'tenant_name' => $e->tenant?->name,
                'company_name' => $e->company?->name,
                'customer_message' => $e->customer_message,
                'good_reply' => $e->good_reply,
                'language' => $e->language,
                'status' => $e->status,
            ]);

        $evalExamples = AiEvalExample::query()->orderBy('id')->get();

        $latestRunId = AiEvalResult::query()->latest('created_at')->value('run_id');
        $latestResults = $latestRunId
            ? AiEvalResult::withoutGlobalScopes()->where('run_id', $latestRunId)->with('example')->get()
            : collect();

        return response()->json([
            'base_knowledge_document' => $this->platform->baseKnowledgeDocument(),
            'prompts' => $prompts,
            'active_prompt' => AiSystemPrompt::active(),
            'examples' => $examples,
            'examples_approved_count' => $examples->where('status', 'approved')->count(),
            'eval_examples' => $evalExamples,
            'latest_run_id' => $latestRunId,
            'latest_results' => $latestResults->map(fn (AiEvalResult $r): array => [
                'id' => $r->id,
                'example_id' => $r->ai_eval_example_id,
                'input_text' => $r->example?->input_text,
                'expected_reply' => $r->example?->expected_reply,
                'expected_intent' => $r->example?->expected_intent,
                'provider' => $r->provider,
                'model' => $r->model,
                'response_text' => $r->response_text,
                'success' => $r->success,
                'error_message' => $r->error_message,
                'latency_ms' => $r->latency_ms,
                'tokens_in' => $r->tokens_in,
                'tokens_out' => $r->tokens_out,
                'created_at' => $r->created_at,
            ]),
        ]);
    }

    /**
     * Moved here from SuperAdminLlmProviderController (llm-providers is
     * credentials/keys only) — this text is injected into every tenant's AI
     * system prompt regardless of their own knowledge base, so it belongs
     * with the rest of the platform's language/knowledge controls.
     */
    public function updateBaseKnowledgeDocument(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'content' => ['nullable', 'string', 'max:8000'],
        ]);

        $previous = $this->platform->baseKnowledgeDocument();
        $this->platform->setBaseKnowledgeDocument($data['content'] ?? '');
        $audit->record('platform_base_knowledge.updated', 'PlatformSettings', ['length' => mb_strlen($data['content'] ?? '')], ['length' => mb_strlen($previous)], $request);

        return response()->json(['ok' => true, 'content' => $this->platform->baseKnowledgeDocument()]);
    }

    public function storeSystemPrompt(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:60'],
            'content' => ['required', 'string', 'max:20000'],
        ]);

        AiSystemPrompt::query()->where('is_active', true)->update(['is_active' => false]);
        $prompt = AiSystemPrompt::query()->create([
            'version' => $data['version'],
            'content' => $data['content'],
            'is_active' => true,
        ]);

        $audit->record('ai_system_prompt.created', $prompt, ['version' => $prompt->version], [], $request);

        return response()->json($prompt, 201);
    }

    public function activateSystemPrompt(AiSystemPrompt $prompt, AuditLogger $audit): JsonResponse
    {
        AiSystemPrompt::query()->where('is_active', true)->update(['is_active' => false]);
        $prompt->update(['is_active' => true]);

        $audit->record('ai_system_prompt.activated', $prompt, ['version' => $prompt->version], [], request());

        return response()->json($prompt);
    }

    public function updateExampleStatus(Request $request, LanguageExample $languageExample, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,pending,rejected'],
        ]);

        $previous = $languageExample->status;
        $languageExample->forceFill(['status' => $data['status']])->save();

        $audit->record('language_example.status_updated', $languageExample, ['status' => $data['status']], ['status' => $previous], $request);

        return response()->json($languageExample);
    }

    public function storeEvalExample(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'input_text' => ['required', 'string', 'max:2000'],
            'expected_reply' => ['nullable', 'string', 'max:2000'],
            'expected_intent' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $example = AiEvalExample::query()->create($data);
        $audit->record('ai_eval_example.created', $example, ['input_text' => $example->input_text], [], $request);

        return response()->json($example, 201);
    }

    public function destroyEvalExample(AiEvalExample $aiEvalExample, AuditLogger $audit): JsonResponse
    {
        $audit->record('ai_eval_example.deleted', $aiEvalExample, [], ['input_text' => $aiEvalExample->input_text], request());
        $aiEvalExample->delete();

        return response()->json(['ok' => true]);
    }

    public function runEval(LanguageEvalRunner $runner, AuditLogger $audit): JsonResponse
    {
        $runId = $runner->run();
        $results = AiEvalResult::withoutGlobalScopes()->where('run_id', $runId)->with('example')->get();

        $audit->record('ai_eval.run', 'AiEvalResult', ['run_id' => $runId, 'results' => $results->count()], [], request());

        return response()->json([
            'run_id' => $runId,
            'results' => $results->map(fn (AiEvalResult $r): array => [
                'id' => $r->id,
                'example_id' => $r->ai_eval_example_id,
                'input_text' => $r->example?->input_text,
                'expected_reply' => $r->example?->expected_reply,
                'expected_intent' => $r->example?->expected_intent,
                'provider' => $r->provider,
                'model' => $r->model,
                'response_text' => $r->response_text,
                'success' => $r->success,
                'error_message' => $r->error_message,
                'latency_ms' => $r->latency_ms,
                'tokens_in' => $r->tokens_in,
                'tokens_out' => $r->tokens_out,
                'created_at' => $r->created_at,
            ]),
        ]);
    }
}
