<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Bot, Lock, Plus } from '@lucide/vue';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { sourceLabels } from '../../../lib/statusLabels';
import { planById, plans, providerForModel } from '../../../lib/plans';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '../../ui/dialog';
import { Input } from '../../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Textarea } from '../../ui/textarea';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { knowledgeDocuments } = storeToRefs(store);
const open = ref(false);
const form = reactive({ name: '', instructions: '', handoff_threshold: 70, model: '', modelCustom: '' });
const selectedDocIds = ref<number[]>([]);
const selectedChannels = ref<string[]>([]);

const MODEL_OPTIONS = ['openai/gpt-oss-120b', 'openai/gpt-oss-20b', 'gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'deepseek-chat', 'deepseek-reasoner', 'claude-3-5-sonnet-latest', 'claude-3-5-haiku-latest', 'gemini-1.5-pro', 'gemini-1.5-flash'];
const currentPlan = computed(() => planById(store.tenant?.settings?.billing?.plan));
function isModelLocked(model: string): boolean {
    const provider = providerForModel(model);
    return provider !== null && ! currentPlan.value.aiProviders.includes(provider);
}
const maxAiProviders = Math.max(...plans.map((plan) => plan.aiProviders.length));
const CHANNEL_OPTIONS = ['telegram', 'whatsapp', 'instagram', 'facebook', 'website'];
const CHANNEL_STYLES: Record<string, string> = {
    telegram: 'border-brand-telegram/50 bg-brand-telegram/15 text-brand-telegram',
    whatsapp: 'border-brand-whatsapp/50 bg-brand-whatsapp/15 text-brand-whatsapp',
    instagram: 'border-brand-instagram-to/50 bg-brand-instagram-to/15 text-brand-instagram-to',
    facebook: 'border-[#1877F2]/50 bg-[#1877F2]/15 text-[#1877F2]',
    website: 'border-sky-600/50 bg-sky-600/15 text-sky-700 dark:text-sky-300',
    chatwoot: 'border-emerald-600/50 bg-emerald-600/15 text-emerald-700 dark:text-emerald-300',
};

function toggleDoc(id: number, checked: boolean): void {
    selectedDocIds.value = checked
        ? [...selectedDocIds.value, id]
        : selectedDocIds.value.filter((docId) => docId !== id);
}

function toggleChannel(channel: string): void {
    selectedChannels.value = selectedChannels.value.includes(channel)
        ? selectedChannels.value.filter((item) => item !== channel)
        : [...selectedChannels.value, channel];
}

async function submit(): Promise<void> {
    if (! form.name.trim()) return;

    const model = form.model === '__custom__' ? form.modelCustom.trim() : form.model;

    await store.createAiAgent({
        name: form.name.trim(),
        instructions: form.instructions.trim() || undefined,
        handoff_threshold: Number(form.handoff_threshold),
        model: model || undefined,
        channels: selectedChannels.value,
    }, selectedDocIds.value);

    Object.assign(form, { name: '', instructions: '', handoff_threshold: 70, model: '', modelCustom: '' });
    selectedDocIds.value = [];
    selectedChannels.value = [];
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button size="icon-sm" variant="outline" type="button" aria-label="Создать ассистента">
                <Plus class="h-4 w-4" />
            </Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><Bot class="h-4 w-4 text-primary" />Новый AI-ассистент</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Название</span>
                        <Input v-model="form.name" placeholder="Например, Sales Assistant" required />
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Инструкции</span>
                        <Textarea v-model="form.instructions" class="min-h-28" placeholder="Как ассистент должен отвечать клиентам" />
                    </label>
                    <label class="max-w-40">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Порог handoff</span>
                        <Input v-model="form.handoff_threshold" type="number" min="1" max="100" />
                    </label>
                    <div>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('ai.modelLabel') }}</span>
                        <Select v-model="form.model">
                            <SelectTrigger class="w-full"><SelectValue placeholder="Модель по умолчанию" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="model in MODEL_OPTIONS" :key="model" :value="model" :disabled="isModelLocked(model)">
                                    <span class="flex items-center gap-1.5">
                                        <Lock v-if="isModelLocked(model)" class="h-3 w-3 ui-subtle" />
                                        {{ model }}
                                    </span>
                                </SelectItem>
                                <SelectItem value="__custom__">{{ locale.t('ai.modelCustom') }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Input v-if="form.model === '__custom__'" v-model="form.modelCustom" class="mt-2" placeholder="Название модели" />
                        <p class="mt-1 text-[11px] ui-subtle">{{ locale.t('ai.modelHint') }}</p>
                        <p v-if="currentPlan.aiProviders.length < maxAiProviders" class="mt-1 text-[11px] text-amber-600 dark:text-amber-400">
                            На тарифе «{{ currentPlan.name }}» доступны: {{ currentPlan.aiProviders.join(', ') }}.
                        </p>
                    </div>
                    <div>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('ai.channelsLabel') }}</span>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="channel in CHANNEL_OPTIONS"
                                :key="channel"
                                type="button"
                                class="rounded-full border px-3 py-1.5 text-xs font-semibold transition"
                                :class="selectedChannels.includes(channel) ? CHANNEL_STYLES[channel] : 'border-border ui-subtle hover:bg-muted'"
                                @click="toggleChannel(channel)"
                            >
                                {{ sourceLabels[channel] ?? channel }}
                            </button>
                        </div>
                        <p class="mt-1 text-[11px] ui-subtle">{{ locale.t('ai.channelsHint') }}</p>
                    </div>
                    <div v-if="knowledgeDocuments.length">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Источники знаний</span>
                        <div class="max-h-40 divide-y overflow-y-auto rounded-lg border border-border">
                            <label v-for="doc in knowledgeDocuments" :key="doc.id" class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm ui-text hover:bg-muted">
                                <input
                                    type="checkbox"
                                    class="size-4 accent-primary"
                                    :checked="selectedDocIds.includes(doc.id)"
                                    @change="toggleDoc(doc.id, ($event.target as HTMLInputElement).checked)"
                                >
                                <span class="min-w-0 flex-1 truncate">{{ doc.title }}</span>
                            </label>
                        </div>
                    </div>
                </div>
                <DialogFooter>
                    <Button type="submit" variant="primary" :disabled="store.busy || !form.name.trim()">Создать</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
