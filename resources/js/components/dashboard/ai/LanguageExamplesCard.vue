<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { Languages, Plus, Trash2 } from '@lucide/vue';
import { apiRequest } from '@/lib/apiClient';
import { useCrmDashboardStore } from '@/stores/crmDashboard';
import { useLocaleStore } from '@/stores/locale';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
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
    <Card :title="locale.t('languageExamples.title')" :subtitle="locale.t('languageExamples.subtitle')">
        <div class="space-y-4">
            <form class="grid gap-3 rounded-xl border p-4 border-border bg-muted/40" @submit.prevent="submit">
                <Textarea v-model="form.customer_message" class="min-h-16" :placeholder="locale.t('languageExamples.customerPlaceholder')" required />
                <Textarea v-model="form.good_reply" class="min-h-16" :placeholder="locale.t('languageExamples.replyPlaceholder')" required />
                <div class="flex flex-wrap items-center gap-3">
                    <Select v-model="form.language">
                        <SelectTrigger class="w-48"><SelectValue :placeholder="locale.t('languageExamples.languageOptional')" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="(label, code) in LANGUAGE_LABELS" :key="code" :value="code">{{ label }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button class="ml-auto" variant="primary" type="submit" :disabled="saving">
                        <Plus class="h-4 w-4" />{{ locale.t('languageExamples.add') }}
                    </Button>
                </div>
            </form>

            <div v-if="loading" class="grid gap-2">
                <Skeleton v-for="i in 2" :key="i" class="h-16 rounded-lg" />
            </div>
            <p v-else-if="! examples.length" class="rounded-lg border border-dashed border-border p-4 text-center text-sm ui-subtle">
                <Languages class="mx-auto mb-2 h-5 w-5 ui-subtle" />
                {{ locale.t('languageExamples.empty') }}
            </p>
            <div v-else class="grid gap-2">
                <article v-for="example in examples" :key="example.id" class="rounded-lg border p-3 border-border bg-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 space-y-1">
                            <p class="truncate text-sm ui-text"><span class="ui-subtle">{{ locale.t('languageExamples.customerLabel') }}:</span> {{ example.customer_message }}</p>
                            <p class="truncate text-sm ui-text"><span class="ui-subtle">{{ locale.t('languageExamples.replyLabel') }}:</span> {{ example.good_reply }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span v-if="example.language" class="rounded bg-muted px-2 py-0.5 text-xs ui-subtle">{{ LANGUAGE_LABELS[example.language] ?? example.language }}</span>
                            <Button size="icon" variant="ghost" :disabled="deletingId === example.id" @click="remove(example.id)">
                                <Trash2 class="h-4 w-4 text-destructive" />
                            </Button>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </Card>
</template>
