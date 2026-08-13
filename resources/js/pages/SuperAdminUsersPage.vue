<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Check, ChevronLeft, ChevronRight, Copy, Download, Key, MoreVertical, Shield, UserPlus } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import SearchInput from '@/components/dashboard/SearchInput.vue';
import TableFiltersButton from '@/components/dashboard/TableFiltersButton.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';

defineOptions({ layout: SuperAdminLayout });

type Role = 'super_admin' | 'owner' | 'manager' | 'operator';
type Status = 'active' | 'invited' | 'disabled';

type UserRow = {
    id: number;
    name: string;
    email: string;
    avatar_url: string | null;
    role: Role;
    status: Status;
    last_login_at: string | null;
    last_ip: string | null;
    created_at: string;
    tenant: { id: number; name: string } | null;
};

type ListResponse = { data: UserRow[]; meta: { current_page: number; last_page: number; per_page: number; total: number } };
type TenantOption = { id: number; name: string };

const rows = ref<UserRow[]>([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
const loading = ref(true);
const search = ref('');
const roleTab = ref('all');
const statusFilter = ref('all');
const page = ref(1);
const busy = ref(false);
const addOpen = ref(false);
const tenantOptions = ref<TenantOption[]>([]);

const roleLabels: Record<Role, string> = {
    super_admin: 'Супер-админ',
    owner: 'Владелец',
    manager: 'Менеджер',
    operator: 'Оператор',
};
const roleTone: Record<Role, 'green' | 'blue' | 'amber' | 'neutral'> = {
    super_admin: 'amber',
    owner: 'green',
    manager: 'blue',
    operator: 'neutral',
};
const statusLabels: Record<Status, string> = {
    active: 'Активен',
    invited: 'Приглашён',
    disabled: 'Отключён',
};
const statusTone: Record<Status, 'green' | 'blue' | 'neutral'> = {
    active: 'green',
    invited: 'blue',
    disabled: 'neutral',
};

function formatDate(value: string | null): string {
    if (! value) return '—';
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

function openCompany(tenantId: number | undefined): void {
    if (! tenantId) return;
    router.visit(`/super-admin/companies/${tenantId}`);
}

function openUser(id: number): void {
    router.visit(`/super-admin/users/${id}`);
}

async function load(): Promise<void> {
    loading.value = true;
    try {
        const params = new URLSearchParams({ page: String(page.value) });
        if (search.value.trim()) params.set('search', search.value.trim());
        if (roleTab.value !== 'all') params.set('role', roleTab.value);
        if (statusFilter.value !== 'all') params.set('status', statusFilter.value);

        const response = await apiRequest<ListResponse>(`/api/admin/users?${params.toString()}`);
        rows.value = response.data;
        meta.value = response.meta;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить пользователей');
    } finally {
        loading.value = false;
    }
}

let searchTimer: number | undefined;
watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => { page.value = 1; load(); }, 350);
});
watch([roleTab, statusFilter], () => { page.value = 1; load(); });
watch(page, load);

onMounted(() => {
    load();
    apiRequest<TenantOption[]>('/api/admin/companies/lookup').then((options) => { tenantOptions.value = options; }).catch(() => {});
});

async function updateStatus(user: UserRow, status: Status): Promise<void> {
    busy.value = true;
    try {
        await apiRequest(`/api/admin/users/${user.id}/status`, { method: 'PATCH', body: { status } });
        toast.success('Статус пользователя обновлён');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось обновить статус');
    } finally {
        busy.value = false;
    }
}

const resetPasswordFor = ref<UserRow | null>(null);
const generatedPassword = ref('');
const copied = ref(false);

async function resetPassword(user: UserRow): Promise<void> {
    busy.value = true;
    try {
        const response = await apiRequest<{ password: string }>(`/api/admin/users/${user.id}/reset-password`, { method: 'POST' });
        resetPasswordFor.value = user;
        generatedPassword.value = response.password;
        copied.value = false;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось сбросить пароль');
    } finally {
        busy.value = false;
    }
}

async function copyPassword(): Promise<void> {
    await navigator.clipboard.writeText(generatedPassword.value);
    copied.value = true;
}

function exportCsv(): void {
    const header = ['Имя', 'Email', 'Роль', 'Компания', 'Статус', 'Последний вход', 'IP', 'Регистрация'];
    const lines = rows.value.map((row) => [
        row.name,
        row.email,
        roleLabels[row.role],
        row.tenant?.name ?? '',
        statusLabels[row.status],
        row.last_login_at ? formatDate(row.last_login_at) : 'не входил(а)',
        row.last_ip ?? '',
        formatDate(row.created_at),
    ]);
    const csv = [header, ...lines].map((cols) => cols.map((col) => `"${col.replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'platform-users.csv';
    link.click();
    URL.revokeObjectURL(url);
}

const newUser = reactive({ tenant_id: '' as number | '', name: '', email: '', role: 'operator' as Exclude<Role, 'super_admin'>, password: '' });
const assignableRoles: Exclude<Role, 'super_admin'>[] = ['owner', 'manager', 'operator'];

const activeFilterCount = computed(() => (statusFilter.value !== 'all' ? 1 : 0));

function resetFilters(): void {
    statusFilter.value = 'all';
}

async function createUser(): Promise<void> {
    if (! newUser.tenant_id || ! newUser.name.trim() || ! newUser.email.trim()) return;

    busy.value = true;
    try {
        await apiRequest('/api/admin/users', {
            method: 'POST',
            body: {
                tenant_id: newUser.tenant_id,
                name: newUser.name.trim(),
                email: newUser.email.trim(),
                role: newUser.role,
                password: newUser.password.trim() || undefined,
            },
        });
        toast.success('Пользователь приглашён');
        Object.assign(newUser, { tenant_id: '', name: '', email: '', role: 'operator', password: '' });
        addOpen.value = false;
        page.value = 1;
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось пригласить пользователя');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-display text-xl font-bold ui-text">Пользователи платформы</h2>
            <p class="mt-1 text-sm ui-subtle">Доступ, роли и активность всех пользователей на всех компаниях.</p>
        </div>
        <div class="flex gap-2">
            <Button variant="outline" size="sm" @click="exportCsv"><Download class="h-4 w-4" />Экспорт CSV</Button>
            <Button variant="primary" size="sm" @click="addOpen = true"><UserPlus class="h-4 w-4" />Пригласить пользователя</Button>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-border bg-card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b p-4 border-border">
            <div class="flex flex-wrap items-center gap-3">
                <SearchInput v-model="search" placeholder="Поиск по имени или email..." />
                <TableFiltersButton :active-count="activeFilterCount" @reset="resetFilters">
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
            <Tabs v-model="roleTab">
                <TabsList>
                    <TabsTrigger value="all">Все</TabsTrigger>
                    <TabsTrigger value="owner">Владельцы</TabsTrigger>
                    <TabsTrigger value="manager">Менеджеры</TabsTrigger>
                    <TabsTrigger value="operator">Операторы</TabsTrigger>
                    <TabsTrigger value="super_admin">Персонал WERO</TabsTrigger>
                </TabsList>
            </Tabs>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[72rem] text-left text-sm">
                <thead class="border-b border-border">
                    <tr class="text-xs font-semibold uppercase ui-subtle">
                        <th class="p-4">Пользователь</th>
                        <th class="p-4">Роль</th>
                        <th class="p-4">Компания</th>
                        <th class="p-4">Последний вход</th>
                        <th class="p-4">IP адрес</th>
                        <th class="p-4">Статус</th>
                        <th class="p-4 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <template v-if="loading">
                        <tr v-for="i in 6" :key="`skeleton-${i}`">
                            <td class="p-4" colspan="7"><Skeleton class="h-10 w-full" /></td>
                        </tr>
                    </template>
                    <tr v-else-if="! rows.length">
                        <td class="p-8 text-center text-sm ui-subtle" colspan="7">Пользователи не найдены</td>
                    </tr>
                    <tr v-else v-for="row in rows" :key="row.id" class="group cursor-pointer transition hover:bg-muted" @click="openUser(row.id)">
                        <td class="p-4">
                            <div class="flex items-center gap-3">
                                <Avatar class="size-9">
                                    <AvatarImage v-if="row.avatar_url" :src="row.avatar_url" alt="" />
                                    <AvatarFallback class="text-xs font-semibold bg-accent text-accent-foreground">{{ row.name.slice(0, 2).toUpperCase() }}</AvatarFallback>
                                </Avatar>
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold ui-text group-hover:text-primary">{{ row.name }}</div>
                                    <div class="truncate text-xs ui-subtle">{{ row.email }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="p-4">
                            <Badge :tone="roleTone[row.role]">
                                <Shield v-if="row.role === 'super_admin'" class="h-3 w-3" />{{ roleLabels[row.role] }}
                            </Badge>
                        </td>
                        <td class="p-4" @click.stop>
                            <button
                                v-if="row.tenant"
                                type="button"
                                class="text-sm text-primary hover:underline"
                                @click="openCompany(row.tenant.id)"
                            >{{ row.tenant.name }}</button>
                            <span v-else class="text-sm ui-subtle">— (платформа)</span>
                        </td>
                        <td class="p-4 font-mono text-xs ui-subtle">{{ row.last_login_at ? formatDate(row.last_login_at) : 'не входил(а)' }}</td>
                        <td class="p-4 font-mono text-xs ui-subtle">{{ row.last_ip ?? '—' }}</td>
                        <td class="p-4">
                            <Badge :tone="statusTone[row.status]">{{ statusLabels[row.status] }}</Badge>
                        </td>
                        <td class="p-4 text-right" @click.stop>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <button type="button" class="rounded-lg p-1.5 opacity-0 transition hover:bg-muted group-hover:opacity-100 ui-subtle">
                                        <MoreVertical class="h-4 w-4" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem @select="openUser(row.id)">Открыть карточку</DropdownMenuItem>
                                    <DropdownMenuItem @select="resetPassword(row)"><Key class="h-4 w-4" />Сбросить пароль</DropdownMenuItem>
                                    <DropdownMenuItem v-if="row.status !== 'active'" @select="updateStatus(row, 'active')">Активировать</DropdownMenuItem>
                                    <DropdownMenuItem v-if="row.status !== 'disabled'" variant="destructive" @select="updateStatus(row, 'disabled')">Отключить</DropdownMenuItem>
                                    <DropdownMenuItem v-if="row.tenant" @select="openCompany(row.tenant.id)">Открыть компанию</DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t p-4 border-border">
            <span class="text-sm ui-subtle">Показано {{ rows.length ? (meta.current_page - 1) * meta.per_page + 1 : 0 }}–{{ (meta.current_page - 1) * meta.per_page + rows.length }} из {{ meta.total }} пользователей</span>
            <div class="flex items-center gap-1">
                <Button size="icon-sm" variant="outline" :disabled="meta.current_page <= 1" @click="page = meta.current_page - 1"><ChevronLeft class="h-4 w-4" /></Button>
                <span class="px-2 font-mono text-sm ui-text">{{ meta.current_page }} / {{ meta.last_page }}</span>
                <Button size="icon-sm" variant="outline" :disabled="meta.current_page >= meta.last_page" @click="page = meta.current_page + 1"><ChevronRight class="h-4 w-4" /></Button>
            </div>
        </div>
    </div>

    <Dialog v-model:open="addOpen">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="createUser">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><UserPlus class="h-4 w-4 text-primary" />Пригласить пользователя</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Компания</span>
                        <Select v-model="newUser.tenant_id">
                            <SelectTrigger class="w-full"><SelectValue placeholder="Выберите компанию" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="option in tenantOptions" :key="option.id" :value="option.id">{{ option.name }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Имя</span>
                        <Input v-model="newUser.name" required />
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Email</span>
                        <Input v-model="newUser.email" type="email" required />
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Роль</span>
                        <Select v-model="newUser.role">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="role in assignableRoles" :key="role" :value="role">{{ roleLabels[role] }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Пароль (необязательно)</span>
                        <Input v-model="newUser.password" type="password" placeholder="Оставьте пустым для случайного" />
                    </label>
                </div>
                <DialogFooter>
                    <Button type="submit" variant="primary" :disabled="busy || !newUser.tenant_id || !newUser.name.trim() || !newUser.email.trim()">Пригласить</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="!!resetPasswordFor" @update:open="(v) => { if (! v) resetPasswordFor = null; }">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><Key class="h-4 w-4 text-primary" />Пароль сброшен</DialogTitle>
            </DialogHeader>
            <p class="text-sm ui-subtle">Новый пароль для <strong class="ui-text">{{ resetPasswordFor?.email }}</strong>. Он показан один раз — скопируйте и передайте пользователю лично.</p>
            <div class="flex items-center gap-2 rounded-lg border p-3 border-border bg-muted">
                <code class="flex-1 select-all font-mono text-sm ui-text">{{ generatedPassword }}</code>
                <Button size="icon-sm" variant="outline" @click="copyPassword">
                    <Check v-if="copied" class="h-4 w-4 text-primary" />
                    <Copy v-else class="h-4 w-4" />
                </Button>
            </div>
            <DialogFooter>
                <Button variant="secondary" @click="resetPasswordFor = null">Готово</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
