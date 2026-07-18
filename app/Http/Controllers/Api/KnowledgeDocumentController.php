<?php

namespace App\Http\Controllers\Api;

use App\Models\AiAgent;
use App\Models\Company;
use App\Models\KnowledgeDocument;
use App\Support\Audit\AuditLogger;
use App\Support\Knowledge\KnowledgeIndexer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class KnowledgeDocumentController extends TenantResourceController
{
    protected function model(): string
    {
        return KnowledgeDocument::class;
    }

    protected function rules(string $action): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'ai_agent_id' => ['nullable', 'integer', 'exists:ai_agents,id'],
            'title' => [$required, 'string', 'max:180'],
            'source_type' => ['nullable', Rule::in(['manual', 'pdf', 'docx', 'xlsx', 'url', 'faq'])],
            'file_name' => ['nullable', 'string', 'max:220'],
            'mime_type' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['draft', 'queued', 'indexed', 'failed', 'archived'])],
            'version' => ['nullable', 'integer', 'min:1'],
            'summary' => ['nullable', 'string'],
            'meta' => ['nullable', 'array'],
            'indexed_at' => ['nullable', 'date'],
        ];
    }

    public function upload(Request $request, KnowledgeIndexer $indexer, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', KnowledgeDocument::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'ai_agent_id' => ['nullable', 'integer', 'exists:ai_agents,id'],
            'title' => ['nullable', 'string', 'max:180'],
            'file' => ['required', 'file', 'max:10240'],
            'summary' => ['nullable', 'string'],
        ]);
        $data['ai_agent_id'] ??= $this->defaultAgent((int) $data['company_id'])->id;

        $document = $indexer->indexUploadedFile($data);

        $audit->record('knowledge_document.uploaded', $document, $this->auditDocument($document), [], $request);

        return response()->json($document->loadCount('chunks'), 201);
    }

    public function indexText(Request $request, KnowledgeIndexer $indexer, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', KnowledgeDocument::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'ai_agent_id' => ['nullable', 'integer', 'exists:ai_agents,id'],
            'title' => ['required', 'string', 'max:180'],
            'content' => ['required', 'string', 'min:20'],
            'source_type' => ['nullable', Rule::in(['manual', 'faq', 'url'])],
            'file_name' => ['nullable', 'string', 'max:220'],
            'mime_type' => ['nullable', 'string', 'max:120'],
            'version' => ['nullable', 'integer', 'min:1'],
            'summary' => ['nullable', 'string'],
        ]);
        $data['ai_agent_id'] ??= $this->defaultAgent((int) $data['company_id'])->id;

        $document = $indexer->indexText($data);

        $audit->record('knowledge_document.indexed_text', $document, $this->auditDocument($document), [], $request);

        return response()->json($document->loadCount('chunks'), 201);
    }

    private function defaultAgent(int $companyId): AiAgent
    {
        $company = Company::withoutGlobalScopes()->findOrFail($companyId);

        return AiAgent::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'name' => 'Default Dify Assistant'],
            [
                'provider' => 'dify',
                'status' => 'active',
                'handoff_threshold' => 70,
                'instructions' => 'Answer as the company assistant using the knowledge base. Ask one short clarifying question when required, and hand off policy/payment edge cases.',
                'settings' => ['knowledge_base' => 'tenant-default'],
            ]
        );
    }
    private function auditDocument(KnowledgeDocument $document): array
    {
        return [
            'title' => $document->title,
            'status' => $document->status,
            'source_type' => $document->source_type,
            'file_name' => $document->file_name,
            'chunks_count' => $document->chunks()->count(),
        ];
    }
}