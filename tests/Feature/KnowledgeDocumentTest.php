<?php

namespace Tests\Feature;

use App\Models\AiAgent;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_knowledge_document_for_tenant(): void
    {
        [$tenant, $company, $agent, $user] = $this->tenantSetup();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/knowledge-documents', [
                'company_id' => $company->id,
                'ai_agent_id' => $agent->id,
                'title' => 'Service FAQ',
                'source_type' => 'faq',
                'status' => 'queued',
                'summary' => 'Answers for service questions.',
            ])
            ->assertCreated()
            ->assertJsonPath('title', 'Service FAQ');

        $this->assertDatabaseHas(KnowledgeDocument::class, [
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'ai_agent_id' => $agent->id,
            'title' => 'Service FAQ',
        ]);
    }

    public function test_owner_can_index_text_into_chunks(): void
    {
        [$tenant, $company, $agent, $user] = $this->tenantSetup();
        $content = str_repeat('Hair color appointments take ninety minutes and require operator confirmation. ', 24)."\n\n".
            str_repeat('Payment, refund and deposit rules must be handled by an operator. ', 24);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/knowledge-documents/index-text', [
                'company_id' => $company->id,
                'ai_agent_id' => $agent->id,
                'title' => 'Long service policy',
                'content' => $content,
            ])
            ->assertCreated()
            ->assertJsonPath('title', 'Long service policy')
            ->assertJsonPath('status', 'indexed');

        $document = KnowledgeDocument::query()->where('title', 'Long service policy')->firstOrFail();

        $this->assertSame($tenant->id, $document->tenant_id);
        $this->assertGreaterThan(1, KnowledgeChunk::query()->where('knowledge_document_id', $document->id)->count());
        $this->assertNotNull($document->indexed_at);
        $this->assertDatabaseHas(AuditLog::class, [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'action' => 'knowledge_document.indexed_text',
            'entity_type' => KnowledgeDocument::class,
            'entity_id' => $document->id,
        ]);
    }

    public function test_owner_can_upload_text_file_into_chunks(): void
    {
        Storage::fake('local');
        [$tenant, $company, $agent, $user] = $this->tenantSetup();
        $content = str_repeat('Uploaded service rules explain booking windows and operator confirmation. ', 24);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->post('/api/knowledge-documents/upload', [
                'company_id' => $company->id,
                'ai_agent_id' => $agent->id,
                'file' => UploadedFile::fake()->createWithContent('service-rules.txt', $content),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('title', 'service-rules')
            ->assertJsonPath('status', 'indexed');

        $document = KnowledgeDocument::query()->where('file_name', 'service-rules.txt')->firstOrFail();

        $this->assertSame($tenant->id, $document->tenant_id);
        $this->assertGreaterThan(0, KnowledgeChunk::query()->where('knowledge_document_id', $document->id)->count());
        Storage::disk('local')->assertExists(data_get($document->meta, 'storage_path'));
    }

    public function test_owner_can_upload_binary_document_for_parser_queue(): void
    {
        Storage::fake('local');
        [$tenant, $company, $agent, $user] = $this->tenantSetup();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->post('/api/knowledge-documents/upload', [
                'company_id' => $company->id,
                'ai_agent_id' => $agent->id,
                'title' => 'Price list PDF',
                'file' => UploadedFile::fake()->create('price-list.pdf', 32, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('title', 'Price list PDF')
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('source_type', 'pdf')
            ->assertJsonPath('chunks_count', 0);

        $document = KnowledgeDocument::query()->where('title', 'Price list PDF')->firstOrFail();

        $this->assertSame($tenant->id, $document->tenant_id);
        $this->assertSame('pending', data_get($document->meta, 'parser_status'));
        Storage::disk('local')->assertExists(data_get($document->meta, 'storage_path'));
    }

    public function test_indexing_knowledge_without_agent_creates_default_ai_agent(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Demo', 'slug' => 'demo']);
        $company = Company::query()->create(['tenant_id' => $tenant->id, 'name' => 'Studio']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/knowledge-documents/index-text', [
                'company_id' => $company->id,
                'title' => 'Company basics',
                'content' => str_repeat('We are a salon. Bridal makeup costs 220 USD. ', 12),
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'indexed');

        $agent = AiAgent::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $document = KnowledgeDocument::withoutGlobalScopes()->where('title', 'Company basics')->firstOrFail();

        $this->assertSame('Default Dify Assistant', $agent->name);
        $this->assertSame($agent->id, $document->ai_agent_id);
    }
    private function tenantSetup(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Demo', 'slug' => 'demo']);
        $company = Company::query()->create(['tenant_id' => $tenant->id, 'name' => 'Studio']);
        $agent = AiAgent::query()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id, 'name' => 'Agent']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

        return [$tenant, $company, $agent, $user];
    }
}