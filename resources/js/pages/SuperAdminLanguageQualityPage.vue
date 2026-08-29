<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import { BookOpen, CheckCircle2, Languages, PlayCircle, Plus, Save, Trash2, XCircle } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';

defineOptions({ layout: SuperAdminLayout });

type SystemPrompt = { id: number; version: string; content: string; is_active: boolean; created_at: string };
type LanguageExampleRow = { id: number; tenant_name: string | null; company_name: string | null; customer_message: string; good_reply: string; language: string | null; status: string };
type EvalExample = { id: number; input_text: string; expected_reply: string | null; expected_intent: string | null; notes: string | null };
type EvalResult = {
    id: number; example_id: number; input_text: string | null; expected_reply: string | null; expected_intent: string | null;
    provider: string; model: string; response_text: string | null; success: boolean; error_message: string | null;
    latency_ms: number | null; tokens_in: number | null; tokens_out: number | null; created_at: string;
};

const loading = ref(true);
const prompts = ref<SystemPrompt[]>([]);
const activePrompt = ref<SystemPrompt | null>(null);
const examples = ref<LanguageExampleRow[]>([]);
const examplesApprovedCount = ref(0);
const evalExamples = ref<EvalExample[]>([]);
const latestResults = ref<EvalResult[]>([]);
const running = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const data = await apiRequest<{
            base_knowledge_document: string; prompts: SystemPrompt[]; active_prompt: SystemPrompt | null; examples: LanguageExampleRow[];
            examples_approved_count: number; eval_examples: EvalExample[]; latest_results: EvalResult[];
        }>('/api/admin/language-quality');
        baseKnowledgeInput.value = data.base_knowledge_document;
        prompts.value = data.prompts;
        activePrompt.value = data.active_prompt;
        promptDraft.version = '';
        promptDraft.content = data.active_prompt?.content ?? '';
        examples.value = data.examples;
        examplesApprovedCount.value = data.examples_approved_count;
        evalExamples.value = data.eval_examples;
        latestResults.value = data.latest_results;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить данные');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

// --- base knowledge document (platform-wide, every tenant's prompt) ---
const baseKnowledgeInput = ref('');
const savingBaseKnowledge = ref(false);

async function saveBaseKnowledge(): Promise<void> {
    savingBaseKnowledge.value = true;
    try {
        await apiRequest('/api/admin/language-quality/base-knowledge-document', {
            method: 'PATCH',
            body: { content: baseKnowledgeInput.value },
        });
        toast.success('Базовый документ сохранён');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось сохранить документ');
    } finally {
        savingBaseKnowledge.value = false;
    }
}

// --- system prompt ---
const promptDraft = reactive({ version: '', content: '' });
const savingPrompt = ref(false);

async function saveNewPromptVersion(): Promise<void> {
    if (! promptDraft.version.trim() || ! promptDraft.content.trim()) return;
    savingPrompt.value = true;
    try {
        await apiRequest('/api/admin/language-quality/system-prompts', {
            method: 'POST',
            body: { version: promptDraft.version.trim(), content: promptDraft.content },
        });
        toast.success('Новая версия промпта сохранена и активирована');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось сохранить');
    } finally {
        savingPrompt.value = false;
    }
}

async function activatePrompt(id: number): Promise<void> {
    try {
        await apiRequest(`/api/admin/language-quality/system-prompts/${id}/activate`, { method: 'PATCH' });
        toast.success('Версия активирована');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось активировать');
    }
}

// --- language examples (train set) ---
async function setExampleStatus(id: number, status: string): Promise<void> {
    try {
        await apiRequest(`/api/admin/language-quality/examples/${id}/status`, { method: 'PATCH', body: { status } });
        const row = examples.value.find((e) => e.id === id);
        if (row) row.status = status;
        examplesApprovedCount.value = examples.value.filter((e) => e.status === 'approved').length;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось изменить статус');
    }
}

function statusTone(status: string): 'green' | 'amber' | 'red' {
    if (status === 'approved') return 'green';
    if (status === 'rejected') return 'red';
    return 'amber';
}

// --- eval examples ---
const newEval = reactive({ input_text: '', expected_reply: '', expected_intent: '', notes: '' });
const savingEval = ref(false);

async function addEvalExample(): Promise<void> {
    if (! newEval.input_text.trim()) return;
    savingEval.value = true;
    try {
        await apiRequest('/api/admin/language-quality/eval-examples', {
            method: 'POST',
            body: {
                input_text: newEval.input_text.trim(),
                expected_reply: newEval.expected_reply.trim() || null,
                expected_intent: newEval.expected_intent.trim() || null,
                notes: newEval.notes.trim() || null,
            },
        });
        Object.assign(newEval, { input_text: '', expected_reply: '', expected_intent: '', notes: '' });
        toast.success('Eval-пример добавлен');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось добавить');
    } finally {
        savingEval.value = false;
    }
}

async function deleteEvalExample(id: number): Promise<void> {
    try {
        await apiRequest(`/api/admin/language-quality/eval-examples/${id}`, { method: 'DELETE' });
        evalExamples.value = evalExamples.value.filter((e) => e.id !== id);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось удалить');
    }
}

async function runEval(): Promise<void> {
    if (! evalExamples.value.length) {
        toast.error('Сначала добавьте хотя бы один eval-пример');
        return;
    }
    running.value = true;
    try {
        const data = await apiRequest<{ run_id: string; results: EvalResult[] }>('/api/admin/language-quality/run-eval', { method: 'POST' });
        latestResults.value = data.results;
        toast.success(`Тест завершён: ${data.results.length} результатов`);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось запустить тест');
    } finally {
        running.value = false;
    }
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}
</script>

<template>
    <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-primary">Качество AI</p>
        <h2 class="font-display text-xl font-bold ui-text">Языковые датасеты</h2>
        <p class="mt-1 text-sm ui-subtle">
            Единая точка управления знаниями и языком AI для всей платформы: базовый документ знаний, системный
            промпт для языковой обработки, одобренные примеры диалогов и eval-тестирование ответов Groq/DeepSeek —
            отдельно от учётных данных LLM-провайдеров. Всё, что попадает сюда, применяется сразу ко всем компаниям.
        </p>
    </div>

    <div v-if="loading" class="mt-6 grid gap-4">
        <Skeleton class="h-48 rounded-xl" />
        <Skeleton class="h-48 rounded-xl" />
        <Skeleton class="h-48 rounded-xl" />
    </div>

    <template v-else>
        <section class="mt-6 rounded-xl border border-primary/30 bg-card p-5">
            <h3 class="mb-1 flex items-center gap-2 font-display text-base font-semibold ui-text">
                <BookOpen class="h-4 w-4 text-primary" />Базовые знания для всех компаний
            </h3>
            <p class="mb-3 text-sm ui-subtle">
                Этот текст добавляется в системный промпт AI для КАЖДОЙ компании на платформе, независимо от их
                собственной базы знаний — глоссарий терминов, правильные формулировки на таджикском и русском,
                рамки/ограничения, за которые AI не должен выходить. То, что вы зададите здесь, действует сразу
                на всех клиентов платформы.
            </p>
            <Textarea v-model="baseKnowledgeInput" class="min-h-40 font-mono text-sm" placeholder="Например: глоссарий терминов, правила вежливого обращения, типовые таджикско-русские формулировки, запрещённые темы..." />
            <Button variant="primary" size="sm" class="mt-3" :disabled="savingBaseKnowledge" @click="saveBaseKnowledge">
                <Save class="h-4 w-4" />Сохранить документ
            </Button>
        </section>

        <section class="mt-6 rounded-xl border border-border bg-card p-5">
            <h3 class="mb-1 flex items-center gap-2 font-display text-base font-semibold ui-text">
                <Languages class="h-4 w-4 text-primary" />Системный промпт (Tajik/Russian)
            </h3>
            <p class="mb-3 text-sm ui-subtle">
                Активная версия: <b class="ui-text">{{ activePrompt?.version ?? '—' }}</b>
                <span v-if="activePrompt" class="ml-1">({{ formatDate(activePrompt.created_at) }})</span>.
                Черновик, составленный ИИ — <b>не проверен носителем языка</b>, правьте текст ниже перед тем как полагаться на него.
            </p>
            <Textarea v-model="promptDraft.content" class="min-h-56 font-mono text-xs" />
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <Input v-model="promptDraft.version" placeholder="Версия, напр. v0.2" class="w-40" />
                <Button variant="primary" size="sm" :disabled="savingPrompt || !promptDraft.version.trim()" @click="saveNewPromptVersion">
                    <Save class="h-4 w-4" />Сохранить как новую версию
                </Button>
            </div>
            <div v-if="prompts.length > 1" class="mt-4 border-t pt-3 border-border">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide ui-subtle">История версий</p>
                <div class="space-y-1.5">
                    <div v-for="p in prompts" :key="p.id" class="flex items-center justify-between text-sm">
                        <span class="ui-text">{{ p.version }} <span class="ui-subtle">· {{ formatDate(p.created_at) }}</span></span>
                        <Badge v-if="p.is_active" tone="green">Активна</Badge>
                        <Button v-else variant="outline" size="sm" @click="activatePrompt(p.id)">Активировать</Button>
                    </div>
                </div>
            </div>
        </section>

        <section class="mt-6 rounded-xl border border-border bg-card p-5">
            <h3 class="mb-1 font-display text-base font-semibold ui-text">Train-примеры (few-shot)</h3>
            <p class="mb-3 text-sm ui-subtle">
                {{ examplesApprovedCount }} из {{ examples.length }} одобрены — в промпт модели попадают только
                примеры со статусом «Одобрен». Примеры заполняются самими компаниями на странице AI-агента.
            </p>
            <div v-if="examples.length" class="max-h-96 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs uppercase tracking-wide ui-subtle">
                            <th class="py-2 pr-3">Компания</th>
                            <th class="py-2 pr-3">Сообщение клиента</th>
                            <th class="py-2 pr-3">Хороший ответ</th>
                            <th class="py-2 pr-3">Статус</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in examples" :key="row.id">
                            <td class="py-2 pr-3 ui-subtle">{{ row.company_name ?? row.tenant_name ?? '—' }}</td>
                            <td class="max-w-56 truncate py-2 pr-3 ui-text">{{ row.customer_message }}</td>
                            <td class="max-w-56 truncate py-2 pr-3 ui-text">{{ row.good_reply }}</td>
                            <td class="py-2 pr-3">
                                <Select :model-value="row.status" @update:model-value="(v) => setExampleStatus(row.id, String(v))">
                                    <SelectTrigger class="h-7 w-32 text-xs"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="approved">Одобрен</SelectItem>
                                        <SelectItem value="pending">На проверке</SelectItem>
                                        <SelectItem value="rejected">Отклонён</SelectItem>
                                    </SelectContent>
                                </Select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-xs ui-subtle">Пока ни одна компания не добавила примеры диалогов.</p>
        </section>

        <section class="mt-6 rounded-xl border border-border bg-card p-5">
            <h3 class="mb-1 font-display text-base font-semibold ui-text">Eval-примеры</h3>
            <p class="mb-3 text-sm ui-subtle">
                Хранятся отдельно от train-примеров и никогда не попадают в промпт модели — только для тестирования.
                Реального файла на 85 примеров не было приложено; ниже — стартовый набор, добавляйте свои.
            </p>
            <div class="mb-4 grid gap-2 sm:grid-cols-2">
                <Input v-model="newEval.input_text" placeholder="Сообщение клиента" />
                <Input v-model="newEval.expected_intent" placeholder="Ожидаемое намерение (необязательно)" />
                <Input v-model="newEval.expected_reply" class="sm:col-span-2" placeholder="Ожидаемый/эталонный ответ (необязательно)" />
                <Input v-model="newEval.notes" class="sm:col-span-2" placeholder="Заметки (необязательно)" />
            </div>
            <Button size="sm" variant="outline" :disabled="savingEval || !newEval.input_text.trim()" @click="addEvalExample">
                <Plus class="h-4 w-4" />Добавить пример
            </Button>

            <div v-if="evalExamples.length" class="mt-4 divide-y divide-border border-t border-border">
                <div v-for="e in evalExamples" :key="e.id" class="flex items-start justify-between gap-3 py-2 text-sm">
                    <div class="min-w-0">
                        <p class="ui-text">{{ e.input_text }}</p>
                        <p v-if="e.expected_reply" class="mt-0.5 text-xs ui-subtle">Ожидается: {{ e.expected_reply }}</p>
                    </div>
                    <button type="button" class="shrink-0 text-destructive hover:text-destructive/80" @click="deleteEvalExample(e.id)">
                        <Trash2 class="h-4 w-4" />
                    </button>
                </div>
            </div>
        </section>

        <section class="mt-6 rounded-xl border border-border bg-card p-5">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="font-display text-base font-semibold ui-text">Результаты тестирования</h3>
                    <p class="text-sm ui-subtle">Каждый eval-пример прогоняется через Groq и DeepSeek напрямую (тот же код, что и в реальных ответах).</p>
                </div>
                <Button variant="primary" size="sm" :disabled="running || !evalExamples.length" @click="runEval">
                    <PlayCircle class="h-4 w-4" />{{ running ? 'Выполняется...' : `Запустить тест (${evalExamples.length} × 2)` }}
                </Button>
            </div>

            <div v-if="latestResults.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs uppercase tracking-wide ui-subtle">
                            <th class="py-2 pr-3">Модель</th>
                            <th class="py-2 pr-3">Сообщение</th>
                            <th class="py-2 pr-3">Ответ модели</th>
                            <th class="py-2 pr-3">Ожидаемый ответ</th>
                            <th class="py-2 pr-3">Статус</th>
                            <th class="py-2 pr-3">Время</th>
                            <th class="py-2 pr-3">Токены</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="r in latestResults" :key="r.id">
                            <td class="py-2 pr-3 font-mono text-xs ui-text">{{ r.provider }}<br><span class="ui-subtle">{{ r.model }}</span></td>
                            <td class="max-w-48 truncate py-2 pr-3 ui-text">{{ r.input_text }}</td>
                            <td class="max-w-64 truncate py-2 pr-3 ui-text">{{ r.response_text ?? r.error_message }}</td>
                            <td class="max-w-48 truncate py-2 pr-3 ui-subtle">{{ r.expected_reply ?? '—' }}</td>
                            <td class="py-2 pr-3">
                                <span v-if="r.success" class="inline-flex items-center gap-1 text-primary"><CheckCircle2 class="h-3.5 w-3.5" />Успешно</span>
                                <span v-else class="inline-flex items-center gap-1 text-destructive"><XCircle class="h-3.5 w-3.5" />Ошибка</span>
                            </td>
                            <td class="py-2 pr-3 font-mono text-xs ui-subtle">{{ r.latency_ms ? `${r.latency_ms} мс` : '—' }}</td>
                            <td class="py-2 pr-3 font-mono text-xs ui-subtle">{{ r.tokens_in ?? '—' }}/{{ r.tokens_out ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-xs ui-subtle">Тест ещё не запускался.</p>
        </section>
    </template>
</template>
