<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Check, ChevronRight, Copy, Key, MessagesSquare, ShieldCheck, Target } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';

defineOptions({ layout: SuperAdminLayout });

type Role = 'super_admin' | 'owner' | 'manager' | 'operator';
type Status = 'active' | 'invited' | 'disabled';

type Detail = {
    user: {
        id: number; name: string; email: string; phone: string | null; avatar_url: string | null;
        role: Role; status: Status; two_factor_enabled: boolean;
        created_at: string; last_login_at: string | null; last_ip: string | null; sessions_count: number;
    };
    tenant: { id: number; name: string; slug: string; status: string } | null;
    stats: { assigned_conversations: number; open_conversations: number; assigned_leads: number };
    activity: { id: number; action: string; entity_type: string | null; ip_address: string | null; created_at: string }[];
};

const userId = usePage<{ userId: number }>().props.userId;
const data = ref<Detail | null>(null);
const loading = ref(true);
const busy = ref(false);

const roleLabels: Record<Role, string> = { super_admin: 'Супер-админ', owner: 'Владелец', manager: 'Менеджер', operator: 'Оператор' };
const roleTone: Record<Role, 'green' | 'blue' | 'amber' | 'neutral'> = { super_admin: 'amber', owner: 'green', manager: 'blue', operator: 'neutral' };
const statusLabels: Record<Status, string> = { active: 'Активен', invited: 'Приглашён', disabled: 'Отключён' };
const statusTone: Record<Status, 'green' | 'blue' | 'neutral'> = { active: 'green', invited: 'blue', disabled: 'neutral' };

const actionLabels: Record<string, string> = {
    'ai_agent.created': 'AI-агент создан',
    'ai_agent.updated': 'AI-агент изменён',
    'tenant_user.created': 'Сотрудник добавлен',
    'tenant_user.updated': 'Сотрудник изменён',
    'integration_settings.updated': 'Настройки интеграций изменены',
    'knowledge_document.uploaded': 'Документ загружен',
    'knowledge_document.indexed_text': 'Текст проиндексирован',
    'company.logo_updated': 'Логотип компании обновлён',
};
function activityLabel(action: string): string {
    return actionLabels[action] ?? action;
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value));
}
function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

async function load(): Promise<void> {
    loading.value = true;
    try {
        data.value = await apiRequest<Detail>(`/api/admin/users/${userId}`);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить пользователя');
    } finally {
        loading.value = false;
    }
}

async function updateStatus(status: Status): Promise<void> {
    busy.value = true;
    try {
        await apiRequest(`/api/admin/users/${userId}/status`, { method: 'PATCH', body: { status } });
        toast.success('Статус пользователя обновлён');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось обновить статус');
    } finally {
        busy.value = false;
    }
}

const generatedPassword = ref('');
const resetDialogOpen = ref(false);
const copied = ref(false);

async function resetPassword(): Promise<void> {
    busy.value = true;
    try {
        const response = await apiRequest<{ password: string }>(`/api/admin/users/${userId}/reset-password`, { method: 'POST' });
        generatedPassword.value = response.password;
        resetDialogOpen.value = true;
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

onMounted(load);
</script>

<template>
    <div class="flex items-center gap-2 text-sm ui-subtle">
        <a href="/super-admin/users" class="transition hover:text-primary">Пользователи</a>
        <ChevronRight class="h-3.5 w-3.5" />
        <span class="font-medium ui-text">{{ data?.user.name ?? '…' }}</span>
    </div>

    <div v-if="loading" class="space-y-6">
        <Skeleton class="h-32 w-full rounded-xl" />
        <div class="grid gap-4 md:grid-cols-3"><Skeleton v-for="i in 3" :key="i" class="h-24 rounded-xl" /></div>
    </div>

    <template v-else-if="data">
        <div class="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 md:flex-row md:items-start md:justify-between">
            <div class="flex items-start gap-4">
                <Avatar class="size-16 shrink-0">
                    <AvatarImage v-if="data.user.avatar_url" :src="data.user.avatar_url" alt="" />
                    <AvatarFallback class="font-display text-2xl font-bold bg-accent text-accent-foreground">{{ data.user.name[0] }}</AvatarFallback>
                </Avatar>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-display text-xl font-bold ui-text">{{ data.user.name }}</h2>
                        <Badge :tone="roleTone[data.user.role]"><ShieldCheck v-if="data.user.role === 'super_admin'" class="h-3 w-3" />{{ roleLabels[data.user.role] }}</Badge>
                        <Badge :tone="statusTone[data.user.status]">{{ statusLabels[data.user.status] }}</Badge>
                    </div>
                    <div class="mt-1.5 flex flex-wrap gap-3 font-mono text-xs ui-subtle">
                        <span>{{ data.user.email }}</span>
                        <span v-if="data.user.phone">{{ data.user.phone }}</span>
                        <span>Регистрация: {{ formatDate(data.user.created_at) }}</span>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" :disabled="busy" @click="resetPassword"><Key class="h-4 w-4" />Сбросить пароль</Button>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" :disabled="busy">Изменить статус</Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem v-for="(label, key) in statusLabels" :key="key" :variant="key === 'disabled' ? 'destructive' : 'default'" @select="updateStatus(key as Status)">{{ label }}</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-border bg-card p-4">
                <div class="flex items-center justify-between"><span class="text-xs font-semibold uppercase ui-subtle">Назначено диалогов</span><MessagesSquare class="h-4 w-4 ui-subtle" /></div>
                <p class="mt-2 font-display text-2xl font-bold ui-text">{{ data.stats.assigned_conversations }}</p>
                <p class="mt-1 text-xs ui-subtle">{{ data.stats.open_conversations }} открыто сейчас</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <div class="flex items-center justify-between"><span class="text-xs font-semibold uppercase ui-subtle">Назначено лидов</span><Target class="h-4 w-4 ui-subtle" /></div>
                <p class="mt-2 font-display text-2xl font-bold ui-text">{{ data.stats.assigned_leads }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <div class="flex items-center justify-between"><span class="text-xs font-semibold uppercase ui-subtle">Последний вход</span><ShieldCheck class="h-4 w-4 ui-subtle" /></div>
                <p class="mt-2 font-display text-lg font-bold ui-text">{{ data.user.last_login_at ? formatDateTime(data.user.last_login_at) : 'не входил(а)' }}</p>
                <p class="mt-1 font-mono text-xs ui-subtle">{{ data.user.last_ip ?? '—' }} · {{ data.user.sessions_count }} сессий</p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <div class="rounded-xl border border-border bg-card p-5">
                    <h3 class="mb-4 font-display text-base font-semibold ui-text">Данные пользователя</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Компания</span>
                            <a v-if="data.tenant" :href="`/super-admin/companies/${data.tenant.id}`" class="text-sm text-primary hover:underline">{{ data.tenant.name }}</a>
                            <span v-else class="text-sm ui-subtle">— (платформа WERO)</span>
                        </div>
                        <div>
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Телефон</span>
                            <span class="text-sm ui-text">{{ data.user.phone ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Двухфакторная аутентификация</span>
                            <span class="text-sm ui-text">{{ data.user.two_factor_enabled ? 'Включена' : 'Отключена' }}</span>
                        </div>
                        <div>
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Последний IP</span>
                            <span class="font-mono text-sm ui-text">{{ data.user.last_ip ?? '—' }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h3 class="mb-4 font-display text-base font-semibold ui-text">Последняя активность</h3>
                <div v-if="data.activity.length" class="relative space-y-4 border-l border-border pl-4">
                    <div v-for="entry in data.activity" :key="entry.id" class="relative">
                        <span class="absolute -left-[1.1rem] top-1 size-2 rounded-full bg-primary"></span>
                        <p class="text-sm font-medium ui-text">{{ activityLabel(entry.action) }}</p>
                        <p class="text-xs ui-subtle">{{ formatDateTime(entry.created_at) }}<span v-if="entry.ip_address"> · {{ entry.ip_address }}</span></p>
                    </div>
                </div>
                <p v-else class="text-sm ui-subtle">Активности пока нет</p>
            </div>
        </div>
    </template>

    <Dialog v-model:open="resetDialogOpen">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><Key class="h-4 w-4 text-primary" />Пароль сброшен</DialogTitle>
            </DialogHeader>
            <p class="text-sm ui-subtle">Новый пароль для <strong class="ui-text">{{ data?.user.email }}</strong>. Он показан один раз — скопируйте и передайте пользователю лично.</p>
            <div class="flex items-center gap-2 rounded-lg border p-3 border-border bg-muted">
                <code class="flex-1 select-all font-mono text-sm ui-text">{{ generatedPassword }}</code>
                <Button size="icon-sm" variant="outline" @click="copyPassword">
                    <Check v-if="copied" class="h-4 w-4 text-primary" />
                    <Copy v-else class="h-4 w-4" />
                </Button>
            </div>
            <DialogFooter>
                <Button variant="secondary" @click="resetDialogOpen = false">Готово</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
