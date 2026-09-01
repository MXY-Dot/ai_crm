<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { AlertTriangle, CheckCircle2, RefreshCw, Save, ShieldAlert } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import KpiCard from '@/components/dashboard/KpiCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

defineOptions({ layout: SuperAdminLayout });

type ComponentRow = {
    component: string;
    status: 'up' | 'down';
    consecutive_failures: number;
    last_failure_at: string | null;
    last_success_at: string | null;
    last_error: string | null;
};

type Incident = {
    id: number;
    component: string;
    status: 'open' | 'resolved';
    cause: string | null;
    started_at: string;
    resolved_at: string | null;
    affected_conversations_count: number;
    tenant: { id: number; name: string } | null;
};

type Overview = {
    components: ComponentRow[];
    dify: { component: string; tenants_total: number; tenants_down: number };
    incidents: Incident[];
    summary: { open_incidents: number; components_down: number };
};

const data = ref<Overview | null>(null);
const loading = ref(true);

const componentLabels: Record<string, string> = {
    db: 'База данных',
    queue: 'Очередь',
    'llm:groq': 'Groq',
    'llm:openai': 'GPT (OpenAI)',
    'llm:anthropic': 'Claude (Anthropic)',
    'llm:google': 'Gemini (Google)',
    'llm:deepseek': 'DeepSeek',
};

function componentLabel(component: string): string {
    return componentLabels[component] ?? component;
}

async function load(): Promise<void> {
    loading.value = true;
    try {
        data.value = await apiRequest<Overview>('/api/admin/incidents');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить инциденты');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

/**
 * Аварийный режим (per-company) — moved here from the tenant's own Каналы page
 * (see RolePages) so only super_admin sets it, for whichever company they pick.
 */
type TenantOption = { id: number; name: string };
type EmergencyStatus = { mode: 'normal' | 'degraded' | 'emergency'; manual_override: boolean };

const tenantOptions = ref<TenantOption[]>([]);
const selectedTenantId = ref<number | null>(null);
const emergencyStatus = ref<EmergencyStatus | null>(null);
const emergencyLoading = ref(false);
const emergencySaving = ref(false);
const emergencyOverrideBusy = ref(false);
const emergencyForm = reactive({ ru: '', tj: '', en: '', telegramChatId: '' });

const emergencyStatusLabel = computed(() => (emergencyStatus.value?.mode === 'emergency' ? 'AI недоступен' : 'Работает нормально'));

async function loadEmergency(tenantId: number): Promise<void> {
    emergencyLoading.value = true;
    try {
        const [settings, status] = await Promise.all([
            apiRequest<{ fallback_message: { ru: string; tj: string; en: string }; telegram_chat_id: string }>(`/api/admin/companies/${tenantId}/emergency-settings`),
            apiRequest<EmergencyStatus>(`/api/admin/companies/${tenantId}/emergency-status`),
        ]);
        emergencyForm.ru = settings.fallback_message.ru;
        emergencyForm.tj = settings.fallback_message.tj;
        emergencyForm.en = settings.fallback_message.en;
        emergencyForm.telegramChatId = settings.telegram_chat_id;
        emergencyStatus.value = status;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить настройки аварийного режима');
    } finally {
        emergencyLoading.value = false;
    }
}

async function saveEmergency(): Promise<void> {
    if (! selectedTenantId.value) return;

    emergencySaving.value = true;
    try {
        await apiRequest(`/api/admin/companies/${selectedTenantId.value}/emergency-settings`, {
            method: 'PATCH',
            body: {
                fallback_message: { ru: emergencyForm.ru, tj: emergencyForm.tj, en: emergencyForm.en },
                telegram_chat_id: emergencyForm.telegramChatId,
            },
        });
        toast.success('Настройки сохранены');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось сохранить настройки');
    } finally {
        emergencySaving.value = false;
    }
}

async function toggleEmergencyOverride(enabled: boolean): Promise<void> {
    if (! selectedTenantId.value) return;

    emergencyOverrideBusy.value = true;
    try {
        emergencyStatus.value = await apiRequest<EmergencyStatus>(`/api/admin/companies/${selectedTenantId.value}/emergency-override`, {
            method: 'PATCH',
            body: { enabled },
        });
        toast.success(enabled ? 'Ручной режим включён' : 'Ручной режим выключен');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось переключить ручной режим');
    } finally {
        emergencyOverrideBusy.value = false;
    }
}

watch(selectedTenantId, (tenantId) => {
    emergencyStatus.value = null;
    if (tenantId) loadEmergency(tenantId);
});

onMounted(() => {
    apiRequest<TenantOption[]>('/api/admin/companies/lookup').then((options) => { tenantOptions.value = options; }).catch(() => {});
});

const dateFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

function formatDate(value: string | null): string {
    return value ? dateFormatter.format(new Date(value)) : '—';
}

const difyTone = computed(() => {
    if (! data.value) return 'ui-subtle';
    return data.value.dify.tenants_down > 0 ? 'text-destructive' : 'text-primary';
});
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">Аварийный режим</h2>
                <p class="mt-2 text-sm ui-subtle">Состояние Dify/LLM-провайдеров, БД и очереди по всей платформе.</p>
            </div>
            <Button size="sm" variant="outline" :disabled="loading" @click="load">
                <RefreshCw class="h-4 w-4" />Обновить
            </Button>
        </div>

        <div class="rounded-xl border border-border bg-card p-5">
            <h3 class="mb-1 font-display text-base font-semibold ui-text">Аварийный режим компании</h3>
            <p class="mb-4 text-sm ui-subtle">Что видит клиент и кто получает уведомление, если AI перестаёт отвечать — настраивается здесь для выбранной компании.</p>

            <Select v-model="selectedTenantId">
                <SelectTrigger class="w-full sm:w-80"><SelectValue placeholder="Выберите компанию" /></SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="option in tenantOptions" :key="option.id" :value="option.id">{{ option.name }}</SelectItem>
                </SelectContent>
            </Select>

            <Skeleton v-if="emergencyLoading" class="mt-4 h-48 rounded-lg" />

            <div v-else-if="selectedTenantId" class="mt-4 grid gap-4 lg:grid-cols-2">
                <div class="space-y-4">
                    <div class="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs" :class="emergencyStatus?.mode === 'emergency' ? 'border-destructive/30 bg-destructive/10 text-destructive' : 'border-primary/20 bg-primary/5 text-primary'">
                        <ShieldAlert class="size-4 shrink-0" />
                        <span class="font-medium">{{ emergencyStatusLabel }}</span>
                    </div>

                    <label class="flex items-center justify-between gap-3 text-sm">
                        <span>
                            <span class="block font-medium ui-text">Ручной режим</span>
                            <span class="block text-xs ui-subtle">Принудительно перевести всех клиентов этой компании на операторов</span>
                        </span>
                        <Switch :model-value="emergencyStatus?.manual_override ?? false" :disabled="emergencyOverrideBusy" @update:model-value="toggleEmergencyOverride" />
                    </label>

                    <label class="block text-sm">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Telegram-чат для алертов</span>
                        <Input v-model="emergencyForm.telegramChatId" placeholder="ID чата или группы" />
                        <span class="mt-1 block text-xs ui-subtle">Куда придёт уведомление, если AI перестанет отвечать</span>
                    </label>
                </div>

                <form class="space-y-3" @submit.prevent="saveEmergency">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Сообщение клиенту — русский</span>
                        <Textarea v-model="emergencyForm.ru" rows="2" placeholder="Ваше сообщение получили. Оператор скоро вам ответит." />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Сообщение клиенту — тоҷикӣ</span>
                        <Textarea v-model="emergencyForm.tj" rows="2" placeholder="Паёми шуморо гирифтем. Оператор ба зудӣ ба шумо ҷавоб медиҳад." />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Сообщение клиенту — English</span>
                        <Textarea v-model="emergencyForm.en" rows="2" placeholder="Thanks for your message. An operator will reply shortly." />
                    </label>
                    <Button size="sm" variant="primary" type="submit" class="w-full" :disabled="emergencySaving">
                        <Save class="h-4 w-4" />{{ emergencySaving ? 'Сохраняем…' : 'Сохранить' }}
                    </Button>
                </form>
            </div>
        </div>

        <div v-if="loading && ! data" class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            <Skeleton v-for="i in 4" :key="i" class="h-28 rounded-xl" />
        </div>

        <template v-if="data">
            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                <KpiCard label="Открытых инцидентов" :value="data.summary.open_incidents">
                    <template #icon><AlertTriangle class="h-4 w-4 text-destructive" /></template>
                </KpiCard>
                <KpiCard label="Компонентов недоступно" :value="data.summary.components_down">
                    <template #icon><ShieldAlert class="h-4 w-4 text-destructive" /></template>
                </KpiCard>
                <KpiCard label="Dify: tenant'ов не в норме" :value="`${data.dify.tenants_down} / ${data.dify.tenants_total}`">
                    <template #icon><ShieldAlert class="h-4 w-4" :class="difyTone" /></template>
                </KpiCard>
            </div>

            <div>
                <h3 class="mb-3 font-display text-base font-semibold ui-text">Платформенные компоненты</h3>
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <div
                        v-for="row in data.components"
                        :key="row.component"
                        class="flex items-center justify-between rounded-xl border p-4"
                        :class="row.status === 'up' ? 'border-border bg-card' : 'border-destructive/30 bg-destructive/5'"
                    >
                        <div>
                            <p class="font-medium ui-text">{{ componentLabel(row.component) }}</p>
                            <p class="text-xs ui-subtle">{{ row.status === 'up' ? `Ок: ${formatDate(row.last_success_at)}` : `Ошибка: ${row.last_error ?? '—'}` }}</p>
                        </div>
                        <Badge :variant="row.status === 'up' ? 'default' : 'destructive'">
                            <CheckCircle2 v-if="row.status === 'up'" class="h-3 w-3" />
                            <AlertTriangle v-else class="h-3 w-3" />
                            {{ row.status === 'up' ? 'В норме' : 'Недоступен' }}
                        </Badge>
                    </div>
                </div>
            </div>

            <div>
                <h3 class="mb-3 font-display text-base font-semibold ui-text">История инцидентов</h3>
                <div class="overflow-x-auto rounded-xl border border-border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/40 text-xs uppercase ui-subtle">
                            <tr>
                                <th class="px-4 py-2 text-left">Компонент</th>
                                <th class="px-4 py-2 text-left">Компания</th>
                                <th class="px-4 py-2 text-left">Причина</th>
                                <th class="px-4 py-2 text-left">Начало</th>
                                <th class="px-4 py-2 text-left">Конец</th>
                                <th class="px-4 py-2 text-left">Диалогов</th>
                                <th class="px-4 py-2 text-left">Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="incident in data.incidents" :key="incident.id" class="border-t border-border">
                                <td class="px-4 py-2">{{ componentLabel(incident.component) }}</td>
                                <td class="px-4 py-2">{{ incident.tenant?.name ?? '—' }}</td>
                                <td class="px-4 py-2 ui-subtle">{{ incident.cause ?? '—' }}</td>
                                <td class="px-4 py-2 ui-subtle">{{ formatDate(incident.started_at) }}</td>
                                <td class="px-4 py-2 ui-subtle">{{ formatDate(incident.resolved_at) }}</td>
                                <td class="px-4 py-2 ui-subtle">{{ incident.affected_conversations_count }}</td>
                                <td class="px-4 py-2">
                                    <Badge :variant="incident.status === 'open' ? 'destructive' : 'default'">
                                        {{ incident.status === 'open' ? 'Открыт' : 'Закрыт' }}
                                    </Badge>
                                </td>
                            </tr>
                            <tr v-if="! data.incidents.length">
                                <td colspan="7" class="px-4 py-6 text-center ui-subtle">Инцидентов пока не было</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </section>
</template>
