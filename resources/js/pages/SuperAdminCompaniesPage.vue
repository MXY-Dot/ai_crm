<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Building2, ChevronLeft, ChevronRight, Download, MoreVertical, Plus, Trash2 } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { planById, plans } from '@/lib/plans';
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

type Status = 'trial' | 'inactive' | 'active' | 'paused' | 'blocked';
type PlanId = 'starter' | 'pro' | 'business';

type CompanyRow = {
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

type ListResponse = { data: CompanyRow[]; meta: { current_page: number; last_page: number; per_page: number; total: number } };

const rows = ref<CompanyRow[]>([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 });
const loading = ref(true);
const search = ref('');
const planFilter = ref('all');
const statusFilter = ref('all');
const page = ref(1);
const selectedIds = ref<number[]>([]);
const busy = ref(false);
const addOpen = ref(false);

const statusLabels: Record<Status, string> = {
    trial: 'Пробный период',
    inactive: 'Неактивна',
    active: 'Активна',
    paused: 'Приостановлена',
    blocked: 'Заблокирована',
};
const statusTone: Record<Status, 'green' | 'blue' | 'amber' | 'red'> = {
    active: 'green',
    trial: 'blue',
    inactive: 'amber',
    paused: 'amber',
    blocked: 'red',
};

const allSelected = computed(() => rows.value.length > 0 && selectedIds.value.length === rows.value.length);

function formatDate(value: string | null): string {
    if (! value) return '—';
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value));
}

function openCompany(id: number): void {
    router.visit(`/super-admin/companies/${id}`);
}

async function load(): Promise<void> {
    loading.value = true;
    try {
        const params = new URLSearchParams({ page: String(page.value) });
        if (search.value.trim()) params.set('search', search.value.trim());
        if (planFilter.value !== 'all') params.set('plan', planFilter.value);
        if (statusFilter.value !== 'all') params.set('status', statusFilter.value);

        const response = await apiRequest<ListResponse>(`/api/admin/companies?${params.toString()}`);
        rows.value = response.data;
        meta.value = response.meta;
        selectedIds.value = [];
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить компании');
    } finally {
        loading.value = false;
    }
}

let searchTimer: number | undefined;
watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => { page.value = 1; load(); }, 350);
});
watch([planFilter, statusFilter], () => { page.value = 1; load(); });
watch(page, load);

onMounted(load);

function toggleAll(checked: boolean): void {
    selectedIds.value = checked ? rows.value.map((row) => row.id) : [];
}

const activeFilterCount = computed(() => [planFilter.value, statusFilter.value].filter((v) => v !== 'all').length);

function resetFilters(): void {
    planFilter.value = 'all';
    statusFilter.value = 'all';
}

function toggleRow(id: number, checked: boolean): void {
    selectedIds.value = checked ? [...selectedIds.value, id] : selectedIds.value.filter((rowId) => rowId !== id);
}

async function updateStatus(tenantId: number, status: Status): Promise<void> {
    busy.value = true;
    try {
        await apiRequest(`/api/admin/companies/${tenantId}/status`, { method: 'PATCH', body: { status } });
        toast.success('Статус компании обновлён');
        await load();
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
        toast.success('Тариф компании обновлён');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось обновить тариф');
    } finally {
        busy.value = false;
    }
}

async function batchSuspend(): Promise<void> {
    if (! selectedIds.value.length) return;
    busy.value = true;
    try {
        await Promise.all(selectedIds.value.map((id) => apiRequest(`/api/admin/companies/${id}/status`, { method: 'PATCH', body: { status: 'blocked' } })));
        toast.success(`Приостановлено компаний: ${selectedIds.value.length}`);
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось приостановить компании');
    } finally {
        busy.value = false;
    }
}

async function batchChangePlan(plan: PlanId): Promise<void> {
    if (! selectedIds.value.length) return;
    busy.value = true;
    try {
        await Promise.all(selectedIds.value.map((id) => apiRequest(`/api/admin/companies/${id}/plan`, { method: 'PATCH', body: { plan } })));
        toast.success(`Тариф обновлён для ${selectedIds.value.length} компаний`);
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось обновить тариф');
    } finally {
        busy.value = false;
    }
}

const deleteTarget = ref<CompanyRow | null>(null);
const deleteConfirmText = ref('');
const deleteBusy = ref(false);

function nameOf(row: CompanyRow): string {
    return row.company?.name ?? row.name;
}

function askDelete(row: CompanyRow): void {
    deleteTarget.value = row;
    deleteConfirmText.value = '';
}

async function confirmDelete(): Promise<void> {
    if (! deleteTarget.value) return;
    deleteBusy.value = true;
    try {
        await apiRequest(`/api/admin/companies/${deleteTarget.value.id}`, { method: 'DELETE', body: { confirm_name: deleteConfirmText.value } });
        toast.success(`Компания «${nameOf(deleteTarget.value)}» удалена`);
        deleteTarget.value = null;
        if (rows.value.length === 1 && meta.value.current_page > 1) page.value -= 1;
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось удалить компанию');
    } finally {
        deleteBusy.value = false;
    }
}

function exportCsv(): void {
    const header = ['Компания', 'Владелец', 'Email', 'План', 'Статус', 'Пользователи', 'Каналы', 'Создана'];
    const lines = rows.value.map((row) => [
        row.company?.name ?? row.name,
        row.owner?.name ?? '',
        row.owner?.email ?? '',
        planById(row.plan).name,
        statusLabels[row.status],
        String(row.users_count),
        String(row.channels_count),
        formatDate(row.created_at),
    ]);
    const csv = [header, ...lines].map((cols) => cols.map((col) => `"${col.replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'companies.csv';
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

async function createCompany(): Promise<void> {
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
        toast.success('Компания создана');
        Object.assign(newCompany, { company_name: '', industry: '', plan: 'starter', status: 'trial', owner_name: '', owner_email: '', owner_password: '' });
        addOpen.value = false;
        page.value = 1;
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось создать компанию');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-display text-xl font-bold ui-text">Управление компаниями</h2>
            <p class="mt-1 text-sm ui-subtle">Все тенанты платформы: владельцы, тарифы, статус подключения.</p>
        </div>
        <div class="flex gap-2">
            <Button variant="outline" size="sm" @click="exportCsv"><Download class="h-4 w-4" />Экспорт CSV</Button>
            <Button variant="primary" size="sm" @click="addOpen = true"><Plus class="h-4 w-4" />Добавить компанию</Button>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-border bg-card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b p-4 border-border">
            <div class="flex flex-wrap items-center gap-3">
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
            </div>

            <div v-if="selectedIds.length" class="flex items-center gap-3 border-l pl-4 border-border">
                <span class="text-sm font-medium ui-text">Выбрано: {{ selectedIds.length }}</span>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button size="sm" variant="outline" :disabled="busy">Изменить тариф</Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent>
                        <DropdownMenuItem v-for="plan in plans" :key="plan.id" @select="batchChangePlan(plan.id)">{{ plan.name }}</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <Button size="sm" variant="destructive" :disabled="busy" @click="batchSuspend">Приостановить</Button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[64rem] text-left text-sm">
                <thead class="border-b border-border">
                    <tr class="text-xs font-semibold uppercase ui-subtle">
                        <th class="w-10 p-4"><input type="checkbox" class="size-4 accent-primary" :checked="allSelected" @change="toggleAll(($event.target as HTMLInputElement).checked)"></th>
                        <th class="p-4">Компания</th>
                        <th class="p-4">Владелец</th>
                        <th class="p-4">Тариф</th>
                        <th class="p-4">Статус</th>
                        <th class="p-4">Команда / Каналы</th>
                        <th class="p-4 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <template v-if="loading">
                        <tr v-for="i in 5" :key="`skeleton-${i}`">
                            <td class="p-4" colspan="7"><Skeleton class="h-10 w-full" /></td>
                        </tr>
                    </template>
                    <tr v-else-if="! rows.length">
                        <td class="p-8 text-center text-sm ui-subtle" colspan="7">Компании не найдены</td>
                    </tr>
                    <tr v-else v-for="row in rows" :key="row.id" class="group cursor-pointer transition hover:bg-muted" @click="openCompany(row.id)">
                        <td class="p-4" @click.stop>
                            <input type="checkbox" class="size-4 accent-primary" :checked="selectedIds.includes(row.id)" @change="toggleRow(row.id, ($event.target as HTMLInputElement).checked)">
                        </td>
                        <td class="p-4">
                            <div class="flex items-center gap-3">
                                <div class="grid size-10 shrink-0 place-items-center rounded-lg bg-accent font-display text-base font-bold text-accent-foreground">{{ (row.company?.name ?? row.name)[0] }}</div>
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold ui-text group-hover:text-primary">{{ row.company?.name ?? row.name }}</div>
                                    <div class="mt-0.5 text-xs ui-subtle">Регистрация: {{ formatDate(row.created_at) }}</div>
                                </div>
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
                        <td class="p-4">
                            <Badge :tone="statusTone[row.status]">{{ statusLabels[row.status] }}</Badge>
                        </td>
                        <td class="p-4">
                            <div class="font-mono text-xs ui-subtle">{{ row.users_count }} чел. <span class="mx-1">/</span> {{ row.channels_count }} канал.</div>
                        </td>
                        <td class="p-4 text-right" @click.stop>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <button type="button" class="rounded-lg p-1.5 text-on-surface-variant opacity-0 transition hover:bg-muted group-hover:opacity-100 ui-subtle">
                                        <MoreVertical class="h-4 w-4" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem @select="openCompany(row.id)">Открыть карточку</DropdownMenuItem>
                                    <DropdownMenuItem v-if="row.status !== 'active'" @select="updateStatus(row.id, 'active')">Активировать</DropdownMenuItem>
                                    <DropdownMenuItem v-if="row.status !== 'paused'" @select="updateStatus(row.id, 'paused')">Приостановить</DropdownMenuItem>
                                    <DropdownMenuItem v-if="row.status !== 'blocked'" variant="destructive" @select="updateStatus(row.id, 'blocked')">Заблокировать</DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem variant="destructive" @select="askDelete(row)"><Trash2 class="h-4 w-4" />Удалить компанию</DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t p-4 border-border">
            <span class="text-sm ui-subtle">Показано {{ rows.length ? (meta.current_page - 1) * meta.per_page + 1 : 0 }}–{{ (meta.current_page - 1) * meta.per_page + rows.length }} из {{ meta.total }} компаний</span>
            <div class="flex items-center gap-1">
                <Button size="icon-sm" variant="outline" :disabled="meta.current_page <= 1" @click="page = meta.current_page - 1"><ChevronLeft class="h-4 w-4" /></Button>
                <span class="px-2 font-mono text-sm ui-text">{{ meta.current_page }} / {{ meta.last_page }}</span>
                <Button size="icon-sm" variant="outline" :disabled="meta.current_page >= meta.last_page" @click="page = meta.current_page + 1"><ChevronRight class="h-4 w-4" /></Button>
            </div>
        </div>
    </div>

    <Dialog v-model:open="addOpen">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="createCompany">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><Building2 class="h-4 w-4 text-primary" />Новая компания</DialogTitle>
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
                <AlertDialogTitle>Удалить компанию «{{ deleteTarget ? nameOf(deleteTarget) : '' }}»?</AlertDialogTitle>
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
