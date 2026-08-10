<?php

namespace App\Support\Dashboard;

use App\Models\AiAgent;
use App\Models\AuditLog;
use App\Models\AiRun;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\KnowledgeDocument;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class DashboardData
{
    public function forUser(?User $user): array
    {
        $empty = [
            'user' => $user?->only(['id', 'name', 'email', 'role']),
            'tenant' => null,
            'company' => null,
            'stats' => [],
            'customers' => [],
            'leads' => [],
            'tasks' => [],
            'channels' => [],
            'conversations' => [],
            'messages' => [],
            'aiAgents' => [],
            'aiRuns' => [],
            'knowledgeDocuments' => [],
            'auditLogs' => [],
            'tenantUsers' => [],
        ];

        if (! Schema::hasTable('tenants')) {
            return $empty;
        }

        $tenant = $this->tenantFor($user);

        if (! $tenant) {
            return $empty;
        }

        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $companyId = $company?->id;

        return [
            'user' => $user?->only(['id', 'name', 'email', 'role']),
            'tenant' => $tenant->only(['id', 'name', 'slug', 'status', 'trial_ends_at', 'settings']),
            'company' => $company?->only(['id', 'name', 'industry', 'phone', 'email', 'website', 'address', 'timezone', 'working_hours', 'brand_settings', 'logo_url']),
            'stats' => [
                'Companies' => Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
                'Customers' => Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
                'Leads' => Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
                'Open tasks' => CrmTask::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('status', ['open', 'in_progress'])->count(),
            ],
            'customers' => Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest()->limit(500)->get(['id', 'name', 'phone', 'email', 'source', 'created_at']),
            'leads' => Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest()->limit(500)->get(['id', 'customer_id', 'title', 'status', 'source', 'score', 'ai_summary', 'created_at']),
            'tasks' => CrmTask::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest()->limit(8)->get(['id', 'lead_id', 'title', 'status', 'priority']),
            'channels' => $this->channels($tenant->id, $companyId),
            'conversations' => $this->conversations($tenant->id, $companyId),
            'messages' => $this->messages($tenant->id),
            'aiAgents' => $this->aiAgents($tenant->id, $companyId),
            'aiRuns' => $this->aiRuns($tenant->id),
            'knowledgeDocuments' => $this->knowledgeDocuments($tenant->id, $companyId),
            'auditLogs' => $this->auditLogs($tenant->id),
            'tenantUsers' => $this->tenantUsers($tenant->id),
        ];
    }

    private function tenantFor(?User $user): ?Tenant
    {
        if ($user?->tenant_id) {
            return Tenant::query()->find($user->tenant_id);
        }

        return Tenant::query()->where('slug', 'demo')->first() ?? Tenant::query()->first();
    }

    private function channels(int $tenantId, ?int $companyId): array
    {
        if (! $companyId || ! Schema::hasTable('channels')) return [];

        return Channel::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('company_id', $companyId)->orderBy('provider')->get(['id', 'provider', 'name', 'status', 'last_synced_at'])->all();
    }

    private function conversations(int $tenantId, ?int $companyId): array
    {
        if (! $companyId || ! Schema::hasTable('conversations')) return [];

        return Conversation::withoutGlobalScopes()
            ->with(['channel:id,provider,name', 'customer:id,name', 'lead:id,title'])
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->latest('last_message_at')
            ->limit(12)
            ->get(['id', 'channel_id', 'customer_id', 'lead_id', 'external_id', 'subject', 'status', 'priority', 'last_message_at', 'ai_summary'])
            ->all();
    }

    private function messages(int $tenantId): array
    {
        if (! Schema::hasTable('messages')) return [];

        return Message::withoutGlobalScopes()->where('tenant_id', $tenantId)->latest('sent_at')->limit(24)->get(['id', 'conversation_id', 'sender_type', 'sender_name', 'body', 'sent_at'])->reverse()->values()->all();
    }

    private function aiAgents(int $tenantId, ?int $companyId): array
    {
        if (! $companyId || ! Schema::hasTable('ai_agents')) return [];

        return AiAgent::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('company_id', $companyId)->latest()->limit(5)->get(['id', 'name', 'provider', 'status', 'handoff_threshold', 'instructions'])->all();
    }

    private function aiRuns(int $tenantId): array
    {
        if (! Schema::hasTable('ai_runs')) return [];

        return AiRun::withoutGlobalScopes()
            ->with(['agent:id,name,provider', 'conversation:id,subject', 'lead:id,title'])
            ->where('tenant_id', $tenantId)
            ->latest('finished_at')
            ->limit(10)
            ->get(['id', 'ai_agent_id', 'conversation_id', 'lead_id', 'status', 'confidence', 'intent', 'summary', 'next_action', 'finished_at'])
            ->all();
    }

    private function tenantUsers(int $tenantId): array
    {
        if (! Schema::hasTable('users')) return [];

        return User::query()
            ->where('tenant_id', $tenantId)
            ->latest()
            ->limit(20)
            ->get(['id', 'name', 'email', 'phone', 'role', 'status', 'last_login_at'])
            ->all();
    }

    private function auditLogs(int $tenantId): array
    {
        if (! Schema::hasTable('audit_logs')) return [];

        return AuditLog::withoutGlobalScopes()
            ->with('user:id,name,email')
            ->where('tenant_id', $tenantId)
            ->latest('created_at')
            ->limit(10)
            ->get(['id', 'user_id', 'action', 'entity_type', 'entity_id', 'new_values', 'created_at'])
            ->all();
    }

    private function knowledgeDocuments(int $tenantId, ?int $companyId): array
    {
        if (! $companyId || ! Schema::hasTable('knowledge_documents')) return [];

        return KnowledgeDocument::withoutGlobalScopes()
            ->withCount('chunks')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->latest('updated_at')
            ->limit(12)
            ->get(['id', 'ai_agent_id', 'title', 'source_type', 'file_name', 'status', 'version', 'summary', 'indexed_at', 'updated_at'])
            ->all();
    }
}