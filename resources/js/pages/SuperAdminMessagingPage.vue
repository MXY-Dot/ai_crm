<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Mail, Send } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import DataTable from '@/components/dashboard/DataTable.vue';
import SearchInput from '@/components/dashboard/SearchInput.vue';
import TableFiltersButton from '@/components/dashboard/TableFiltersButton.vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';

defineOptions({ layout: SuperAdminLayout });

type LogRow = {
    id: number;
    tenant: { id: number; name: string } | null;
    channel: 'mail' | 'telegram';
    recipient: string;
    subject: string | null;
    status: 'sent' | 'failed' | 'blocked';
    error: string | null;
    created_at: string;
};

type CompanyRow = { id: number; name: string; email_enabled: boolean; telegram_enabled: boolean };

const STATUS_LABELS: Record<LogRow['status'], string> = { sent: 'Отправлено', failed: 'Ошибка', blocked: 'Заблокировано' };
const STATUS_TONE: Record<LogRow['status'], 'green' | 'red' | 'amber'> = { sent: 'green', failed: 'red', blocked: 'amber' };

const rows = ref<LogRow[]>([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
const loading = ref(true);
const search = ref('');
const channelTab = ref('all');
const statusFilter = ref('all');
const tenantFilter = ref('all');
const page = ref(1);

const companies = ref<CompanyRow[]>([]);
const companiesLoading = ref(true);
const toggleBusy = ref<number | null>(null);

function formatTime(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

async function loadLogs(): Promise<void> {
    loading.value = true;
    try {
        const params = new URLSearchParams({ page: String(page.value) });
        if (search.value.trim()) params.set('search', search.value.trim());
        if (channelTab.value !== 'all') params.set('channel', channelTab.value);
        if (statusFilter.value !== 'all') params.set('status', statusFilter.value);
        if (tenantFilter.value !== 'all') params.set('tenant_id', tenantFilter.value);

        const response = await apiRequest<{ data: LogRow[]; meta: typeof meta.value }>(`/api/admin/messaging/logs?${params.toString()}`);
        rows.value = response.data;
        meta.value = response.meta;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить журнал');
    } finally {
        loading.value = false;
    }
}

async function loadCompanies(): Promise<void> {
    companiesLoading.value = true;
    try {
        companies.value = await apiRequest<CompanyRow[]>('/api/admin/messaging/companies');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить компании');
    } finally {
        companiesLoading.value = false;
    }
}

async function toggleChannel(company: CompanyRow, channel: 'email' | 'telegram', enabled: boolean): Promise<void> {
    toggleBusy.value = company.id;
    const key = channel === 'email' ? 'email_enabled' : 'telegram_enabled';
    const previous = company[key];
    company[key] = enabled;
    try {
        await apiRequest(`/api/admin/messaging/companies/${company.id}/toggle`, { method: 'PATCH', body: { channel, enabled } });
        toast.success(`${channel === 'email' ? 'Email' : 'Telegram'} для «${company.name}» ${enabled ? 'включён' : 'отключён'}`);
    } catch (error) {
        company[key] = previous;
        toast.error(error instanceof Error ? error.message : 'Не удалось изменить');
    } finally {
        toggleBusy.value = null;
    }
}

let searchTimer: number | undefined;
watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => { page.value = 1; loadLogs(); }, 350);
});
watch([channelTab, statusFilter, tenantFilter], () => { page.value = 1; loadLogs(); });
watch(page, loadLogs);

onMounted(() => {
    loadLogs();
    loadCompanies();
});

const activeFilterCount = computed(() => [statusFilter.value, tenantFilter.value].filter((v) => v !== 'all').length);

function resetFilters(): void {
    statusFilter.value = 'all';
    tenantFilter.value = 'all';
}
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-display text-xl font-bold ui-text">Рассылки: Email и Telegram</h2>
            <p class="mt-1 text-sm ui-subtle">Журнал всех исходящих писем и Telegram-сообщений платформы, по компаниям, плюс возможность отключить канал для конкретной компании.</p>
        </div>
    </div>

    <Card title="Компании" subtitle="Включение/отключение каналов рассылки по каждой компании отдельно.">
        <DataTable embedded :loading="companiesLoading" :row-count="companies.length" :column-count="3" empty-message="Компаний пока нет." min-width="min-w-full">
            <template #thead>
                <th class="p-3">Компания</th>
                <th class="p-3 text-center">Email</th>
                <th class="p-3 text-center">Telegram</th>
            </template>
            <tr v-for="company in companies" :key="company.id">
                <td class="p-3 text-sm ui-text">{{ company.name }}</td>
                <td class="p-3 text-center">
                    <Switch
                        :model-value="company.email_enabled"
                        :disabled="toggleBusy === company.id"
                        @update:model-value="(v) => toggleChannel(company, 'email', v)"
                    />
                </td>
                <td class="p-3 text-center">
                    <Switch
                        :model-value="company.telegram_enabled"
                        :disabled="toggleBusy === company.id"
                        @update:model-value="(v) => toggleChannel(company, 'telegram', v)"
                    />
                </td>
            </tr>
        </DataTable>
    </Card>

    <DataTable
        :loading="loading"
        :row-count="rows.length"
        :column-count="6"
        empty-message="Отправлений не найдено"
        :meta="meta"
        item-label="отправлений"
        min-width="min-w-[64rem]"
        @update:page="page = $event"
    >
        <template #toolbar>
            <div class="flex flex-wrap items-center gap-3">
                <SearchInput v-model="search" placeholder="Поиск по получателю или теме..." />
                <TableFiltersButton :active-count="activeFilterCount" @reset="resetFilters">
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Компания</span>
                        <Select v-model="tenantFilter">
                            <SelectTrigger class="h-9 w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Все компании</SelectItem>
                                <SelectItem v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Статус</span>
                        <Select v-model="statusFilter">
                            <SelectTrigger class="h-9 w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Все статусы</SelectItem>
                                <SelectItem v-for="(label, key) in STATUS_LABELS" :key="key" :value="key">{{ label }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </label>
                </TableFiltersButton>
            </div>
            <Tabs v-model="channelTab">
                <TabsList>
                    <TabsTrigger value="all">Все</TabsTrigger>
                    <TabsTrigger value="mail"><Mail class="h-3.5 w-3.5" />Email</TabsTrigger>
                    <TabsTrigger value="telegram"><Send class="h-3.5 w-3.5" />Telegram</TabsTrigger>
                </TabsList>
            </Tabs>
        </template>

        <template #thead>
            <th class="p-4">Компания</th>
            <th class="p-4">Канал</th>
            <th class="p-4">Получатель</th>
            <th class="p-4">Тема / текст</th>
            <th class="p-4">Статус</th>
            <th class="p-4">Когда</th>
        </template>

        <tr v-for="row in rows" :key="row.id">
            <td class="p-4 text-sm ui-text">{{ row.tenant?.name ?? '—' }}</td>
            <td class="p-4">
                <span class="inline-flex items-center gap-1.5 text-xs ui-subtle">
                    <Mail v-if="row.channel === 'mail'" class="h-3.5 w-3.5" />
                    <Send v-else class="h-3.5 w-3.5" />
                    {{ row.channel === 'mail' ? 'Email' : 'Telegram' }}
                </span>
            </td>
            <td class="p-4 font-mono text-xs ui-subtle">{{ row.recipient }}</td>
            <td class="p-4 max-w-xs truncate text-sm ui-text" :title="row.subject ?? ''">{{ row.subject ?? '—' }}</td>
            <td class="p-4">
                <Badge :tone="STATUS_TONE[row.status]">{{ STATUS_LABELS[row.status] }}</Badge>
                <span v-if="row.error" class="mt-0.5 block max-w-xs truncate text-xs text-destructive" :title="row.error">{{ row.error }}</span>
            </td>
            <td class="p-4 font-mono text-xs ui-subtle">{{ formatTime(row.created_at) }}</td>
        </tr>
    </DataTable>
</template>
