<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Building2, ChevronRight, MessageSquare, Plug } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import DataTable from '@/components/dashboard/DataTable.vue';
import SearchInput from '@/components/dashboard/SearchInput.vue';
import TableFiltersButton from '@/components/dashboard/TableFiltersButton.vue';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

defineOptions({ layout: SuperAdminLayout });

type BusinessType = { id: number; key: string; name: string; is_active: boolean; default_modules: string[] };
type IntegrationRequestRow = {
    id: number; tenant_name: string | null; company_name: string | null; requested_by: string | null;
    platform_name: string; platform_url: string | null; scenario_description: string | null; status: string;
    assigned_admin: string | null; cost_estimate: number | null; dev_time_estimate: string | null; created_at: string;
};

const activeTab = ref('types');
const loading = ref(true);
const businessTypes = ref<BusinessType[]>([]);
const requests = ref<IntegrationRequestRow[]>([]);

const STATUS_LABELS: Record<string, string> = {
    new: 'Новая заявка', reviewing: 'На рассмотрении', needs_info: 'Нужна доп. информация',
    possible: 'Интеграция возможна', needs_pricing: 'Требуется оценка стоимости', agreed: 'Согласовано',
    in_development: 'В разработке', testing: 'На тестировании', connected: 'Подключено', impossible: 'Интеграция невозможна',
};

async function load(): Promise<void> {
    loading.value = true;
    try {
        const [typesData, requestsData] = await Promise.all([
            apiRequest<{ business_types: BusinessType[]; modules: Record<string, string> }>('/api/admin/business-types'),
            apiRequest<IntegrationRequestRow[]>('/api/admin/integration-requests'),
        ]);
        businessTypes.value = typesData.business_types;
        requests.value = requestsData;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить данные');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

const search = ref('');
const statusFilter = ref<'all' | 'active' | 'inactive'>('all');

const filteredTypes = computed(() => businessTypes.value.filter((type) => {
    if (statusFilter.value === 'active' && ! type.is_active) return false;
    if (statusFilter.value === 'inactive' && type.is_active) return false;
    const q = search.value.trim().toLowerCase();
    if (! q) return true;
    return type.name.toLowerCase().includes(q) || type.key.toLowerCase().includes(q);
}));

const activeFilterCount = computed(() => (statusFilter.value !== 'all' ? 1 : 0));

function resetFilters(): void {
    statusFilter.value = 'all';
}

function openType(id: number): void {
    router.visit(`/super-admin/business-modules/${id}`);
}

async function updateRequestStatus(row: IntegrationRequestRow, status: string): Promise<void> {
    try {
        await apiRequest(`/api/admin/integration-requests/${row.id}`, { method: 'PATCH', body: { status } });
        row.status = status;
        toast.success('Статус обновлён');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось обновить статус');
    }
}

function statusTone(status: string): 'green' | 'blue' | 'amber' | 'red' {
    if (status === 'connected') return 'green';
    if (status === 'impossible') return 'red';
    if (['agreed', 'in_development', 'testing', 'possible'].includes(status)) return 'blue';
    return 'amber';
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}
</script>

<template>
    <div>
        <h2 class="font-display text-xl font-bold ui-text">Отраслевые модули и интеграции</h2>
        <p class="mt-1 text-sm ui-subtle">Сферы бизнеса, модули по умолчанию для каждой сферы, очередь заявок на интеграцию с CRM/1С/другими системами.</p>
    </div>

    <div v-if="loading" class="mt-6 space-y-3">
        <Skeleton class="h-10 w-64 rounded-lg" />
        <Skeleton class="h-64 rounded-xl" />
    </div>

    <Tabs v-else v-model="activeTab" class="mt-6">
        <TooltipProvider :delay-duration="200">
            <TabsList>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <TabsTrigger value="types" aria-label="Сферы и модули"><Building2 class="h-4 w-4" /></TabsTrigger>
                    </TooltipTrigger>
                    <TooltipContent>Сферы и модули</TooltipContent>
                </Tooltip>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <TabsTrigger value="requests" aria-label="Заявки на интеграцию"><Plug class="h-4 w-4" /></TabsTrigger>
                    </TooltipTrigger>
                    <TooltipContent>Заявки на интеграцию ({{ requests.length }})</TooltipContent>
                </Tooltip>
            </TabsList>
        </TooltipProvider>

        <TabsContent value="types" class="mt-4">
            <DataTable
                :row-count="filteredTypes.length"
                :column-count="4"
                empty-message="Сферы бизнеса не найдены"
                min-width="min-w-[48rem]"
            >
                <template #toolbar>
                    <div class="flex flex-wrap items-center gap-3">
                        <SearchInput v-model="search" placeholder="Поиск по названию или ключу..." />
                        <TableFiltersButton :active-count="activeFilterCount" @reset="resetFilters">
                            <label class="block">
                                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Статус</span>
                                <Select v-model="statusFilter">
                                    <SelectTrigger class="h-9 w-full"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Все сферы</SelectItem>
                                        <SelectItem value="active">Активные</SelectItem>
                                        <SelectItem value="inactive">Отключённые</SelectItem>
                                    </SelectContent>
                                </Select>
                            </label>
                        </TableFiltersButton>
                    </div>
                    <span class="text-sm ui-subtle">{{ filteredTypes.length }} из {{ businessTypes.length }}</span>
                </template>

                <template #thead>
                    <th class="p-4">Сфера бизнеса</th>
                    <th class="p-4">Модули по умолчанию</th>
                    <th class="p-4">Статус</th>
                    <th class="p-4"></th>
                </template>

                <tr
                    v-for="type in filteredTypes" :key="type.id"
                    class="group cursor-pointer transition hover:bg-muted"
                    @click="openType(type.id)"
                >
                    <td class="p-4">
                        <div class="text-sm font-semibold ui-text group-hover:text-primary">{{ type.name }}</div>
                        <div class="font-mono text-xs ui-subtle">{{ type.key }}</div>
                    </td>
                    <td class="p-4">
                        <Badge tone="neutral">{{ type.default_modules.length }} модул{{ type.default_modules.length === 1 ? 'ь' : type.default_modules.length >= 2 && type.default_modules.length <= 4 ? 'я' : 'ей' }}</Badge>
                    </td>
                    <td class="p-4">
                        <Badge :tone="type.is_active ? 'green' : 'neutral'">{{ type.is_active ? 'Активна' : 'Отключена' }}</Badge>
                    </td>
                    <td class="p-4 text-right">
                        <ChevronRight class="ml-auto h-4 w-4 ui-subtle transition group-hover:translate-x-0.5 group-hover:text-primary" />
                    </td>
                </tr>
            </DataTable>
        </TabsContent>

        <TabsContent value="requests" class="mt-4">
            <div v-if="requests.length" class="space-y-3">
                <div v-for="row in requests" :key="row.id" class="rounded-xl border border-border bg-card p-4">
                    <div class="mb-2 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="flex items-center gap-2 font-medium ui-text">
                                <MessageSquare class="h-4 w-4 text-primary" />{{ row.platform_name }}
                                <Badge :tone="statusTone(row.status)">{{ STATUS_LABELS[row.status] ?? row.status }}</Badge>
                            </p>
                            <p class="mt-0.5 text-xs ui-subtle">
                                {{ row.company_name ?? row.tenant_name }} · {{ row.requested_by ?? '—' }} · {{ formatDate(row.created_at) }}
                            </p>
                        </div>
                        <Select :model-value="row.status" @update:model-value="(v) => updateRequestStatus(row, String(v))">
                            <SelectTrigger class="h-8 w-56 text-xs"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="(label, key) in STATUS_LABELS" :key="key" :value="key">{{ label }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <p v-if="row.scenario_description" class="text-sm ui-text">{{ row.scenario_description }}</p>
                    <p v-if="row.platform_url" class="mt-1 text-xs ui-subtle">{{ row.platform_url }}</p>
                </div>
            </div>
            <p v-else class="text-xs ui-subtle">Заявок пока нет.</p>
        </TabsContent>
    </Tabs>
</template>
