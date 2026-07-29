import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { apiRequest } from '../lib/apiClient';

export type Toast = { id: number; tone: 'success' | 'error'; message: string };

export type Customer = {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    source: string | null;
};

export type Lead = {
    id: number;
    customer_id: number | null;
    title: string;
    status: string;
    source: string | null;
    score: number;
    ai_summary: string | null;
};

export type Task = {
    id: number;
    lead_id: number | null;
    title: string;
    status: string;
    priority: string;
};

export type Channel = {
    id: number;
    provider: string;
    name: string;
    status: string;
    last_synced_at: string | null;
};

export type Conversation = {
    id: number;
    external_id?: string | null;
    subject: string;
    status: string;
    priority: string;
    last_message_at: string | null;
    ai_summary: string | null;
    channel?: { id: number; provider: string; name: string } | null;
    customer?: { id: number; name: string } | null;
    lead?: { id: number; title: string } | null;
};

export type Message = {
    id: number;
    conversation_id: number;
    sender_type: string;
    sender_name: string | null;
    body: string;
    sent_at: string | null;
};

export type AiAgent = {
    id: number;
    name: string;
    provider: string;
    status: string;
    handoff_threshold: number;
    instructions: string | null;
};

export type AiAgentPayload = {
    name?: string;
    status?: 'active' | 'paused' | 'disabled';
    handoff_threshold?: number;
    instructions?: string | null;
};

export type KnowledgeDocument = {
    id: number;
    ai_agent_id: number | null;
    title: string;
    source_type: string;
    file_name: string | null;
    status: string;
    version: number;
    summary: string | null;
    indexed_at: string | null;
    updated_at: string | null;
    chunks_count: number;
};

export type TenantUser = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    role: 'super_admin' | 'owner' | 'manager' | 'operator';
    status: 'active' | 'invited' | 'disabled';
    last_login_at: string | null;
};

export type TenantUserPayload = {
    name?: string;
    email?: string;
    phone?: string | null;
    role?: 'super_admin' | 'owner' | 'manager' | 'operator';
    status?: 'active' | 'invited' | 'disabled';
    password?: string;
};

export type AuditLog = {
    id: number;
    action: string;
    entity_type: string;
    entity_id: number | null;
    new_values: Record<string, unknown> | null;
    created_at: string | null;
    user?: { id: number; name: string; email: string } | null;
};

export type IntegrationSettings = {
    dify: {
        url: string | null;
        api_key_configured: boolean;
        api_key_mask: string | null;
        timeout: number;
        handoff_threshold: number | null;
    };
    chatwoot: {
        url: string | null;
        account_id: number | string | null;
        api_token_configured: boolean;
        api_token_mask: string | null;
        webhook_secret_configured: boolean;
        webhook_secret_mask: string | null;
        webhook_url: string;
        auto_reply_enabled: boolean;
    };
    telegram?: {
        bot_token_configured: boolean;
        bot_token_mask: string | null;
        webhook_secret_configured: boolean;
        webhook_secret_mask: string | null;
        webhook_url: string;
        auto_reply_enabled: boolean;
    };
};

export type IntegrationTestResult = {
    ok: boolean;
    provider: 'dify' | 'chatwoot';
    status: string;
    message: string;
    checked_at: string;
    meta: Record<string, string>;
};

export type IntegrationSettingsPayload = {
    dify?: {
        api_key?: string;
        timeout?: number;
        handoff_threshold?: number;
    };
    chatwoot?: {
        account_id?: number | null;
        api_token?: string;
        webhook_secret?: string;
        auto_reply_enabled?: boolean;
    };
    telegram?: {
        bot_token?: string;
        webhook_secret?: string;
        auto_reply_enabled?: boolean;
    };
};

export type AiRun = {
    id: number;
    status: string;
    confidence: number;
    intent: string | null;
    summary: string | null;
    next_action: string | null;
    finished_at: string | null;
    agent?: { id: number; name: string; provider: string } | null;
    conversation?: { id: number; subject: string } | null;
    lead?: { id: number; title: string } | null;
};

export type CompanyProfile = {
    id: number;
    name: string;
    industry: string | null;
    phone?: string | null;
    email?: string | null;
    website?: string | null;
    address?: string | null;
    timezone?: string | null;
    working_hours?: Record<string, string> | null;
    brand_settings?: Record<string, string> | null;
};

export type CompanyPayload = Partial<Omit<CompanyProfile, 'id'>>;

export type Bootstrap = {
    user?: { id: number; name: string; email: string; role: string } | null;
    tenant: { id: number; name: string; slug: string; status: string } | null;
    company: CompanyProfile | null;
    stats: Record<string, number>;
    customers: Customer[];
    leads: Lead[];
    tasks: Task[];
    channels: Channel[];
    conversations: Conversation[];
    messages: Message[];
    aiAgents: AiAgent[];
    aiRuns: AiRun[];
    knowledgeDocuments: KnowledgeDocument[];
    auditLogs: AuditLog[];
    tenantUsers: TenantUser[];
};

const fallback: Bootstrap = {
    user: null,
    tenant: null,
    company: null,
    stats: {},
    customers: [],
    leads: [],
    tasks: [],
    channels: [],
    conversations: [],
    messages: [],
    aiAgents: [],
    aiRuns: [],
    knowledgeDocuments: [],
    auditLogs: [],
    tenantUsers: [],
};

export const useCrmDashboardStore = defineStore('crmDashboard', () => {
    const boot = fallback;
    const user = ref(boot.user ?? null);
    const tenant = ref(boot.tenant);
    const company = ref(boot.company);
    const stats = ref(boot.stats);
    const customers = ref(boot.customers ?? []);
    const leads = ref(boot.leads ?? []);
    const tasks = ref(boot.tasks ?? []);
    const channels = ref(boot.channels ?? []);
    const conversations = ref(boot.conversations ?? []);
    const messages = ref(boot.messages ?? []);
    const aiAgents = ref(boot.aiAgents ?? []);
    const aiRuns = ref(boot.aiRuns ?? []);
    const knowledgeDocuments = ref(boot.knowledgeDocuments ?? []);
    const auditLogs = ref(boot.auditLogs ?? []);
    const tenantUsers = ref(boot.tenantUsers ?? []);
    const integrationSettings = ref<IntegrationSettings | null>(null);
    const toasts = ref<Toast[]>([]);
    const leadStatus = ref('all');
    const selectedConversationId = ref<number | null>(conversations.value[0]?.id ?? null);
    const selectedCustomerId = ref<number | null>(customers.value[0]?.id ?? null);
    const selectedLeadId = ref<number | null>(leads.value[0]?.id ?? null);
    const busy = ref(false);
    const error = ref<string | null>(null);

    const openTasks = computed(() => tasks.value.filter((task) => ['open', 'in_progress'].includes(task.status)));
    const filteredLeads = computed(() => leadStatus.value === 'all'
        ? leads.value
        : leads.value.filter((lead) => lead.status === leadStatus.value));
    const selectedCustomer = computed(() => customers.value.find((customer) => customer.id === selectedCustomerId.value) ?? null);
    const selectedLead = computed(() => leads.value.find((lead) => lead.id === selectedLeadId.value) ?? null);
    const selectedConversation = computed(() => conversations.value.find((conversation) => conversation.id === selectedConversationId.value) ?? conversations.value[0] ?? null);
    const selectedMessages = computed(() => selectedConversation.value
        ? messages.value.filter((message) => message.conversation_id === selectedConversation.value?.id)
        : []);
    const aiHandoffs = computed(() => aiRuns.value.filter((run) => run.confidence < (aiAgents.value[0]?.handoff_threshold ?? 70)));
    const apiHeader = computed(() => `X-Tenant-Id: ${tenant.value?.slug ?? 'demo'}`);
    const hasData = computed(() => tenant.value !== null);
    const tenantSlug = computed(() => tenant.value?.slug ?? null);
    const companyId = computed(() => company.value?.id ?? null);

    function notify(message: string, tone: Toast['tone'] = 'success'): void {
        const id = Date.now() + Math.floor(Math.random() * 1000);
        toasts.value.push({ id, tone, message });
        window.setTimeout(() => dismissToast(id), 4000);
    }

    function dismissToast(id: number): void {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }

    function hydrateBootstrap(data: Bootstrap): void {
        user.value = data.user ?? null;
        tenant.value = data.tenant ?? null;
        company.value = data.company ?? null;
        stats.value = data.stats ?? {};
        customers.value = data.customers ?? [];
        leads.value = data.leads ?? [];
        tasks.value = data.tasks ?? [];
        channels.value = data.channels ?? [];
        conversations.value = data.conversations ?? [];
        messages.value = data.messages ?? [];
        aiAgents.value = data.aiAgents ?? [];
        aiRuns.value = data.aiRuns ?? [];
        knowledgeDocuments.value = data.knowledgeDocuments ?? [];
        auditLogs.value = data.auditLogs ?? [];
        tenantUsers.value = data.tenantUsers ?? [];
        selectedConversationId.value = conversations.value[0]?.id ?? null;
        selectedCustomerId.value = customers.value[0]?.id ?? null;
        selectedLeadId.value = leads.value[0]?.id ?? null;
    }
    function selectConversation(id: number): void {
        selectedConversationId.value = id;
    }

    async function refreshDashboard(): Promise<void> {
        const data = await apiRequest<Bootstrap>('/api/dashboard');
        user.value = data.user ?? null;
        tenant.value = data.tenant ?? null;
        company.value = data.company ?? null;
        stats.value = data.stats ?? {};
        customers.value = data.customers ?? [];
        leads.value = data.leads ?? [];
        tasks.value = data.tasks ?? [];
        channels.value = data.channels ?? [];
        conversations.value = data.conversations ?? [];
        messages.value = data.messages ?? [];
        aiAgents.value = data.aiAgents ?? [];
        aiRuns.value = data.aiRuns ?? [];
        knowledgeDocuments.value = data.knowledgeDocuments ?? [];
        auditLogs.value = data.auditLogs ?? [];
        tenantUsers.value = data.tenantUsers ?? [];
        selectedConversationId.value ??= conversations.value[0]?.id ?? null;
        selectedCustomerId.value ??= customers.value[0]?.id ?? null;
        selectedLeadId.value ??= leads.value[0]?.id ?? null;
    }

    async function mutate(action: () => Promise<unknown>): Promise<void> {
        busy.value = true;
        error.value = null;
        try {
            await action();
            await refreshDashboard();
            notify('Done');
        } catch (caught) {
            error.value = caught instanceof Error ? caught.message : 'Request failed';
            notify(error.value, 'error');
            throw caught;
        } finally {
            busy.value = false;
        }
    }

    async function createCustomer(payload: { name: string; phone?: string; email?: string; source?: string }): Promise<void> {
        if (! companyId.value) return;
        await mutate(() => apiRequest('/api/customers', {
            method: 'POST',
            tenant: tenantSlug.value,
            body: { company_id: companyId.value, ...payload },
        }));
    }

    async function createLead(payload: { title: string; source?: string; score?: number; customer_id?: number | null }): Promise<void> {
        if (! companyId.value) return;
        await mutate(() => apiRequest('/api/leads', {
            method: 'POST',
            tenant: tenantSlug.value,
            body: { company_id: companyId.value, status: 'new', ...payload },
        }));
    }

    async function updateCompany(id: number, payload: CompanyPayload): Promise<void> {
        await mutate(() => apiRequest(`/api/companies/${id}`, {
            method: 'PATCH',
            tenant: tenantSlug.value,
            body: payload,
        }));
    }

    async function updateLeadStatus(id: number, status: string): Promise<void> {
        await mutate(() => apiRequest(`/api/leads/${id}`, {
            method: 'PATCH',
            tenant: tenantSlug.value,
            body: { status },
        }));
    }

    async function createTask(payload: { title: string; priority?: string; lead_id?: number | null }): Promise<void> {
        if (! companyId.value) return;
        await mutate(() => apiRequest('/api/tasks', {
            method: 'POST',
            tenant: tenantSlug.value,
            body: { company_id: companyId.value, status: 'open', priority: 'normal', ...payload },
        }));
    }

    async function updateTaskStatus(id: number, status: string): Promise<void> {
        await mutate(() => apiRequest(`/api/tasks/${id}`, {
            method: 'PATCH',
            tenant: tenantSlug.value,
            body: { status },
        }));
    }

    async function generateAiDraft(id: number): Promise<void> {
        await mutate(() => apiRequest(`/api/conversations/${id}/ai-draft`, {
            method: 'POST',
            tenant: tenantSlug.value,
        }));
    }
    async function replyToConversation(id: number, body: string): Promise<void> {
        await mutate(() => apiRequest(`/api/conversations/${id}/reply`, {
            method: 'POST',
            tenant: tenantSlug.value,
            body: { body },
        }));
    }

    async function syncChatwoot(): Promise<void> {
        await mutate(() => apiRequest('/api/chatwoot/sync', {
            method: 'POST',
            tenant: tenantSlug.value,
        }));
    }

    async function updateAiAgent(id: number, payload: AiAgentPayload): Promise<void> {
        await mutate(() => apiRequest(`/api/ai-agents/${id}`, {
            method: 'PATCH',
            tenant: tenantSlug.value,
            body: payload,
        }));
    }

    async function indexKnowledgeText(payload: { title: string; content: string; ai_agent_id?: number | null }): Promise<void> {
        if (! companyId.value) return;
        await mutate(() => apiRequest('/api/knowledge-documents/index-text', {
            method: 'POST',
            tenant: tenantSlug.value,
            body: { company_id: companyId.value, source_type: 'manual', ...payload },
        }));
    }

    async function uploadKnowledgeFile(payload: { title?: string; file: File; ai_agent_id?: number | null }): Promise<void> {
        if (! companyId.value) return;

        const body = new FormData();
        body.append('company_id', String(companyId.value));
        if (payload.ai_agent_id) body.append('ai_agent_id', String(payload.ai_agent_id));
        if (payload.title) body.append('title', payload.title);
        body.append('file', payload.file);

        await mutate(() => apiRequest('/api/knowledge-documents/upload', {
            method: 'POST',
            tenant: tenantSlug.value,
            body,
        }));
    }

    async function createTenantUser(payload: Required<Pick<TenantUserPayload, 'name' | 'email' | 'role'>> & TenantUserPayload): Promise<void> {
        await mutate(() => apiRequest('/api/tenant-users', {
            method: 'POST',
            tenant: tenantSlug.value,
            body: payload,
        }));
    }

    async function updateTenantUser(id: number, payload: TenantUserPayload): Promise<void> {
        await mutate(() => apiRequest(`/api/tenant-users/${id}`, {
            method: 'PATCH',
            tenant: tenantSlug.value,
            body: payload,
        }));
    }

    async function loadIntegrationSettings(): Promise<void> {
        integrationSettings.value = await apiRequest<IntegrationSettings>('/api/integration-settings', {
            tenant: tenantSlug.value,
        });
    }

    async function testIntegrationConnection(payload: IntegrationSettingsPayload & { provider: 'dify' | 'chatwoot' }): Promise<IntegrationTestResult> {
        return apiRequest<IntegrationTestResult>('/api/integration-settings/test', {
            method: 'POST',
            tenant: tenantSlug.value,
            body: payload,
        });
    }

    async function updateIntegrationSettings(payload: IntegrationSettingsPayload): Promise<void> {
        busy.value = true;
        error.value = null;
        try {
            integrationSettings.value = await apiRequest<IntegrationSettings>('/api/integration-settings', {
                method: 'PATCH',
                tenant: tenantSlug.value,
                body: payload,
            });
            await refreshDashboard();
            notify('Settings saved');
        } catch (caught) {
            error.value = caught instanceof Error ? caught.message : 'Request failed';
            notify(error.value, 'error');
            throw caught;
        } finally {
            busy.value = false;
        }
    }

    return {
        user,
        tenant,
        company,
        stats,
        customers,
        leads,
        tasks,
        channels,
        conversations,
        messages,
        aiAgents,
        aiRuns,
        knowledgeDocuments,
        auditLogs,
        tenantUsers,
        integrationSettings,
        toasts,
        leadStatus,
        selectedConversationId,
        busy,
        error,
        openTasks,
        filteredLeads,
        selectedConversation,
        selectedMessages,
        aiHandoffs,
        apiHeader,
        hasData,
        hydrateBootstrap,
        notify,
        dismissToast,
        selectConversation,
        refreshDashboard,
        createCustomer,
        createLead,
        updateCompany,
        updateLeadStatus,
        createTask,
        updateTaskStatus,
        generateAiDraft,
        replyToConversation,
        syncChatwoot,
        updateAiAgent,
        indexKnowledgeText,
        uploadKnowledgeFile,
        createTenantUser,
        updateTenantUser,
        loadIntegrationSettings,
        testIntegrationConnection,
        updateIntegrationSettings,
    };
});