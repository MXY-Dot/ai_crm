import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { router } from '@inertiajs/vue3';
import { apiRequest } from '../lib/apiClient';
import { toast } from 'vue-sonner';
import { pathForRecord } from '../lib/pages';
import { useLocaleStore } from './locale';

export type Toast = { id: number; tone: 'success' | 'error'; message: string };

export type Customer = {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    source: string | null;
    created_at?: string | null;
    segment?: string | null;
    is_business?: boolean;
    city?: string | null;
    birth_year?: number | null;
    vip_score?: number | null;
    vip_status?: string | null;
    vip_reason?: string | null;
    purchases_count?: number | null;
    total_revenue?: number | null;
    last_purchase_at?: string | null;
};

export type CustomerFeedback = {
    id: number;
    customer_id: number;
    satisfaction: 'positive' | 'neutral' | 'negative';
    notes: string | null;
    created_at: string;
};

export type Lead = {
    id: number;
    customer_id: number | null;
    title: string;
    status: string;
    source: string | null;
    score: number;
    next_action: string | null;
    ai_summary: string | null;
    created_at?: string | null;
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
    health_status?: 'up' | 'down' | null;
};

export type Conversation = {
    id: number;
    external_id?: string | null;
    subject: string;
    status: string;
    priority: string;
    last_message_at: string | null;
    ai_summary: string | null;
    labels?: string[] | null;
    channel?: { id: number; provider: string; name: string } | null;
    customer?: { id: number; name: string; avatar_url?: string | null } | null;
    lead?: { id: number; title: string } | null;
};

export type MessageAttachment = {
    url: string;
    path: string;
    type: 'photo' | 'voice' | 'document' | 'video';
    filename?: string | null;
    mime?: string | null;
};

export type Message = {
    id: number;
    conversation_id: number;
    sender_type: string;
    sender_name: string | null;
    body: string;
    sent_at: string | null;
    meta?: { attachment?: MessageAttachment | null } | null;
};

export type AiAgent = {
    id: number;
    name: string;
    provider: string;
    model: string | null;
    status: string;
    handoff_threshold: number;
    goal: string | null;
    persona: string | null;
    max_discount_percent: number | null;
    forbidden_topics: string[] | null;
    instructions: string | null;
    channels: string[] | null;
};

export type AiAgentPayload = {
    name?: string;
    status?: 'active' | 'paused' | 'disabled';
    handoff_threshold?: number;
    goal?: string | null;
    persona?: string | null;
    max_discount_percent?: number | null;
    forbidden_topics?: string[] | null;
    instructions?: string | null;
    model?: string | null;
    channels?: string[];
};

export type KnowledgeChunk = {
    id: number;
    position: number;
    content: string;
};

export type KnowledgeDocumentDetail = {
    id: number;
    ai_agent_id: number | null;
    title: string;
    source_type: string;
    file_name: string | null;
    mime_type: string | null;
    status: string;
    version: number;
    summary: string | null;
    indexed_at: string | null;
    updated_at: string | null;
    meta: { storage_path?: string } | null;
    chunks: KnowledgeChunk[];
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

export type TenantUserRole = 'super_admin' | 'owner' | 'manager' | 'operator' | 'specialist' | 'accountant';

export type TenantUser = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    role: TenantUserRole;
    status: 'active' | 'invited' | 'disabled';
    last_login_at: string | null;
    employee_id: number | null;
};

export type TenantUserPayload = {
    name?: string;
    email?: string;
    phone?: string | null;
    role?: TenantUserRole;
    status?: 'active' | 'invited' | 'disabled';
    password?: string;
    employee_id?: number | null;
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
        auto_reply_mode: 'off' | 'priority' | 'always';
    };
    telegram?: {
        bot_token_configured: boolean;
        bot_token_mask: string | null;
        webhook_secret_configured: boolean;
        webhook_secret_mask: string | null;
        webhook_url: string;
    };
    telegram_webhook?: { ok: boolean; message: string } | null;
    whatsapp?: {
        access_token_configured: boolean;
        access_token_mask: string | null;
        phone_number_id: string | null;
        business_account_id: string | null;
        webhook_url: string;
    };
    instagram?: {
        page_access_token_configured: boolean;
        page_access_token_mask: string | null;
        business_account_id: string | null;
        webhook_url: string;
    };
    facebook?: {
        page_access_token_configured: boolean;
        page_access_token_mask: string | null;
        page_id: string | null;
        webhook_url: string;
    };
    alif?: {
        token_configured: boolean;
        token_mask: string | null;
        webhook_secret_configured: boolean;
        webhook_secret_mask: string | null;
        base_url: string;
        webhook_url_example: string;
    };
};

export type IntegrationTestResult = {
    ok: boolean;
    provider: 'dify' | 'chatwoot' | 'telegram' | 'whatsapp' | 'instagram' | 'facebook';
    status: string;
    message: string;
    checked_at: string;
    meta: Record<string, unknown>;
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
        auto_reply_mode?: 'off' | 'priority' | 'always';
    };
    telegram?: {
        bot_token?: string;
        webhook_secret?: string;
        auto_reply_enabled?: boolean;
    };
    whatsapp?: {
        access_token?: string;
        phone_number_id?: string;
        business_account_id?: string;
    };
    instagram?: {
        page_access_token?: string;
        business_account_id?: string;
    };
    facebook?: {
        page_access_token?: string;
        page_id?: string;
    };
    alif?: {
        token?: string;
        webhook_secret?: string;
        base_url?: string;
    };
};

export type IntegrationSettingsForm = {
    difyApiKey: string;
    difyTimeout: number;
    handoffThreshold: number;
    chatwootAccountId: number | null;
    chatwootApiToken: string;
    chatwootSecret: string;
    chatwootAutoReplyMode: 'off' | 'priority' | 'always';
    telegramBotToken: string;
    telegramSecret: string;
    telegramAutoReply: boolean;
};

export function buildIntegrationSettingsPayload(form: IntegrationSettingsForm): IntegrationSettingsPayload {
    return {
        dify: {
            api_key: form.difyApiKey || undefined,
            timeout: Number(form.difyTimeout),
            handoff_threshold: Number(form.handoffThreshold),
        },
        chatwoot: {
            account_id: form.chatwootAccountId || null,
            api_token: form.chatwootApiToken || undefined,
            webhook_secret: form.chatwootSecret || undefined,
            auto_reply_mode: form.chatwootAutoReplyMode,
        },
        telegram: {
            bot_token: form.telegramBotToken || undefined,
            webhook_secret: form.telegramSecret || undefined,
            auto_reply_enabled: form.telegramAutoReply,
        },
    };
}

export type WidgetLauncherIcon = 'chat' | 'message' | 'help';

export type WidgetSettings = {
    status: string;
    welcome_message: string | null;
    color: string;
    position: 'right' | 'left';
    launcher_icon: WidgetLauncherIcon;
    last_synced_at: string | null;
};

export type WidgetSettingsForm = {
    welcomeMessage: string;
    color: string;
    position: 'right' | 'left';
    launcherIcon: WidgetLauncherIcon;
};

export type WidgetToken = {
    id: number;
    label: string;
    token: string;
    embed_snippet: string;
    last_used_at: string | null;
    created_at: string;
};

export type AiRun = {
    id: number;
    status: string;
    confidence: number;
    intent: string | null;
    summary: string | null;
    next_action: string | null;
    finished_at: string | null;
    payload?: { detected_sentiment?: string | null; detected_language?: string | null } | null;
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
    brand_settings?: Record<string, unknown> | null;
    logo_url?: string | null;
};

export type CompanyPayload = Partial<Omit<CompanyProfile, 'id' | 'logo_url'>>;

export type ProfileUser = { id: number; name: string; email: string; role: string; phone: string | null; avatar_url: string | null; two_factor_enabled: boolean };

export type ProfilePayload = { name?: string; phone?: string | null };

export type Bootstrap = {
    user?: ProfileUser | null;
    tenant: { id: number; name: string; slug: string; status: string; trial_ends_at: string | null; settings: { billing?: { plan?: string } } | null } | null;
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

function selectedRecordId<T extends { id: number }>(currentId: number | null, records: T[]): number | null {
    return currentId !== null && records.some((record) => record.id === currentId) ? currentId : records[0]?.id ?? null;
}

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
    const widgetSettings = ref<WidgetSettings | null>(null);
    const widgetTokens = ref<WidgetToken[]>([]);
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
        if (tone === 'error') toast.error(message);
        else toast.success(message);
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
        selectedConversationId.value = selectedRecordId(selectedConversationId.value, conversations.value);
        selectedCustomerId.value = selectedRecordId(selectedCustomerId.value, customers.value);
        selectedLeadId.value = selectedRecordId(selectedLeadId.value, leads.value);
    }
    function selectConversation(id: number): void {
        selectedConversationId.value = id;
    }

    function openLead(id: number): void {
        selectedLeadId.value = id;
        router.visit(pathForRecord('lead'), { preserveState: true });
    }

    function openCustomer(id: number): void {
        selectedCustomerId.value = id;
        router.visit(pathForRecord('customer'), { preserveState: true });
    }

    function openConversation(id: number): void {
        selectedConversationId.value = id;
        router.visit(pathForRecord('conversation'), { preserveState: true });
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
        selectedConversationId.value = selectedRecordId(selectedConversationId.value, conversations.value);
        selectedCustomerId.value = selectedRecordId(selectedCustomerId.value, customers.value);
        selectedLeadId.value = selectedRecordId(selectedLeadId.value, leads.value);
    }

    async function mutate(action: () => Promise<unknown>, successKey?: string): Promise<void> {
        busy.value = true;
        error.value = null;
        const locale = useLocaleStore();
        try {
            await action();
            await refreshDashboard();
            notify(locale.t(successKey ?? 'toast.genericSuccess'));
        } catch (caught) {
            error.value = caught instanceof Error ? caught.message : locale.t('toast.genericError');
            notify(error.value, 'error');
            throw caught;
        } finally {
            busy.value = false;
        }
    }

    async function createCustomer(payload: { name: string; phone?: string; email?: string; source?: string; is_business?: boolean }): Promise<void> {
        if (! companyId.value) return;
        await mutate(() => apiRequest('/api/customers', {
            method: 'POST',
            tenant: tenantSlug.value,
            body: { company_id: companyId.value, ...payload },
        }), 'toast.customerCreated');
    }

    async function updateCustomer(id: number, payload: Partial<{ name: string; phone: string; email: string; is_business: boolean; city: string | null; birth_year: number | null }>): Promise<void> {
        await mutate(() => apiRequest(`/api/customers/${id}`, {
            method: 'PATCH',
            tenant: tenantSlug.value,
            body: payload,
        }), 'toast.customerUpdated');
    }

    async function recordCustomerFeedback(payload: { customer_id: number; lead_id?: number | null; conversation_id?: number | null; satisfaction: 'positive' | 'neutral' | 'negative'; notes?: string }): Promise<void> {
        await mutate(() => apiRequest('/api/customer-feedback', {
            method: 'POST',
            tenant: tenantSlug.value,
            body: payload,
        }), 'toast.feedbackRecorded');
    }

    async function createLead(payload: { title: string; source?: string; score?: number; customer_id?: number | null }): Promise<void> {
        if (! companyId.value) return;
        await mutate(() => apiRequest('/api/leads', {
            method: 'POST',
            tenant: tenantSlug.value,
            body: { company_id: companyId.value, status: 'new', ...payload },
        }), 'toast.leadCreated');
    }

    async function updateCompany(id: number, payload: CompanyPayload): Promise<void> {
        await mutate(() => apiRequest(`/api/companies/${id}`, {
            method: 'PATCH',
            tenant: tenantSlug.value,
            body: payload,
        }), 'toast.companyUpdated');
    }

    async function uploadCompanyLogo(id: number, file: File): Promise<void> {
        const body = new FormData();
        body.append('photo', file);

        await mutate(() => apiRequest(`/api/companies/${id}/logo`, {
            method: 'POST',
            tenant: tenantSlug.value,
            body,
        }), 'toast.logoUpdated');
    }

    async function updateProfile(payload: ProfilePayload): Promise<void> {
        await mutate(() => apiRequest('/api/profile', {
            method: 'PATCH',
            body: payload,
        }), 'toast.profileUpdated');
    }

    async function uploadAvatar(file: File): Promise<void> {
        const body = new FormData();
        body.append('photo', file);

        await mutate(() => apiRequest('/api/profile/avatar', {
            method: 'POST',
            body,
        }), 'toast.avatarUpdated');
    }

    async function updateLeadStatus(id: number, status: string, lostReason?: string): Promise<void> {
        await mutate(() => apiRequest(`/api/leads/${id}`, {
            method: 'PATCH',
            tenant: tenantSlug.value,
            body: lostReason ? { status, lost_reason: lostReason } : { status },
        }), 'toast.leadStatusUpdated');
    }

    async function createTask(payload: { title: string; priority?: string; lead_id?: number | null }): Promise<void> {
        if (! companyId.value) return;
        await mutate(() => apiRequest('/api/tasks', {
            method: 'POST',
            tenant: tenantSlug.value,
            body: { company_id: companyId.value, status: 'open', priority: 'normal', ...payload },
        }), 'toast.taskCreated');
    }

    async function updateTaskStatus(id: number, status: string): Promise<void> {
        await mutate(() => apiRequest(`/api/tasks/${id}`, {
            method: 'PATCH',
            tenant: tenantSlug.value,
            body: { status },
        }), 'toast.taskUpdated');
    }

    async function generateAiDraft(id: number): Promise<void> {
        await mutate(() => apiRequest(`/api/conversations/${id}/ai-draft`, {
            method: 'POST',
            tenant: tenantSlug.value,
        }), 'toast.aiDraftGenerated');
    }
    async function replyToConversation(id: number, body: string, attachment?: MessageAttachment | null): Promise<void> {
        await mutate(() => apiRequest(`/api/conversations/${id}/reply`, {
            method: 'POST',
            tenant: tenantSlug.value,
            body: { body, attachment: attachment ?? undefined },
        }), 'toast.replySent');
    }

    async function uploadConversationAttachment(conversationId: number, file: File, type: MessageAttachment['type']): Promise<MessageAttachment> {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', type);

        return apiRequest<MessageAttachment>(`/api/conversations/${conversationId}/attachments`, {
            method: 'POST',
            tenant: tenantSlug.value,
            body: formData,
        });
    }

    async function syncChatwoot(): Promise<void> {
        await mutate(() => apiRequest('/api/chatwoot/sync', {
            method: 'POST',
            tenant: tenantSlug.value,
        }), 'toast.chatwootSynced');
    }

    async function updatePlan(plan: string): Promise<void> {
        if (! tenant.value) return;

        await mutate(() => apiRequest(`/api/tenants/${tenant.value!.id}`, {
            method: 'PATCH',
            tenant: tenantSlug.value,
            body: { settings: { ...(tenant.value!.settings ?? {}), billing: { ...(tenant.value!.settings?.billing ?? {}), plan } } },
        }), 'toast.planUpdated');
    }

    async function createAiAgent(payload: { name: string; status?: string; handoff_threshold?: number; instructions?: string; model?: string; channels?: string[] }, documentIds: number[] = []): Promise<void> {
        await mutate(async () => {
            const agent = await apiRequest<AiAgent>('/api/ai-agents', {
                method: 'POST',
                tenant: tenantSlug.value,
                body: payload,
            });

            await Promise.all(documentIds.map((id) => apiRequest(`/api/knowledge-documents/${id}`, {
                method: 'PATCH',
                tenant: tenantSlug.value,
                body: { ai_agent_id: agent.id },
            })));
        }, 'toast.agentCreated');
    }

    async function updateAiAgent(id: number, payload: AiAgentPayload): Promise<void> {
        await mutate(() => apiRequest(`/api/ai-agents/${id}`, {
            method: 'PATCH',
            tenant: tenantSlug.value,
            body: payload,
        }), 'toast.agentUpdated');
    }

    async function deleteAiAgent(id: number): Promise<void> {
        await mutate(() => apiRequest(`/api/ai-agents/${id}`, {
            method: 'DELETE',
            tenant: tenantSlug.value,
        }), 'toast.agentDeleted');
    }

    async function syncAgentKnowledge(agentId: number, documentIds: number[]): Promise<void> {
        await mutate(async () => {
            const current = knowledgeDocuments.value.filter((doc) => doc.ai_agent_id === agentId).map((doc) => doc.id);
            const toAttach = documentIds.filter((id) => ! current.includes(id));
            const toDetach = current.filter((id) => ! documentIds.includes(id));

            await Promise.all([
                ...toAttach.map((id) => apiRequest(`/api/knowledge-documents/${id}`, {
                    method: 'PATCH',
                    tenant: tenantSlug.value,
                    body: { ai_agent_id: agentId },
                })),
                ...toDetach.map((id) => apiRequest(`/api/knowledge-documents/${id}`, {
                    method: 'PATCH',
                    tenant: tenantSlug.value,
                    body: { ai_agent_id: null },
                })),
            ]);
        }, 'toast.agentUpdated');
    }

    async function indexKnowledgeText(payload: { title: string; content: string; ai_agent_id?: number | null }): Promise<void> {
        if (! companyId.value) return;
        await mutate(() => apiRequest('/api/knowledge-documents/index-text', {
            method: 'POST',
            tenant: tenantSlug.value,
            body: { company_id: companyId.value, source_type: 'manual', ...payload },
        }), 'toast.knowledgeIndexed');
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
        }), 'toast.fileUploaded');
    }

    async function fetchKnowledgeUrl(payload: { title?: string; url: string; ai_agent_id?: number | null }): Promise<void> {
        if (! companyId.value) return;
        await mutate(() => apiRequest('/api/knowledge-documents/fetch-url', {
            method: 'POST',
            tenant: tenantSlug.value,
            body: { company_id: companyId.value, ...payload },
        }), 'toast.knowledgeIndexed');
    }

    async function deleteKnowledgeDocument(id: number): Promise<void> {
        await mutate(() => apiRequest(`/api/knowledge-documents/${id}`, {
            method: 'DELETE',
            tenant: tenantSlug.value,
        }), 'toast.documentDeleted');
    }

    function fetchKnowledgeDocument(id: number): Promise<KnowledgeDocumentDetail> {
        return apiRequest(`/api/knowledge-documents/${id}`, { tenant: tenantSlug.value });
    }

    async function updateKnowledgeDocumentContent(id: number, payload: { title?: string; content: string }): Promise<void> {
        await mutate(() => apiRequest(`/api/knowledge-documents/${id}/content`, {
            method: 'PATCH',
            tenant: tenantSlug.value,
            body: payload,
        }), 'toast.documentUpdated');
    }

    async function createTenantUser(payload: Required<Pick<TenantUserPayload, 'name' | 'email' | 'role'>> & TenantUserPayload): Promise<void> {
        await mutate(() => apiRequest('/api/tenant-users', {
            method: 'POST',
            tenant: tenantSlug.value,
            body: payload,
        }), 'toast.userInvited');
    }

    async function updateTenantUser(id: number, payload: TenantUserPayload): Promise<void> {
        await mutate(() => apiRequest(`/api/tenant-users/${id}`, {
            method: 'PATCH',
            tenant: tenantSlug.value,
            body: payload,
        }), 'toast.userUpdated');
    }

    async function loadIntegrationSettings(): Promise<void> {
        integrationSettings.value = await apiRequest<IntegrationSettings>('/api/integration-settings', {
            tenant: tenantSlug.value,
        });
    }

    async function loadWidgetSettings(): Promise<void> {
        widgetSettings.value = await apiRequest<WidgetSettings>('/api/widget-settings', {
            tenant: tenantSlug.value,
        });
    }

    async function updateWidgetSettings(form: WidgetSettingsForm): Promise<void> {
        await mutate(async () => {
            widgetSettings.value = await apiRequest<WidgetSettings>('/api/widget-settings', {
                method: 'PATCH',
                tenant: tenantSlug.value,
                body: {
                    welcome_message: form.welcomeMessage || null,
                    color: form.color,
                    position: form.position,
                    launcher_icon: form.launcherIcon,
                },
            });
        }, 'toast.settingsSaved');
    }

    async function loadWidgetTokens(): Promise<void> {
        const response = await apiRequest<{ data: WidgetToken[] }>('/api/widget-tokens', {
            tenant: tenantSlug.value,
        });
        widgetTokens.value = response.data;
    }

    async function createWidgetToken(label: string): Promise<void> {
        await mutate(async () => {
            const token = await apiRequest<WidgetToken>('/api/widget-tokens', {
                method: 'POST',
                tenant: tenantSlug.value,
                body: { label },
            });
            widgetTokens.value = [token, ...widgetTokens.value];
        }, 'toast.settingsSaved');
    }

    async function deleteWidgetToken(id: number): Promise<void> {
        await mutate(async () => {
            await apiRequest(`/api/widget-tokens/${id}`, {
                method: 'DELETE',
                tenant: tenantSlug.value,
            });
            widgetTokens.value = widgetTokens.value.filter((token) => token.id !== id);
        }, 'toast.settingsSaved');
    }

    async function testIntegrationConnection(payload: IntegrationSettingsPayload & { provider: 'dify' | 'chatwoot' | 'telegram' | 'whatsapp' | 'instagram' | 'facebook' }): Promise<IntegrationTestResult> {
        try {
            const result = await apiRequest<IntegrationTestResult>('/api/integration-settings/test', {
                method: 'POST',
                tenant: tenantSlug.value,
                body: payload,
            });
            notify(result.message, result.ok ? 'success' : 'error');

            // A successful test on a direct Meta channel (whatsapp/instagram/facebook)
            // flips its Channel row to 'connected' server-side (see
            // IntegrationSettingsController::markChannelConnected()) -- refresh so the
            // channel card reflects that without waiting for a full page reload.
            if (result.ok) await refreshDashboard();

            return result;
        } catch (caught) {
            const locale = useLocaleStore();
            notify(caught instanceof Error ? caught.message : locale.t('toast.connectionTestFailed'), 'error');
            throw caught;
        }
    }

    async function updateIntegrationSettings(payload: IntegrationSettingsPayload): Promise<void> {
        busy.value = true;
        error.value = null;
        const locale = useLocaleStore();
        try {
            integrationSettings.value = await apiRequest<IntegrationSettings>('/api/integration-settings', {
                method: 'PATCH',
                tenant: tenantSlug.value,
                body: payload,
            });
            await refreshDashboard();
            notify(locale.t('toast.settingsSaved'));

            if (integrationSettings.value.telegram_webhook) {
                const webhook = integrationSettings.value.telegram_webhook;
                notify(webhook.message, webhook.ok ? 'success' : 'error');
            }
        } catch (caught) {
            error.value = caught instanceof Error ? caught.message : locale.t('toast.genericError');
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
        widgetSettings,
        widgetTokens,
        leadStatus,
        selectedConversationId,
        selectedCustomerId,
        selectedLeadId,
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
        selectConversation,
        openLead,
        openCustomer,
        openConversation,
        refreshDashboard,
        createCustomer,
        updateCustomer,
        recordCustomerFeedback,
        createLead,
        updateCompany,
        uploadCompanyLogo,
        updateProfile,
        uploadAvatar,
        updateLeadStatus,
        createTask,
        updateTaskStatus,
        generateAiDraft,
        replyToConversation,
        uploadConversationAttachment,
        syncChatwoot,
        updatePlan,
        createAiAgent,
        updateAiAgent,
        syncAgentKnowledge,
        indexKnowledgeText,
        uploadKnowledgeFile,
        fetchKnowledgeUrl,
        deleteKnowledgeDocument,
        fetchKnowledgeDocument,
        updateKnowledgeDocumentContent,
        createTenantUser,
        updateTenantUser,
        loadIntegrationSettings,
        testIntegrationConnection,
        updateIntegrationSettings,
        loadWidgetSettings,
        updateWidgetSettings,
        loadWidgetTokens,
        createWidgetToken,
        deleteWidgetToken,
    };
});