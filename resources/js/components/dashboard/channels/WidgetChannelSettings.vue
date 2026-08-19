<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { Copy, HelpCircle, MessageSquare, RefreshCw, Save } from '@lucide/vue';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import type { WidgetLauncherIcon } from '../../../stores/crmDashboard';
import { Button } from '../../ui/button';
import { Textarea } from '../../ui/textarea';

const store = useCrmDashboardStore();
const { widgetSettings, busy } = storeToRefs(store);

const form = reactive({
    welcomeMessage: '',
    color: '#16a34a',
    position: 'right' as 'right' | 'left',
    launcherIcon: 'chat' as WidgetLauncherIcon,
});
const copied = ref(false);

const LAUNCHER_ICONS: { value: WidgetLauncherIcon; label: string }[] = [
    { value: 'chat', label: 'Диалог' },
    { value: 'message', label: 'Письмо' },
    { value: 'help', label: 'Вопрос' },
];

onMounted(async () => {
    if (! widgetSettings.value) await store.loadWidgetSettings();
});

watch(widgetSettings, (value) => {
    form.welcomeMessage = value?.welcome_message ?? '';
    form.color = value?.color ?? '#16a34a';
    form.position = value?.position ?? 'right';
    form.launcherIcon = value?.launcher_icon ?? 'chat';
}, { immediate: true });

async function copySnippet(): Promise<void> {
    if (! widgetSettings.value?.embed_snippet) return;
    await navigator.clipboard.writeText(widgetSettings.value.embed_snippet);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}

async function save(): Promise<void> {
    await store.updateWidgetSettings({
        welcomeMessage: form.welcomeMessage.trim(),
        color: form.color,
        position: form.position,
        launcherIcon: form.launcherIcon,
    });
}

async function regenerateKey(): Promise<void> {
    if (! confirm('Старый код виджета перестанет работать на сайте — придётся вставить новый. Продолжить?')) return;
    await store.regenerateWidgetKey();
}
</script>

<template>
    <div class="space-y-4 text-sm">
        <p class="ui-subtle">Вставьте этот код перед закрывающим тегом <code class="rounded bg-muted px-1 py-0.5 text-xs">&lt;/body&gt;</code> на вашем сайте — чат появится на странице. Когда диалог открыт у оператора, посетитель видит его имя и фото; иначе — что отвечает AI.</p>

        <div v-if="widgetSettings" class="rounded-lg border p-3 border-border bg-muted">
            <span class="mb-1 block text-[11px] font-semibold uppercase ui-subtle">Код для вставки</span>
            <p class="break-all font-mono text-xs ui-text">{{ widgetSettings.embed_snippet }}</p>
            <button type="button" class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline" @click="copySnippet">
                <Copy class="h-3.5 w-3.5" /> {{ copied ? 'Скопировано' : 'Скопировать' }}
            </button>
        </div>

        <form class="space-y-4" @submit.prevent="save">
            <label class="block text-sm">
                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Приветственное сообщение</span>
                <Textarea v-model="form.welcomeMessage" rows="3" placeholder="Здравствуйте! Чем можем помочь?" />
                <span class="mt-1 block text-xs ui-subtle">Показывается первым, до того как посетитель напишет что-то сам.</span>
            </label>

            <div class="flex gap-4">
                <label class="block flex-1 text-sm">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Цвет виджета</span>
                    <div class="flex items-center gap-2">
                        <input v-model="form.color" type="color" class="h-9 w-12 shrink-0 cursor-pointer rounded-md border border-border bg-transparent p-0.5" />
                        <input
                            v-model="form.color"
                            type="text"
                            maxlength="7"
                            class="h-9 w-full rounded-lg border border-border bg-transparent px-2.5 font-mono text-xs ui-text outline-none"
                        />
                    </div>
                </label>

                <div class="block flex-1 text-sm">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Расположение</span>
                    <div class="flex h-9 gap-1 rounded-lg border border-border p-0.5">
                        <button
                            type="button"
                            class="flex-1 rounded-md text-xs font-medium transition"
                            :class="form.position === 'left' ? 'bg-primary text-primary-foreground' : 'ui-subtle hover:bg-muted'"
                            @click="form.position = 'left'"
                        >
                            Слева
                        </button>
                        <button
                            type="button"
                            class="flex-1 rounded-md text-xs font-medium transition"
                            :class="form.position === 'right' ? 'bg-primary text-primary-foreground' : 'ui-subtle hover:bg-muted'"
                            @click="form.position = 'right'"
                        >
                            Справа
                        </button>
                    </div>
                </div>
            </div>

            <div class="block text-sm">
                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Значок на кнопке</span>
                <div class="flex gap-2">
                    <button
                        v-for="icon in LAUNCHER_ICONS"
                        :key="icon.value"
                        type="button"
                        class="flex flex-1 flex-col items-center gap-1 rounded-lg border py-2 text-xs font-medium transition"
                        :class="form.launcherIcon === icon.value ? 'border-primary bg-primary/10 text-primary' : 'border-border ui-subtle hover:bg-muted'"
                        @click="form.launcherIcon = icon.value"
                    >
                        <MessageSquare v-if="icon.value === 'message'" class="h-4 w-4" />
                        <HelpCircle v-else-if="icon.value === 'help'" class="h-4 w-4" />
                        <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
                        </svg>
                        {{ icon.label }}
                    </button>
                </div>
            </div>

            <div class="flex gap-2">
                <Button size="sm" variant="primary" type="submit" class="flex-1" :disabled="busy">
                    <Save class="h-4 w-4" /> {{ busy ? 'Сохранение...' : 'Сохранить' }}
                </Button>
                <Button size="sm" variant="outline" type="button" :disabled="busy" @click="regenerateKey">
                    <RefreshCw class="h-4 w-4" /> Обновить ключ
                </Button>
            </div>
        </form>
    </div>
</template>
