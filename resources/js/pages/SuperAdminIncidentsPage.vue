<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { AlertTriangle, CheckCircle2, RefreshCw, ShieldAlert } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import KpiCard from '@/components/dashboard/KpiCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

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
