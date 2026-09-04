<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Building2, CalendarClock, CircleDollarSign, Download, MoreVertical, Plus, Trash2, TrendingUp, Users } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { planById, plans, type PlanId } from '@/lib/plans';
import DataTable from '@/components/dashboard/DataTable.vue';
import KpiCard from '@/components/dashboard/KpiCard.vue';
import SearchInput from '@/components/dashboard/SearchInput.vue';
import TableFiltersButton from '@/components/dashboard/TableFiltersButton.vue';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';

defineOptions({ layout: SuperAdminLayout });

type Status = 'trial' | 'active' | 'paused' | 'blocked';

type Overview = {
    kpis: { mrr: number; arr: number; active_subscriptions: number; trial_subscriptions: number; trial_ending_soon: number };
    by_plan: { plan: PlanId; price: number; subscribers: number; revenue: number }[];
};

type SubscriptionRow = {
    id: number;
    name: string;
    slug: string;
    status: Status;
    created_at: string;
    trial_ends_at: string | null;
    plan: PlanId;
    users_count: number;
    channels_count: number;
    company: { id: number; name: string; industry: string | null; logo_url: string | null } | null;
    owner: { id: number; name: string; email: string; avatar_url: string | null } | null;
};

type ListResponse = { data: SubscriptionRow[]; meta: { current_page: number; last_page: number; per_page: number; total: number } };

const overview = ref<Overview | null>(null);
const overviewLoading = ref(true);

const rows = ref<SubscriptionRow[]>([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 });
const loading = ref(true);
const search = ref('');
const planFilter = ref('all');
const statusFilter = ref('all');
const page = ref(1);
const busy = ref(false);
const addOpen = ref(false);

const statusLabels: Record<Status, string> = {
    trial: 'Пробный период',
    active: 'Активна',
    paused: 'Приостановлена',
    blocked: 'Заблокирована',
};
const statusTone: Record<Status, 'green' | 'blue' | 'amber' | 'red'> = {
    active: 'green',
    trial: 'blue',
    paused: 'amber',
    blocked: 'red',
};

function formatMoney(value: number): string {
    return new Intl.NumberFormat('ru-RU').format(value) + ' $';
}
function formatDate(value: string | null): string {
    if (! value) return '—';
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value));
}

function openCompany(id: number): void {
    router.visit(`/super-admin/companies/${id}`);
}

async function loadOverview(): Promise<void> {
    overviewLoading.value = true;
    try {
        overview.value = await apiRequest<Overview>('/api/admin/billing/overview');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить показатели биллинга');
    } finally {
        overviewLoading.value = false;
    }
}

async function loadSubscriptions(): Promise<void> {
    loading.value = true;
    try {
        const params = new URLSearchParams({ page: String(page.value) });
        if (search.value.trim()) params.set('search', search.value.trim());
        if (planFilter.value !== 'all') params.set('plan', planFilter.value);
        if (statusFilter.value !== 'all') params.set('status', statusFilter.value);

        const response = await apiRequest<ListResponse>(`/api/admin/companies?${params.toString()}`);
        rows.value = response.data;
        meta.value = response.meta;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить подписки');
    } finally {
        loading.value = false;
    }
}

let searchTimer: number | undefined;
watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => { page.value = 1; loadSubscriptions(); }, 350);
});
watch([planFilter, statusFilter], () => { page.value = 1; loadSubscriptions(); });
watch(page, loadSubscriptions);

onMounted(() => {
    loadOverview();
    loadSubscriptions();
});

async function updateStatus(tenantId: number, status: Status): Promise<void> {
    busy.value = true;
    try {
        await apiRequest(`/api/admin/companies/${tenantId}/status`, { method: 'PATCH', body: { status } });
        toast.success('Статус подписки обновлён');
        await Promise.all([loadSubscriptions(), loadOverview()]);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось обновить статус');
    } finally {
        busy.value = false;
    }
}

async function updatePlanFor(tenantId: number, plan: PlanId): Promise<void> {
    busy.value = true;
    try {
        await apiRequest(`/api/admin/companies/${tenantId}/plan`, { method: 'PATCH', body: { plan } });
        toast.success('Тариф подписки обновлён');
        await Promise.all([loadSubscriptions(), loadOverview()]);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось обновить тариф');
    } finally {
        busy.value = false;
    }
}

const deleteTarget = ref<SubscriptionRow | null>(null);
const deleteConfirmText = ref('');
const deleteBusy = ref(false);

function nameOf(row: SubscriptionRow): string {
    return row.company?.name ?? row.name;
}

function askDelete(row: SubscriptionRow): void {
    deleteTarget.value = row;
    deleteConfirmText.value = '';
}

async function confirmDelete(): Promise<void> {
    if (! deleteTarget.value) return;
    deleteBusy.value = true;
    try {
        await apiRequest(`/api/admin/companies/${deleteTarget.value.id}`, { method: 'DELETE', body: { confirm_name: deleteConfirmText.value } });
        toast.success(`Подписка «${nameOf(deleteTarget.value)}» удалена`);
        deleteTarget.value = null;
        if (rows.value.length === 1 && meta.value.current_page > 1) page.value -= 1;
        await Promise.all([loadSubscriptions(), loadOverview()]);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось удалить подписку');
    } finally {
        deleteBusy.value = false;
    }
}

function exportCsv(): void {
    const header = ['Компания', 'Владелец', 'Email', 'Тариф', 'Статус', 'Регистрация', 'Триал до'];
    const lines = rows.value.map((row) => [
        row.company?.name ?? row.name,
        row.owner?.name ?? '',
        row.owner?.email ?? '',
        planById(row.plan).name,
        statusLabels[row.status],
        formatDate(row.created_at),
        formatDate(row.trial_ends_at),
    ]);
    const csv = [header, ...lines].map((cols) => cols.map((col) => `"${col.replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'subscriptions.csv';
    link.click();
    URL.revokeObjectURL(url);
}

const newCompany = reactive({
    company_name: '',
    industry: '',
    plan: 'starter' as PlanId,
    status: 'trial' as Status,
    owner_name: '',
    owner_email: '',
    owner_password: '',
});

async function createSubscription(): Promise<void> {
    if (! newCompany.company_name.trim() || ! newCompany.owner_name.trim() || ! newCompany.owner_email.trim()) return;

    busy.value = true;
    try {
        await apiRequest('/api/admin/companies', {
            method: 'POST',
            body: {
                company_name: newCompany.company_name.trim(),
                industry: newCompany.industry.trim() || undefined,
                plan: newCompany.plan,
                status: newCompany.status,
                owner_name: newCompany.owner_name.trim(),
                owner_email: newCompany.owner_email.trim(),
                owner_password: newCompany.owner_password.trim() || undefined,
            },
        });
        toast.success('Подписка создана');
        Object.assign(newCompany, { company_name: '', industry: '', plan: 'starter', status: 'trial', owner_name: '', owner_email: '', owner_password: '' });
        addOpen.value = false;
        page.value = 1;
        await Promise.all([loadSubscriptions(), loadOverview()]);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось создать подписку');
    } finally {
        busy.value = false;
    }
}

const maxRevenue = computed(() => Math.max(1, ...(overview.value?.by_plan.map((p) => p.revenue) ?? [1])));

const activeFilterCount = computed(() => [planFilter.value, statusFilter.value].filter((v) => v !== 'all').length);

function resetFilters(): void {
    planFilter.value = 'all';
    statusFilter.value = 'all';
}
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-display text-xl font-bold ui-text">Биллинг и подписки</h2>
            <p class="mt-1 text-sm ui-subtle">Тарифы всех компаний платформы, доход и управление подписками.</p>
        </div>
        <div class="flex gap-2">
            <Button variant="outline" size="sm" @click="exportCsv"><Download class="h-4 w-4" />Экспорт CSV</Button>
            <Button variant="primary" size="sm" @click="addOpen = true"><Plus class="h-4 w-4" />Добавить подписку</Button>
        </div>
    </div>

    <div v-if="overviewLoading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Skeleton v-for="i in 4" :key="i" class="h-28 rounded-xl" />
    </div>
    <div v-else-if="overview" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <KpiCard label="MRR" :value="formatMoney(overview.kpis.mrr)" hint="По активным подпискам">
            <template #icon><CircleDollarSign class="h-4 w-4 ui-subtle" /></template>
        </KpiCard>
        <KpiCard label="ARR (прогноз)" :value="formatMoney(overview.kpis.arr)" hint="MRR × 12">
            <template #icon><TrendingUp class="h-4 w-4 text-primary" /></template>
        </KpiCard>
        <KpiCard label="Активные подписки" :value="overview.kpis.active_subscriptions" :hint="`${overview.kpis.trial_subscriptions} на пробном периоде`">
            <template #icon><Users class="h-4 w-4 ui-subtle" /></template>
        </KpiCard>
        <KpiCard label="Триал заканчивается" :value="overview.kpis.trial_ending_soon" hint="В ближайшие 7 дней">
            <template #icon><CalendarClock class="h-4 w-4" :class="overview.kpis.trial_ending_soon ? 'text-amber-600' : 'ui-subtle'" /></template>
        </KpiCard>
    </div>

    <div v-if="overview" class="rounded-xl border border-border bg-card p-5">
        <h3 class="mb-4 font-display text-base font-semibold ui-text">Доход по тарифам</h3>
        <div class="space-y-4">
            <div v-for="row in overview.by_plan" :key="row.plan">
                <div class="mb-1.5 flex items-center justify-between text-sm">
                    <span class="font-medium ui-text">{{ planById(row.plan).name }} <span class="ml-1 font-mono text-xs ui-subtle">${{ row.price }}/мес</span></span>
                    <span class="font-mono text-xs ui-subtle">{{ row.subscribers }} подписок · {{ formatMoney(row.revenue) }}</span>
                </div>
                <div class="h-2 w-full rounded-full bg-muted"><div class="h-2 rounded-full bg-primary" :style="{ width: `${(row.revenue / maxRevenue) * 100}%` }"></div></div>
            </div>
        </div>
    </div>

    <DataTable
        :loading="loading"
        :row-count="rows.length"
        :column-count="7"
        empty-message="Подписки не найдены"
        :meta="meta"
        item-label="подписок"
        min-width="min-w-[64rem]"
        @update:page="page = $event"
    >
        <template #toolbar>
            <SearchInput v-model="search" placeholder="Поиск по компании или владельцу..." />
            <TableFiltersButton :active-count="activeFilterCount" @reset="resetFilters">
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Тариф</span>
                    <Select v-model="planFilter">
                        <SelectTrigger class="h-9 w-full"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Все тарифы</SelectItem>
                            <SelectItem v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</SelectItem>
                        </SelectContent>
                    </Select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Статус</span>
                    <Select v-model="statusFilter">
                        <SelectTrigger class="h-9 w-full"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Все статусы</SelectItem>
                            <SelectItem v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</SelectItem>
                        </SelectContent>
                    </Select>
                </label>
            </TableFiltersButton>
        </template>

        <template #thead>
            <th class="p-4">Компания</th>
            <th class="p-4">Владелец</th>
            <th class="p-4">Тариф</th>
            <th class="p-4">Статус</th>
            <th class="p-4">Регистрация</th>
            <th class="p-4">Триал до</th>
            <th class="p-4 text-right">Действия</th>
        </template>

        <tr v-for="row in rows" :key="row.id" class="group cursor-pointer transition hover:bg-muted" @click="openCompany(row.id)">
            <td class="p-4">
                <div class="flex items-center gap-3">
                    <div class="grid size-9 shrink-0 place-items-center rounded-lg bg-accent font-display text-sm font-bold text-accent-foreground">{{ (row.company?.name ?? row.name)[0] }}</div>
                    <div class="min-w-0 truncate text-sm font-semibold ui-text group-hover:text-primary">{{ row.company?.name ?? row.name }}</div>
                </div>
            </td>
            <td class="p-4">
                <div v-if="row.owner" class="flex items-center gap-2">
                    <Avatar class="size-6">
                        <AvatarImage v-if="row.owner.avatar_url" :src="row.owner.avatar_url" alt="" />
                        <AvatarFallback class="text-[10px] font-semibold bg-primary text-primary-foreground">{{ row.owner.name[0] }}</AvatarFallback>
                    </Avatar>
                    <span class="truncate text-sm ui-text">{{ row.owner.email }}</span>
                </div>
                <span v-else class="text-sm ui-subtle">Нет владельца</span>
            </td>
            <td class="p-4" @click.stop>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button type="button" class="rounded-md border px-2 py-1 text-xs font-medium ui-text border-border hover:bg-muted">{{ planById(row.plan).name }}</button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem v-for="plan in plans" :key="plan.id" @select="updatePlanFor(row.id, plan.id)">{{ plan.name }} — {{ plan.price }}</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </td>
            <td class="p-4" @click.stop>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button type="button"><Badge :tone="statusTone[row.status]">{{ statusLabels[row.status] }}</Badge></button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem v-for="(label, key) in statusLabels" :key="key" :variant="key === 'blocked' ? 'destructive' : 'default'" @select="updateStatus(row.id, key as Status)">{{ label }}</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </td>
            <td class="p-4 font-mono text-xs ui-subtle">{{ formatDate(row.created_at) }}</td>
            <td class="p-4 font-mono text-xs ui-subtle">{{ row.status === 'trial' ? formatDate(row.trial_ends_at) : '—' }}</td>
            <td class="p-4 text-right" @click.stop>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button type="button" class="rounded-lg p-1.5 opacity-0 transition hover:bg-muted group-hover:opacity-100 ui-subtle">
                            <MoreVertical class="h-4 w-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem @select="openCompany(row.id)">Открыть карточку</DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem variant="destructive" @select="askDelete(row)"><Trash2 class="h-4 w-4" />Удалить подписку</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </td>
        </tr>
    </DataTable>

    <Dialog v-model:open="addOpen">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="createSubscription">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><Building2 class="h-4 w-4 text-primary" />Новая подписка</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Название компании</span>
                        <Input v-model="newCompany.company_name" required />
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Отрасль</span>
                        <Input v-model="newCompany.industry" placeholder="Например, beauty" />
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label>
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Тариф</span>
                            <Select v-model="newCompany.plan">
                                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </label>
                        <label>
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Статус</span>
                            <Select v-model="newCompany.status">
                                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </label>
                    </div>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Имя владельца</span>
                        <Input v-model="newCompany.owner_name" required />
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Email владельца</span>
                        <Input v-model="newCompany.owner_email" type="email" required />
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Пароль (необязательно)</span>
                        <Input v-model="newCompany.owner_password" type="password" placeholder="Оставьте пустым для случайного" />
                    </label>
                </div>
                <DialogFooter>
                    <Button type="submit" variant="primary" :disabled="busy || !newCompany.company_name.trim() || !newCompany.owner_name.trim() || !newCompany.owner_email.trim()">Создать</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <AlertDialog :open="!!deleteTarget" @update:open="(v) => { if (! v) deleteTarget = null; }">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Удалить подписку «{{ deleteTarget ? nameOf(deleteTarget) : '' }}»?</AlertDialogTitle>
                <AlertDialogDescription>
                    Это безвозвратно удалит компанию, всю команду, диалоги, лиды, клиентов, каналы, AI-агентов и базу знаний. Чтобы подтвердить, введите название компании: <strong class="ui-text">{{ deleteTarget ? nameOf(deleteTarget) : '' }}</strong>
                </AlertDialogDescription>
            </AlertDialogHeader>
            <Input v-model="deleteConfirmText" placeholder="Введите название компании" />
            <AlertDialogFooter>
                <AlertDialogCancel>Отмена</AlertDialogCancel>
                <AlertDialogAction
                    variant="destructive"
                    :disabled="deleteBusy || ! deleteTarget || deleteConfirmText !== nameOf(deleteTarget)"
                    @click="confirmDelete"
                >Удалить безвозвратно</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
