<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { ArrowRight, Building2, Sparkles } from '@lucide/vue';
import { apiRequest } from '../lib/apiClient';
import { Button } from '../components/ui/button';
import { Card } from '../components/ui/card';
import { Input } from '../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../components/ui/select';
import { Textarea } from '../components/ui/textarea';

defineProps<{
    businessTypes: { id: number; key: string; name: string }[];
    modules: Record<string, string>;
}>();

const step = ref<1 | 2 | 3>(1);
const submitting = ref(false);

const form = reactive({
    business_type_id: '' as string,
    business_type_other: '',
    existing_system: 'none',
});

const EXISTING_SYSTEM_LABELS: Record<string, string> = {
    none: 'Не используем',
    crm: 'Используем CRM',
    '1c': 'Используем 1С',
    warehouse: 'Используем систему управления складом',
    booking: 'Используем систему бронирования',
    own: 'Используем собственную платформу',
    other: 'Используем другую программу',
};

const integrationForm = reactive({
    platform_name: '',
    platform_url: '',
    plan_version: '',
    tech_contact: '',
    api_docs_url: '',
    data_to_receive: [] as string[],
    data_to_send: [] as string[],
    sync_frequency: '',
    scenario_description: '',
    comment: '',
});

const RECEIVE_OPTIONS = ['Клиенты', 'Товары', 'Цены', 'Остатки', 'Заказы', 'Статусы заказов', 'Платежи', 'Расписание', 'Свободные места', 'Данные доставки'];
const SEND_OPTIONS = ['Нового клиента', 'Новый заказ', 'Новую запись', 'Сообщение клиента', 'Изменение статуса', 'Результат оплаты', 'Отмену или перенос бронирования'];

function toggleInList(list: string[], value: string): void {
    const idx = list.indexOf(value);
    if (idx === -1) list.push(value);
    else list.splice(idx, 1);
}

const needsIntegrationForm = computed(() => form.existing_system !== 'none');
const canContinueStep1 = computed(() => Boolean(form.business_type_id) || form.business_type_other.trim() !== '');

function goToStep2(): void {
    if (! canContinueStep1.value) return;
    step.value = 2;
}

async function finish(): Promise<void> {
    submitting.value = true;
    try {
        const result = await apiRequest<{ ok: boolean; needs_integration_form: boolean }>('/api/onboarding/complete', {
            method: 'POST',
            body: {
                business_type_id: form.business_type_id ? Number(form.business_type_id) : null,
                business_type_other: form.business_type_id ? null : form.business_type_other.trim(),
                existing_system: form.existing_system,
            },
        });

        if (result.needs_integration_form) {
            step.value = 3;
            submitting.value = false;
            return;
        }

        router.visit('/app');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось сохранить');
        submitting.value = false;
    }
}

async function submitIntegrationRequest(): Promise<void> {
    if (! integrationForm.platform_name.trim()) return;
    submitting.value = true;
    try {
        await apiRequest('/api/onboarding/integration-request', {
            method: 'POST',
            body: integrationForm,
        });
        toast.success('Заявка на интеграцию отправлена');
        router.visit('/app');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось отправить заявку');
        submitting.value = false;
    }
}

function skipIntegrationForm(): void {
    router.visit('/app');
}
</script>

<template>
    <main class="dark relative flex min-h-screen items-center justify-center overflow-hidden bg-zinc-950 px-4 py-10 text-zinc-100">
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-primary/20 blur-3xl" />
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,0.06)_1px,transparent_0)] [background-size:28px_28px]" />
        </div>

        <Card class="relative w-full max-w-lg border-white/10 bg-zinc-900/70 shadow-2xl shadow-black/40 backdrop-blur-xl">
            <div class="px-2 py-2">
                <div class="mb-5 flex items-center gap-2">
                    <span class="grid size-9 place-items-center rounded-full bg-primary/15 text-primary"><Sparkles class="h-4 w-4" /></span>
                    <div>
                        <h1 class="text-lg font-bold text-white">Настройка рабочего пространства</h1>
                        <p class="text-xs text-zinc-400">Шаг {{ step }} из {{ needsIntegrationForm ? 3 : 2 }}</p>
                    </div>
                </div>

                <div v-if="step === 1" class="space-y-4">
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase text-zinc-400">Сфера деятельности</span>
                        <Select v-model="form.business_type_id">
                            <SelectTrigger class="w-full"><SelectValue placeholder="Выберите сферу..." /></SelectTrigger>
                            <SelectContent class="max-h-72">
                                <SelectItem v-for="bt in businessTypes" :key="bt.id" :value="String(bt.id)">{{ bt.name }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </label>
                    <label v-if="! form.business_type_id" class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase text-zinc-400">Или укажите свою сферу</span>
                        <Input v-model="form.business_type_other" placeholder="Например: ветеринарная клиника" />
                    </label>
                    <Button variant="primary" class="w-full" :disabled="! canContinueStep1" @click="goToStep2">
                        Далее <ArrowRight class="h-4 w-4" />
                    </Button>
                </div>

                <div v-else-if="step === 2" class="space-y-4">
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase text-zinc-400">
                            Использует ли ваша компания CRM, 1С, систему учёта, программу склада или другую платформу?
                        </span>
                        <Select v-model="form.existing_system">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="(label, key) in EXISTING_SYSTEM_LABELS" :key="key" :value="key">{{ label }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </label>
                    <Button variant="primary" class="w-full" :disabled="submitting" @click="finish">
                        {{ needsIntegrationForm ? 'Далее' : 'Завершить настройку' }} <ArrowRight class="h-4 w-4" />
                    </Button>
                </div>

                <div v-else class="space-y-4">
                    <p class="text-sm text-zinc-400">Расскажите о вашей системе, чтобы команда WERO оценила возможность интеграции.</p>
                    <Input v-model="integrationForm.platform_name" placeholder="Название платформы" required />
                    <Input v-model="integrationForm.platform_url" placeholder="Адрес сайта платформы" />
                    <Input v-model="integrationForm.plan_version" placeholder="Тариф или версия" />
                    <Input v-model="integrationForm.tech_contact" placeholder="Контакт технического специалиста" />
                    <Input v-model="integrationForm.api_docs_url" placeholder="Ссылка на API-документацию (если есть)" />

                    <div>
                        <span class="mb-1.5 block text-xs font-semibold uppercase text-zinc-400">Какие данные нужно получать</span>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="opt in RECEIVE_OPTIONS" :key="opt" type="button"
                                class="rounded-full border px-2.5 py-1 text-xs transition"
                                :class="integrationForm.data_to_receive.includes(opt) ? 'border-primary bg-primary/15 text-primary' : 'border-white/10 text-zinc-400 hover:border-white/20'"
                                @click="toggleInList(integrationForm.data_to_receive, opt)"
                            >{{ opt }}</button>
                        </div>
                    </div>
                    <div>
                        <span class="mb-1.5 block text-xs font-semibold uppercase text-zinc-400">Какие данные нужно отправлять</span>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="opt in SEND_OPTIONS" :key="opt" type="button"
                                class="rounded-full border px-2.5 py-1 text-xs transition"
                                :class="integrationForm.data_to_send.includes(opt) ? 'border-primary bg-primary/15 text-primary' : 'border-white/10 text-zinc-400 hover:border-white/20'"
                                @click="toggleInList(integrationForm.data_to_send, opt)"
                            >{{ opt }}</button>
                        </div>
                    </div>

                    <Input v-model="integrationForm.sync_frequency" placeholder="Как часто синхронизировать данные" />
                    <Textarea
                        v-model="integrationForm.scenario_description"
                        class="min-h-20"
                        placeholder="Например: когда клиент сообщает номер заказа, WERO должен получить из нашей CRM актуальный статус и сообщить его клиенту"
                    />
                    <Textarea v-model="integrationForm.comment" class="min-h-16" placeholder="Дополнительный комментарий" />

                    <p class="rounded-lg border border-white/10 bg-white/[0.03] p-3 text-xs leading-5 text-zinc-400">
                        Ваш запрос на интеграцию получен. Команда WERO изучит возможности платформы, наличие API, объём работ, стоимость
                        и сроки. После рассмотрения мы свяжемся с вами.
                    </p>

                    <div class="flex gap-2">
                        <Button variant="outline" class="border-white/10 text-zinc-100 hover:bg-white/5" :disabled="submitting" @click="skipIntegrationForm">
                            Пропустить
                        </Button>
                        <Button variant="primary" class="flex-1" :disabled="submitting || ! integrationForm.platform_name.trim()" @click="submitIntegrationRequest">
                            <Building2 class="h-4 w-4" />Отправить заявку
                        </Button>
                    </div>
                </div>
            </div>
        </Card>
    </main>
</template>
