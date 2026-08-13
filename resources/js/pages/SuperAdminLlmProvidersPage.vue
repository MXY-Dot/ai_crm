<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import type { Component } from 'vue';
import { Activity, Brain, CheckCircle2, Cpu, DollarSign, PlugZap, Radio, RefreshCw, Save, Workflow, Zap } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import KpiCard from '@/components/dashboard/KpiCard.vue';
import ChannelCard from '@/components/dashboard/channels/ChannelCard.vue';
import ProviderTrendChart from '@/components/dashboard/ai/ProviderTrendChart.vue';
import ClaudeIcon from '@/components/icons/ClaudeIcon.vue';
import GoogleIcon from '@/components/icons/GoogleIcon.vue';
import OpenAiIcon from '@/components/icons/OpenAiIcon.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';

defineOptions({ layout: SuperAdminLayout });

type DayPoint = { date: string; label: string; requests: number };
type ProviderStats = { requests_30d: number; tokens_in_30d: number; tokens_out_30d: number; cost_usd_30d: number; daily: DayPoint[] };
type ProviderRow = { provider: string; label: string; configured: boolean; key_mask: string | null; external_url: string | null; stats: ProviderStats };
type TopTenant = { tenant_id: number; name: string; requests_30d: number; cost_usd_30d: number };

type Overview = {
    providers: ProviderRow[];
    dify: { configured: boolean; key_mask: string | null };
    primary_provider: string;
    backup_provider: string | null;
    usage: { top_tenants: TopTenant[]; requests_this_month: number };
};

// Real brand logos where Font Awesome free has them (OpenAI/Claude/Google); Groq and DeepSeek
// have no official icon in either Lucide or Font Awesome free, so a generic Lucide icon stands in.
const providerIcons: Record<string, Component> = { groq: Zap, openai: OpenAiIcon, deepseek: Brain, anthropic: ClaudeIcon, google: GoogleIcon };

const data = ref<Overview | null>(null);
const loading = ref(true);

const keyInput = reactive<Record<string, string>>({ groq: '', openai: '', anthropic: '', google: '', deepseek: '' });
const savingKey = reactive<Record<string, boolean>>({ groq: false, openai: false, anthropic: false, google: false, deepseek: false });
const testingKey = reactive<Record<string, boolean>>({ groq: false, openai: false, anthropic: false, google: false, deepseek: false });
const testResult = reactive<Record<string, { ok: boolean; message?: string; models?: string[] } | null>>({
    groq: null, openai: null, anthropic: null, google: null, deepseek: null,
});

const primarySelect = ref('groq');
const backupSelect = ref<string>('none');
const savingPrimary = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    try {
        data.value = await apiRequest<Overview>('/api/admin/llm-providers');
        primarySelect.value = data.value.primary_provider;
        backupSelect.value = data.value.backup_provider ?? 'none';
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить LLM-провайдеров');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function providerRow(provider: string): ProviderRow | undefined {
    return data.value?.providers.find((p) => p.provider === provider);
}

async function saveKey(provider: string): Promise<void> {
    const value = keyInput[provider].trim();
    if (! value) return;
    savingKey[provider] = true;
    try {
        await apiRequest(`/api/admin/llm-providers/${provider}/key`, { method: 'PATCH', body: { api_key: value } });
        toast.success(`Ключ ${providerRow(provider)?.label ?? provider} сохранён`);
        keyInput[provider] = '';
        testResult[provider] = null;
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось сохранить ключ');
    } finally {
        savingKey[provider] = false;
    }
}

async function testProvider(provider: string): Promise<void> {
    testingKey[provider] = true;
    testResult[provider] = null;
    try {
        testResult[provider] = await apiRequest(`/api/admin/llm-providers/${provider}/test`, { method: 'POST' });
        if (testResult[provider]?.ok) toast.success(`${providerRow(provider)?.label ?? provider} подключён`);
    } catch (error) {
        testResult[provider] = { ok: false, message: error instanceof Error ? error.message : 'Ошибка проверки' };
    } finally {
        testingKey[provider] = false;
    }
}

async function savePrimary(): Promise<void> {
    savingPrimary.value = true;
    try {
        await apiRequest('/api/admin/llm-providers/primary', {
            method: 'PATCH',
            body: { primary_provider: primarySelect.value, backup_provider: backupSelect.value === 'none' ? null : backupSelect.value },
        });
        toast.success('Основной/резервный провайдер обновлён');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось сохранить выбор');
    } finally {
        savingPrimary.value = false;
    }
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat('ru-RU').format(value);
}
function formatCost(value: number): string {
    return '$' + value.toFixed(value < 1 ? 4 : 2);
}

const totalCost30d = computed(() => (data.value?.providers ?? []).reduce((sum, p) => sum + p.stats.cost_usd_30d, 0));
const totalRequests30d = computed(() => (data.value?.providers ?? []).reduce((sum, p) => sum + p.stats.requests_30d, 0));
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-display text-xl font-bold ui-text">LLM-провайдеры</h2>
            <p class="mt-1 text-sm ui-subtle">Платформа управляет ключами всех AI-моделей централизованно — компании выбирают модель по своему тарифу и просто пользуются ей, без собственных ключей.</p>
        </div>
        <Button variant="outline" size="sm" :disabled="loading" @click="load"><RefreshCw class="h-4 w-4" />Обновить</Button>
    </div>

    <div v-if="loading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Skeleton v-for="i in 4" :key="i" class="h-28 rounded-xl" />
    </div>
    <div v-else-if="data" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <KpiCard label="Запросов в этом месяце" :value="formatNumber(data.usage.requests_this_month)" hint="Прямые вызовы LLM">
            <template #icon><Activity class="h-4 w-4 ui-subtle" /></template>
        </KpiCard>
        <KpiCard label="Запросов за 30 дней" :value="formatNumber(totalRequests30d)" hint="По всем провайдерам">
            <template #icon><Radio class="h-4 w-4 ui-subtle" /></template>
        </KpiCard>
        <KpiCard label="Стоимость за 30 дней" :value="formatCost(totalCost30d)" hint="Оценка по токенам">
            <template #icon><DollarSign class="h-4 w-4 text-primary" /></template>
        </KpiCard>
        <KpiCard label="Основной провайдер" :value="providerRow(data.primary_provider)?.label ?? data.primary_provider" :hint="data.backup_provider ? `Резерв: ${providerRow(data.backup_provider)?.label ?? data.backup_provider}` : 'Резерв не задан'">
            <template #icon><Cpu class="h-4 w-4 text-primary" /></template>
        </KpiCard>
    </div>

    <div v-if="data">
        <h3 class="mb-3 font-display text-base font-semibold ui-text">Провайдеры моделей</h3>
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            <ChannelCard
                v-for="p in data.providers"
                :key="p.provider"
                :icon="providerIcons[p.provider] ?? Cpu"
                :name="p.label"
                brand="blue"
                :status="p.configured ? 'connected' : 'pending'"
                :external-url="p.external_url"
            >
                <template #stats>
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div>
                            <p class="font-display text-lg font-bold ui-text">{{ formatNumber(p.stats.requests_30d) }}</p>
                            <p class="text-[10px] uppercase tracking-wide ui-subtle">Запросов/30д</p>
                        </div>
                        <div>
                            <p class="font-display text-lg font-bold ui-text">{{ formatNumber(p.stats.tokens_in_30d + p.stats.tokens_out_30d) }}</p>
                            <p class="text-[10px] uppercase tracking-wide ui-subtle">Токенов/30д</p>
                        </div>
                        <div>
                            <p class="font-display text-lg font-bold ui-text">{{ formatCost(p.stats.cost_usd_30d) }}</p>
                            <p class="text-[10px] uppercase tracking-wide ui-subtle">Расход/30д</p>
                        </div>
                    </div>
                    <ProviderTrendChart :daily="p.stats.daily" />
                </template>

                <form class="space-y-3" @submit.prevent="saveKey(p.provider)">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">API-ключ (платформенный)</span>
                        <Input v-model="keyInput[p.provider]" type="password" placeholder="sk-..." autocomplete="new-password" />
                        <span v-if="p.key_mask" class="mt-1 block text-xs ui-subtle">Текущий: {{ p.key_mask }}</span>
                    </label>
                    <div v-if="testResult[p.provider]" class="rounded-lg border p-3 text-xs" :class="testResult[p.provider]?.ok ? 'border-primary/20 bg-primary/5' : 'border-destructive/20 bg-destructive/5'">
                        <p class="mb-1.5 flex items-center gap-1.5 font-semibold" :class="testResult[p.provider]?.ok ? 'text-primary' : 'text-destructive'">
                            <CheckCircle2 class="h-3.5 w-3.5" />{{ testResult[p.provider]?.ok ? 'Подключение работает' : 'Ошибка подключения' }}
                        </p>
                        <p class="ui-subtle">{{ testResult[p.provider]?.ok ? testResult[p.provider]?.models?.slice(0, 6).join(', ') : testResult[p.provider]?.message }}</p>
                    </div>
                    <div class="flex gap-2">
                        <Button size="sm" variant="primary" type="submit" class="flex-1" :disabled="savingKey[p.provider] || !keyInput[p.provider].trim()">
                            <Save class="h-4 w-4" />Сохранить
                        </Button>
                        <Button size="sm" variant="outline" type="button" :disabled="testingKey[p.provider] || !p.configured" @click="testProvider(p.provider)">
                            <PlugZap class="h-4 w-4" />Проверить
                        </Button>
                    </div>
                </form>
            </ChannelCard>
        </div>
    </div>

    <div v-if="data" class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-xl border border-border bg-card p-5">
            <h3 class="mb-4 font-display text-base font-semibold ui-text">Основной / резервный провайдер</h3>
            <p class="mb-3 text-sm ui-subtle">Приоритет провайдера для будущего автоматического fallback (переключение при сбое — отдельный этап). Пока это только сохранённый выбор.</p>
            <div class="grid grid-cols-2 gap-3">
                <label>
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Основной</span>
                    <Select v-model="primarySelect">
                        <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="p in data.providers" :key="p.provider" :value="p.provider">{{ p.label }}</SelectItem>
                        </SelectContent>
                    </Select>
                </label>
                <label>
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Резервный</span>
                    <Select v-model="backupSelect">
                        <SelectTrigger class="w-full"><SelectValue placeholder="Не задан" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Не задан</SelectItem>
                            <SelectItem v-for="p in data.providers" :key="p.provider" :value="p.provider">{{ p.label }}</SelectItem>
                        </SelectContent>
                    </Select>
                </label>
            </div>
            <Button variant="primary" size="sm" class="mt-4" :disabled="savingPrimary" @click="savePrimary">Сохранить выбор</Button>
        </div>

        <div class="rounded-xl border border-border bg-card p-5">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="flex items-center gap-2 font-display text-base font-semibold ui-text"><Workflow class="h-4 w-4 text-primary" />Dify (AI-оркестрация)</h3>
                    <p class="mt-1 text-sm ui-subtle">Статус платформенного ключа AI-оркестрации — настраивается на сервере, не из этого экрана.</p>
                    <p v-if="data.dify.key_mask" class="mt-1 font-mono text-xs ui-subtle">Текущий: {{ data.dify.key_mask }}</p>
                </div>
                <Badge :tone="data.dify.configured ? 'green' : 'red'">{{ data.dify.configured ? 'Подключён' : 'Не настроен' }}</Badge>
            </div>
        </div>
    </div>

    <div v-if="data && data.usage.top_tenants.length" class="rounded-xl border border-border bg-card p-5">
        <h3 class="mb-4 font-display text-base font-semibold ui-text">Топ компаний по использованию AI (30 дней)</h3>
        <div class="space-y-3">
            <div v-for="row in data.usage.top_tenants" :key="row.tenant_id" class="flex items-center justify-between text-sm">
                <span class="ui-text">{{ row.name }}</span>
                <span class="font-mono text-xs ui-subtle">{{ formatNumber(row.requests_30d) }} запросов · {{ formatCost(row.cost_usd_30d) }}</span>
            </div>
        </div>
    </div>
</template>
