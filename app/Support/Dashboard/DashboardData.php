<?php

namespace App\Support\Dashboard;

use App\Models\AiAgent;
use App\Models\AuditLog;
use App\Models\AiRun;
use App\Models\Channel;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Conversation;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\HealthComponent;
use App\Models\KnowledgeDocument;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\RolePages;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class DashboardData
{
    public function forUser(?User $user): array
    {
        $empty = [
            'user' => $user?->only(['id', 'name', 'email', 'role', 'phone', 'avatar_url', 'two_factor_enabled']),
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
            'enabledModules' => [],
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
        $role = $user?->role;

        return [
            'user' => $user?->only(['id', 'name', 'email', 'role', 'phone', 'avatar_url', 'two_factor_enabled']),
            'tenant' => array_merge(
                $tenant->only(['id', 'name', 'slug', 'status', 'trial_ends_at']),
                ['settings' => Arr::except($tenant->settings ?? [], ['integrations'])],
            ),
            'company' => $company?->only(['id', 'name', 'industry', 'phone', 'email', 'website', 'address', 'timezone', 'working_hours', 'brand_settings', 'logo_url']),
            'stats' => [
                'Companies' => Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
                'Customers' => Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
                'Leads' => Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
                'Open tasks' => CrmTask::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('status', ['open', 'in_progress'])->count(),
            ],
            'customers' => Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest()->limit(500)->get(['id', 'name', 'phone', 'email', 'source', 'created_at', 'segment', 'is_business', 'city', 'birth_year', 'vip_score', 'vip_status', 'vip_reason', 'purchases_count', 'total_revenue', 'last_purchase_at']),
            'leads' => Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest()->limit(500)->get(['id', 'customer_id', 'title', 'status', 'source', 'score', 'ai_summary', 'created_at']),
            'tasks' => CrmTask::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest()->limit(8)->get(['id', 'lead_id', 'title', 'status', 'priority']),
            'channels' => $this->channels($tenant->id, $companyId),
            'conversations' => $this->conversations($tenant->id, $companyId),
            'messages' => $this->messages($tenant->id),
            'aiAgents' => $this->aiAgents($tenant->id, $companyId),
            'aiRuns' => $this->aiRuns($tenant->id),
            'knowledgeDocuments' => RolePages::allowed($role, 'knowledge') ? $this->knowledgeDocuments($tenant->id, $companyId) : [],
            'auditLogs' => RolePages::allowed($role, 'settings') ? $this->auditLogs($tenant->id) : [],
            'tenantUsers' => RolePages::allowed($role, 'team') ? $this->tenantUsers($tenant->id) : [],
            'enabledModules' => $this->enabledModules($companyId),
        ];
    }

    /**
     * Public so per-entity "show" routes (e.g. GET /ai/agents/{agent}) can
     * check ownership against the SAME effective tenant forUser() itself
     * resolved the page's bootstrap from -- a super_admin has no tenant_id
     * at all and falls back to the 'demo' tenant here, so a naive
     * `$agent->tenant_id === $user->tenant_id` check would 404 every one of
     * their own dashboard pages.
     */
    public function tenantFor(?User $user): ?Tenant
    {
        if ($user?->tenant_id) {
            return Tenant::query()->find($user->tenant_id);
        }

        return Tenant::query()->where('slug', 'demo')->first() ?? Tenant::query()->first();
    }

    private function channels(int $tenantId, ?int $companyId): array
    {
        if (! $companyId || ! Schema::hasTable('channels')) return [];

        $channels = Channel::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('company_id', $companyId)->orderBy('provider')->get(['id', 'provider', 'name', 'status', 'last_synced_at']);

        // ЭТАП 2.6 — real per-channel health, Telegram only for now (see
        // ActiveHealthProbe::probeTelegramChannels()'s own docblock for why
        // WhatsApp/Instagram/Facebook/Website aren't probed). null means
        // "not actively monitored", distinct from up/down.
        if (Schema::hasTable('health_components')) {
            $telegramStatus = HealthComponent::query()->where('tenant_id', $tenantId)->where('component', 'telegram:'.$tenantId)->value('status');

            $channels->each(function (Channel $channel) use ($telegramStatus): void {
                $channel->setAttribute('health_status', $channel->provider === 'telegram' ? $telegramStatus : null);
            });
        }

        return $channels->all();
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
            ->get(['id', 'channel_id', 'customer_id', 'lead_id', 'external_id', 'subject', 'status', 'priority', 'last_message_at', 'ai_summary', 'labels'])
            ->all();
    }

    private function messages(int $tenantId): array
    {
        if (! Schema::hasTable('messages')) return [];

        return Message::withoutGlobalScopes()->where('tenant_id', $tenantId)->latest('sent_at')->limit(24)->get(['id', 'conversation_id', 'sender_type', 'sender_name', 'body', 'sent_at', 'meta'])->reverse()->values()->all();
    }

    private function aiAgents(int $tenantId, ?int $companyId): array
    {
        if (! $companyId || ! Schema::hasTable('ai_agents')) return [];

        return AiAgent::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('company_id', $companyId)->latest()->limit(50)->get(['id', 'name', 'provider', 'model', 'status', 'handoff_threshold', 'goal', 'persona', 'max_discount_percent', 'forbidden_topics', 'instructions', 'channels'])->all();
    }

    private function aiRuns(int $tenantId): array
    {
        if (! Schema::hasTable('ai_runs')) return [];

        return AiRun::withoutGlobalScopes()
            ->with(['agent:id,name,provider', 'conversation:id,subject', 'lead:id,title'])
            ->where('tenant_id', $tenantId)
            ->latest('finished_at')
            ->limit(10)
            ->get(['id', 'ai_agent_id', 'conversation_id', 'lead_id', 'status', 'confidence', 'intent', 'summary', 'next_action', 'finished_at', 'payload'])
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

    private function enabledModules(?int $companyId): array
    {
        if (! $companyId || ! Schema::hasTable('company_modules')) return [];

        return CompanyModule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('enabled', true)
            ->pluck('module_key')
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