<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { Languages, Plus, Sparkles, Trash2, User } from '@lucide/vue';
import { apiRequest } from '@/lib/apiClient';
import { useCrmDashboardStore } from '@/stores/crmDashboard';
import { useLocaleStore } from '@/stores/locale';
import { Bubble, BubbleContent } from '../../ui/bubble';
import { Button } from '../../ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Skeleton } from '../../ui/skeleton';
import { Textarea } from '../../ui/textarea';

type LanguageExample = {
    id: number;
    customer_message: string;
    good_reply: string;
    language: string | null;
    created_at?: string | null;
};

const LANGUAGE_LABELS: Record<string, string> = {
    ru: 'Русский',
    tj: 'Тоҷикӣ (кириллица)',
    tj_latin: 'Тоҷикӣ (латиница)',
    en: 'English',
};

const dashboard = useCrmDashboardStore();
const locale = useLocaleStore();
const { tenant, company } = storeToRefs(dashboard);

const examples = ref<LanguageExample[]>([]);
const loading = ref(true);
const saving = ref(false);
const deletingId = ref<number | null>(null);
const form = reactive({ customer_message: '', good_reply: '', language: '' as string });

async function load(): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    loading.value = true;
    try {
        const response = await apiRequest<{ data: LanguageExample[] }>('/api/language-examples', { tenant: slug });
        examples.value = response.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить примеры');
    } finally {
        loading.value = false;
    }
}

async function submit(): Promise<void> {
    const slug = tenant.value?.slug;
    const companyId = company.value?.id;
    if (! slug || ! companyId) return;

    saving.value = true;
    try {
        await apiRequest('/api/language-examples', {
            method: 'POST',
            tenant: slug,
            body: {
                company_id: companyId,
                customer_message: form.customer_message,
                good_reply: form.good_reply,
                language: form.language || null,
            },
        });
        Object.assign(form, { customer_message: '', good_reply: '', language: '' });
        toast.success('Пример добавлен');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось сохранить пример');
    } finally {
        saving.value = false;
    }
}

async function remove(id: number): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    deletingId.value = id;
    try {
        await apiRequest(`/api/language-examples/${id}`, { method: 'DELETE', tenant: slug });
        examples.value = examples.value.filter((example) => example.id !== id);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось удалить пример');
    } finally {
        deletingId.value = null;
    }
}

onMounted(load);
</script>

<template>
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_1.3fr]">
        <section>
            <h3 class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide ui-subtle">
                <Plus class="h-3.5 w-3.5" />{{ locale.t('languageExamples.addSectionTitle') }}
            </h3>
            <form class="grid gap-3 rounded-xl border p-4 border-border bg-muted/40" @submit.prevent="submit">
                <label class="block text-sm">
                    <span class="mb-1 flex items-center gap-1.5 text-xs font-medium ui-subtle"><User class="h-3.5 w-3.5" />{{ locale.t('languageExamples.customerLabel') }}</span>
                    <Textarea v-model="form.customer_message" class="min-h-16" :placeholder="locale.t('languageExamples.customerPlaceholder')" required />
                </label>
                <label class="block text-sm">
                    <span class="mb-1 flex items-center gap-1.5 text-xs font-medium text-primary"><Sparkles class="h-3.5 w-3.5" />{{ locale.t('languageExamples.replyLabel') }}</span>
                    <Textarea v-model="form.good_reply" class="min-h-16" :placeholder="locale.t('languageExamples.replyPlaceholder')" required />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('languageExamples.languageOptional') }}</span>
                    <Select v-model="form.language">
                        <SelectTrigger class="w-full"><SelectValue :placeholder="locale.t('languageExamples.languageOptional')" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="(label, code) in LANGUAGE_LABELS" :key="code" :value="code">{{ label }}</SelectItem>
                        </SelectContent>
                    </Select>
                </label>
                <Button variant="primary" type="submit" :disabled="saving">
                    <Plus class="h-4 w-4" />{{ locale.t('languageExamples.add') }}
                </Button>
            </form>
        </section>

        <section>
            <h3 class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide ui-subtle">
                <Languages class="h-3.5 w-3.5" />{{ locale.t('languageExamples.savedSectionTitle') }} ({{ examples.length }})
            </h3>

            <div v-if="loading" class="grid gap-3">
                <Skeleton v-for="i in 2" :key="i" class="h-24 rounded-xl" />
            </div>
            <p v-else-if="! examples.length" class="rounded-xl border border-dashed border-border p-6 text-center text-sm ui-subtle">
                <Languages class="mx-auto mb-2 h-5 w-5 ui-subtle" />
                {{ locale.t('languageExamples.empty') }}
            </p>
            <div v-else class="max-h-[32rem] space-y-4 overflow-y-auto pr-1">
                <article v-for="example in examples" :key="example.id" class="group rounded-xl border p-4 border-border bg-card">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <span v-if="example.language" class="rounded bg-muted px-2 py-0.5 text-[11px] font-medium ui-subtle">{{ LANGUAGE_LABELS[example.language] ?? example.language }}</span>
                        <span v-else />
                        <Button size="icon" variant="ghost" class="opacity-0 transition-opacity group-hover:opacity-100" :disabled="deletingId === example.id" @click="remove(example.id)">
                            <Trash2 class="h-4 w-4 text-destructive" />
                        </Button>
                    </div>
                    <div class="flex flex-col gap-2">
                        <Bubble align="start" variant="muted">
                            <BubbleContent>{{ example.customer_message }}</BubbleContent>
                        </Bubble>
                        <Bubble align="end" variant="tinted">
                            <BubbleContent>{{ example.good_reply }}</BubbleContent>
                        </Bubble>
                    </div>
                </article>
            </div>
        </section>
    </div>
</template>
