<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { ChevronRight, CircleDollarSign, MessagesSquare, Target, Trash2, Users } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { planById, plans } from '@/lib/plans';
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
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';

defineOptions({ layout: SuperAdminLayout });

type Status = 'trial' | 'active' | 'paused' | 'blocked';
type PlanId = 'starter' | 'pro' | 'business';

type Detail = {
    tenant: { id: number; name: string; slug: string; status: Status; plan: PlanId; created_at: string; trial_ends_at: string | null };
    company: { id: number; name: string; industry: string | null; phone: string | null; email: string | null; logo_url: string | null } | null;
    owner: { id: number; name: string; email: string; avatar_url: string | null } | null;
    kpis: { mrr: number; arr: number; team_count: number; messages_30d: number; leads_count: number; active_channels: number };
    limits: { conversations: { used: number; limit: number | null }; ai_agents: { used: number; limit: number | null } };
    channels: { id: number; provider: string; name: string; status: string; last_synced_at: string | null }[];
    team: { id: number; name: string; email: string; role: string; status: string; avatar_url: string | null; last_login_at: string | null }[];
    activity: { id: number; action: string; entity_type: string | null; user: string | null; created_at: string }[];
};

const tenantId = usePage<{ tenantId: number }>().props.tenantId;
const data = ref<Detail | null>(null);
const loading = ref(true);
const busy = ref(false);

const statusLabels: Record<Status, string> = { trial: 'Пробный период', active: 'Активна', paused: 'Приостановлена', blocked: 'Заблокирована' };
const statusTone: Record<Status, 'green' | 'blue' | 'amber' | 'red'> = { active: 'green', trial: 'blue', paused: 'amber', blocked: 'red' };
const providerLabels: Record<string, string> = { telegram: 'Telegram', whatsapp: 'WhatsApp', website: 'Виджет сайта', chatwoot: 'Единый инбокс', instagram: 'Instagram' };
const roleLabels: Record<string, string> = { super_admin: 'Супер-админ', owner: 'Владелец', manager: 'Менеджер', operator: 'Оператор' };

const conversationsPercent = computed(() => {
    const { used, limit } = data.value?.limits.conversations ?? { used: 0, limit: null };
    return limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
});
const aiAgentsPercent = computed(() => {
    const { used, limit } = data.value?.limits.ai_agents ?? { used: 0, limit: null };
    return limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
});

function formatMoney(value: number): string {
    return new Intl.NumberFormat('ru-RU').format(value) + ' $';
}
function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value));
}
function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

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

async function load(): Promise<void> {
    loading.value = true;
    try {
        data.value = await apiRequest<Detail>(`/api/admin/companies/${tenantId}`);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить компанию');
    } finally {
        loading.value = false;
    }
}

async function updateStatus(status: Status): Promise<void> {
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

async function updatePlan(plan: PlanId): Promise<void> {
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

const deleteOpen = ref(false);
const deleteConfirmText = ref('');
const deleteBusy = ref(false);

function companyName(): string {
    return data.value?.company?.name ?? data.value?.tenant.name ?? '';
}

async function confirmDelete(): Promise<void> {
    deleteBusy.value = true;
    try {
        await apiRequest(`/api/admin/companies/${tenantId}`, { method: 'DELETE', body: { confirm_name: deleteConfirmText.value } });
        toast.success(`Компания «${companyName()}» удалена`);
        router.visit('/super-admin/companies');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось удалить компанию');
    } finally {
        deleteBusy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="flex items-center gap-2 text-sm ui-subtle">
        <a href="/super-admin/companies" class="transition hover:text-primary">Компании</a>
        <ChevronRight class="h-3.5 w-3.5" />
        <span class="font-medium ui-text">{{ data?.company?.name ?? data?.tenant.name ?? '…' }}</span>
    </div>

    <div v-if="loading" class="space-y-6">
        <Skeleton class="h-32 w-full rounded-xl" />
        <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-6"><Skeleton v-for="i in 6" :key="i" class="h-24 rounded-xl" /></div>
    </div>

    <template v-else-if="data">
        <div class="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 md:flex-row md:items-start md:justify-between">
            <div class="flex items-start gap-4">
                <div class="grid size-16 shrink-0 place-items-center rounded-lg bg-accent font-display text-2xl font-bold text-accent-foreground">
                    {{ (data.company?.name ?? data.tenant.name)[0] }}
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-display text-xl font-bold ui-text">{{ data.company?.name ?? data.tenant.name }}</h2>
                        <Badge variant="outline">{{ planById(data.tenant.plan).name }}</Badge>
                        <Badge :tone="statusTone[data.tenant.status]">{{ statusLabels[data.tenant.status] }}</Badge>
                    </div>
                    <div class="mt-1.5 flex flex-wrap gap-3 font-mono text-xs ui-subtle">
                        <span>ID: {{ data.tenant.slug }}</span>
                        <span>Создана: {{ formatDate(data.tenant.created_at) }}</span>
                        <span v-if="data.tenant.trial_ends_at">Триал до: {{ formatDate(data.tenant.trial_ends_at) }}</span>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" :disabled="busy">Изменить тариф</Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem v-for="plan in plans" :key="plan.id" @select="updatePlan(plan.id)">{{ plan.name }} — {{ plan.price }}</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" :disabled="busy">Изменить статус</Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem v-for="(label, key) in statusLabels" :key="key" :variant="key === 'blocked' ? 'destructive' : 'default'" @select="updateStatus(key as Status)">{{ label }}</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <Button variant="destructive" size="sm" :disabled="busy" @click="deleteConfirmText = ''; deleteOpen = true"><Trash2 class="h-4 w-4" />Удалить компанию</Button>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
            <div class="rounded-xl border border-border bg-card p-4">
                <div class="flex items-center justify-between"><span class="text-xs font-semibold uppercase ui-subtle">MRR</span><CircleDollarSign class="h-4 w-4 ui-subtle" /></div>
                <p class="mt-2 font-display text-2xl font-bold ui-text">{{ formatMoney(data.kpis.mrr) }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <div class="flex items-center justify-between"><span class="text-xs font-semibold uppercase ui-subtle">ARR</span><CircleDollarSign class="h-4 w-4 ui-subtle" /></div>
                <p class="mt-2 font-display text-2xl font-bold ui-text">{{ formatMoney(data.kpis.arr) }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <div class="flex items-center justify-between"><span class="text-xs font-semibold uppercase ui-subtle">Команда</span><Users class="h-4 w-4 ui-subtle" /></div>
                <p class="mt-2 font-display text-2xl font-bold ui-text">{{ data.kpis.team_count }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <div class="flex items-center justify-between"><span class="text-xs font-semibold uppercase ui-subtle">Сообщения (30д)</span><MessagesSquare class="h-4 w-4 ui-subtle" /></div>
                <p class="mt-2 font-display text-2xl font-bold ui-text">{{ data.kpis.messages_30d }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <div class="flex items-center justify-between"><span class="text-xs font-semibold uppercase ui-subtle">Лиды</span><Target class="h-4 w-4 ui-subtle" /></div>
                <p class="mt-2 font-display text-2xl font-bold ui-text">{{ data.kpis.leads_count }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <div class="flex items-center justify-between"><span class="text-xs font-semibold uppercase ui-subtle">Активные каналы</span><Users class="h-4 w-4 ui-subtle" /></div>
                <p class="mt-2 font-display text-2xl font-bold ui-text">{{ data.kpis.active_channels }} / {{ data.channels.length }}</p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <div class="rounded-xl border border-border bg-card p-5">
                    <h3 class="mb-4 font-display text-base font-semibold ui-text">Данные компании</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Владелец</span>
                            <div v-if="data.owner" class="flex items-center gap-2">
                                <Avatar class="size-6"><AvatarImage v-if="data.owner.avatar_url" :src="data.owner.avatar_url" alt="" /><AvatarFallback class="text-[10px] font-semibold bg-primary text-primary-foreground">{{ data.owner.name[0] }}</AvatarFallback></Avatar>
                                <span class="text-sm ui-text">{{ data.owner.name }}</span>
                                <span class="text-xs ui-subtle">{{ data.owner.email }}</span>
                            </div>
                            <span v-else class="text-sm ui-subtle">Нет владельца</span>
                        </div>
                        <div>
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Контакты</span>
                            <span class="text-sm ui-text">{{ data.company?.phone ?? data.company?.email ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Отрасль</span>
                            <span class="text-sm ui-text">{{ data.company?.industry ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Дата регистрации</span>
                            <span class="font-mono text-sm ui-text">{{ formatDate(data.tenant.created_at) }}</span>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-border bg-card p-5">
                    <h3 class="mb-4 font-display text-base font-semibold ui-text">Лимиты тарифа «{{ planById(data.tenant.plan).name }}»</h3>
                    <div class="space-y-5">
                        <div>
                            <div class="mb-1.5 flex items-center justify-between text-sm">
                                <span class="font-medium ui-text">Диалоги (30 дней)</span>
                                <span class="font-mono text-xs ui-subtle">{{ data.limits.conversations.used }} / {{ data.limits.conversations.limit ?? '∞' }}</span>
                            </div>
                            <div class="h-2 w-full rounded-full bg-muted"><div class="h-2 rounded-full bg-primary" :style="{ width: `${conversationsPercent}%` }"></div></div>
                        </div>
                        <div>
                            <div class="mb-1.5 flex items-center justify-between text-sm">
                                <span class="font-medium ui-text">AI-агенты</span>
                                <span class="font-mono text-xs ui-subtle">{{ data.limits.ai_agents.used }} / {{ data.limits.ai_agents.limit ?? '∞' }}</span>
                            </div>
                            <div class="h-2 w-full rounded-full bg-muted"><div class="h-2 rounded-full bg-primary" :style="{ width: `${aiAgentsPercent}%` }"></div></div>
                        </div>
                    </div>

                    <div class="mt-5 border-t pt-4 border-border">
                        <span class="mb-3 block text-xs font-semibold uppercase ui-subtle">Каналы</span>
                        <div v-if="data.channels.length" class="flex flex-wrap gap-2">
                            <span v-for="channel in data.channels" :key="channel.id" class="flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-medium border-border" :class="channel.status === 'connected' ? 'ui-text' : 'ui-subtle'">
                                <span class="size-1.5 rounded-full" :class="channel.status === 'connected' ? 'bg-primary' : 'bg-muted-foreground'"></span>
                                {{ providerLabels[channel.provider] ?? channel.provider }}
                            </span>
                        </div>
                        <p v-else class="text-sm ui-subtle">Каналы не подключены</p>
                    </div>
                </div>

                <div class="rounded-xl border border-border bg-card p-5">
                    <h3 class="mb-4 font-display text-base font-semibold ui-text">Команда ({{ data.team.length }})</h3>
                    <div class="divide-y divide-border">
                        <div v-for="member in data.team" :key="member.id" class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                            <Avatar class="size-8"><AvatarImage v-if="member.avatar_url" :src="member.avatar_url" alt="" /><AvatarFallback class="text-xs font-semibold bg-accent text-accent-foreground">{{ member.name[0] }}</AvatarFallback></Avatar>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium ui-text">{{ member.name }}</div>
                                <div class="truncate text-xs ui-subtle">{{ member.email }}</div>
                            </div>
                            <Badge variant="outline">{{ roleLabels[member.role] ?? member.role }}</Badge>
                        </div>
                        <p v-if="!data.team.length" class="py-4 text-center text-sm ui-subtle">В команде пока никого нет</p>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h3 class="mb-4 font-display text-base font-semibold ui-text">Последняя активность</h3>
                <div v-if="data.activity.length" class="relative space-y-4 border-l border-border pl-4">
                    <div v-for="entry in data.activity" :key="entry.id" class="relative">
                        <span class="absolute -left-[1.1rem] top-1 size-2 rounded-full bg-primary"></span>
                        <p class="text-sm font-medium ui-text">{{ activityLabel(entry.action) }}</p>
                        <p class="text-xs ui-subtle">{{ entry.user ?? 'Система' }} · {{ formatDateTime(entry.created_at) }}</p>
                    </div>
                </div>
                <p v-else class="text-sm ui-subtle">Активности пока нет</p>
            </div>
        </div>
    </template>

    <AlertDialog v-model:open="deleteOpen">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Удалить компанию «{{ companyName() }}»?</AlertDialogTitle>
                <AlertDialogDescription>
                    Это безвозвратно удалит компанию, всю команду, диалоги, лиды, клиентов, каналы, AI-агентов и базу знаний. Чтобы подтвердить, введите название компании: <strong class="ui-text">{{ companyName() }}</strong>
                </AlertDialogDescription>
            </AlertDialogHeader>
            <Input v-model="deleteConfirmText" placeholder="Введите название компании" />
            <AlertDialogFooter>
                <AlertDialogCancel>Отмена</AlertDialogCancel>
                <AlertDialogAction
                    variant="destructive"
                    :disabled="deleteBusy || deleteConfirmText !== companyName()"
                    @click="confirmDelete"
                >Удалить безвозвратно</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
