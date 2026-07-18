<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { LockKeyhole, Mail, Sparkles, UserRound } from '@lucide/vue';
import { authPost, type AuthErrors } from '../../lib/authClient';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import LanguageSwitcher from '../dashboard/LanguageSwitcher.vue';
import { Button } from '../ui/button';
import { Card } from '../ui/card';

const store = useCrmDashboardStore();
const isRegister = computed(() => store.authMode === 'register');
const plan = computed(() => new URLSearchParams(window.location.search).get('plan') ?? store.plan ?? 'starter');
const processing = ref(false);
const errors = ref<AuthErrors>({});
const form = reactive({
    name: '',
    workspace: '',
    email: store.login?.email ?? '',
    password: store.login?.password ?? '',
    password_confirmation: '',
    remember: false,
});

watch(() => store.login, (login) => {
    if (!isRegister.value) {
        form.email = login?.email ?? '';
        form.password = login?.password ?? '';
    }
}, { immediate: true });

async function submit(): Promise<void> {
    if (processing.value) return;

    processing.value = true;
    errors.value = {};

    try {
        await authPost(isRegister.value ? '/register' : '/login', isRegister.value ? {
            name: form.name,
            workspace: form.workspace,
            email: form.email,
            password: form.password,
            password_confirmation: form.password_confirmation,
            plan: plan.value,
        } : {
            email: form.email,
            password: form.password,
            remember: form.remember,
        });

        window.location.assign('/app');
    } catch (caught) {
        errors.value = caught instanceof Error && 'errors' in caught ? (caught.errors as AuthErrors) : { email: 'Не удалось войти. Проверьте данные.' };
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <main class="flex min-h-screen items-center justify-center bg-zinc-950 px-4 py-8 text-zinc-100">
        <div class="grid w-full max-w-5xl gap-6 lg:grid-cols-[1fr_420px] lg:items-center">
            <section>
                <div class="flex flex-wrap items-center gap-2">
                    <Link href="/" class="inline-flex h-10 w-10 items-center justify-center rounded-md bg-emerald-300 text-zinc-950"><Sparkles class="h-5 w-5" /></Link>
                    <LanguageSwitcher />
                </div>
                <h1 class="mt-6 max-w-2xl text-3xl font-semibold text-white sm:text-5xl">{{ isRegister ? 'Создайте workspace AI CRM' : 'Вход в Gravity AI CRM' }}</h1>
                <p class="mt-4 max-w-2xl text-sm leading-6 text-zinc-400 sm:text-base">Диалоги сайта и Telegram, CRM-связи, AI-черновики и handoff оператору в одном дашборде.</p>
                <div class="mt-6 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-md border border-white/10 bg-white/5 p-4 text-sm text-zinc-300">Telegram-инбокс</div>
                    <div class="rounded-md border border-white/10 bg-white/5 p-4 text-sm text-zinc-300">Лиды с сайта</div>
                    <div class="rounded-md border border-white/10 bg-white/5 p-4 text-sm text-zinc-300">AI-черновики</div>
                </div>
            </section>

            <Card :title="isRegister ? 'Начать пробный период' : 'С возвращением'" :subtitle="isRegister ? `Выбранный тариф: ${plan}` : 'Войдите через email или Google.'">
                <a class="mb-4 flex h-11 items-center justify-center rounded-md border border-white/10 text-sm text-zinc-200 hover:bg-white/10" href="/auth/google">Продолжить с Google</a>
                <form class="space-y-4" @submit.prevent="submit">
                    <label v-if="isRegister" class="block">
                        <span class="text-sm font-medium text-zinc-300">Имя</span>
                        <span class="mt-2 flex h-11 items-center gap-2 rounded-md border border-white/10 bg-white/5 px-3 focus-within:ring-2 focus-within:ring-emerald-300">
                            <UserRound class="h-4 w-4 text-zinc-500" />
                            <input v-model="form.name" class="w-full bg-transparent text-sm text-white outline-none" type="text" autocomplete="name" required>
                        </span>
                        <span v-if="errors.name" class="mt-1 block text-xs text-red-300">{{ errors.name }}</span>
                    </label>
                    <label v-if="isRegister" class="block">
                        <span class="text-sm font-medium text-zinc-300">Workspace</span>
                        <span class="mt-2 flex h-11 items-center gap-2 rounded-md border border-white/10 bg-white/5 px-3 focus-within:ring-2 focus-within:ring-emerald-300">
                            <Sparkles class="h-4 w-4 text-zinc-500" />
                            <input v-model="form.workspace" class="w-full bg-transparent text-sm text-white outline-none" type="text" required>
                        </span>
                        <span v-if="errors.workspace" class="mt-1 block text-xs text-red-300">{{ errors.workspace }}</span>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-zinc-300">Email</span>
                        <span class="mt-2 flex h-11 items-center gap-2 rounded-md border border-white/10 bg-white/5 px-3 focus-within:ring-2 focus-within:ring-emerald-300">
                            <Mail class="h-4 w-4 text-zinc-500" />
                            <input v-model="form.email" class="w-full bg-transparent text-sm text-white outline-none" type="email" autocomplete="email" required>
                        </span>
                        <span v-if="errors.email" class="mt-1 block text-xs text-red-300">{{ errors.email }}</span>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-zinc-300">Пароль</span>
                        <span class="mt-2 flex h-11 items-center gap-2 rounded-md border border-white/10 bg-white/5 px-3 focus-within:ring-2 focus-within:ring-emerald-300">
                            <LockKeyhole class="h-4 w-4 text-zinc-500" />
                            <input v-model="form.password" class="w-full bg-transparent text-sm text-white outline-none" type="password" autocomplete="current-password" required>
                        </span>
                        <span v-if="errors.password" class="mt-1 block text-xs text-red-300">{{ errors.password }}</span>
                    </label>
                    <label v-if="isRegister" class="block">
                        <span class="text-sm font-medium text-zinc-300">Подтвердите пароль</span>
                        <span class="mt-2 flex h-11 items-center gap-2 rounded-md border border-white/10 bg-white/5 px-3 focus-within:ring-2 focus-within:ring-emerald-300">
                            <LockKeyhole class="h-4 w-4 text-zinc-500" />
                            <input v-model="form.password_confirmation" class="w-full bg-transparent text-sm text-white outline-none" type="password" required>
                        </span>
                    </label>
                    <label v-if="!isRegister" class="flex items-center gap-2 text-sm text-zinc-400">
                        <input v-model="form.remember" class="h-4 w-4 rounded border-white/10 bg-white/5" type="checkbox">
                        Запомнить меня
                    </label>
                    <Button class="w-full" variant="primary" type="button" :disabled="processing" @click="submit">{{ processing ? 'Подождите...' : (isRegister ? 'Создать workspace' : 'Войти') }}</Button>
                </form>
                <p class="mt-4 text-center text-sm text-zinc-400">
                    <Link class="text-emerald-200 hover:underline" :href="isRegister ? '/login' : `/register?plan=${plan}`">{{ isRegister ? 'У меня уже есть аккаунт' : 'Создать аккаунт' }}</Link>
                </p>
            </Card>
        </div>
    </main>
</template>