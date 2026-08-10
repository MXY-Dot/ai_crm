<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { LockKeyhole, Mail, Sparkles, UserRound } from '@lucide/vue';
import { authPost, type AuthErrors } from '../../lib/authClient';
import LanguageSwitcher from '../dashboard/LanguageSwitcher.vue';
import { Button } from '../ui/button';
import { Card } from '../ui/card';
import { Input } from '../ui/input';

const props = withDefaults(defineProps<{
    mode: 'login' | 'register';
    login?: { email: string; password: string };
    plan?: string;
}>(), {
    login: () => ({ email: '', password: '' }),
    plan: 'starter',
});
const isRegister = computed(() => props.mode === 'register');
const plan = computed(() => props.plan);
const processing = ref(false);
const errors = ref<AuthErrors>({});
const form = reactive({
    name: '',
    workspace: '',
    email: props.login.email,
    password: props.login.password,
    password_confirmation: '',
    remember: false,
});

watch(() => props.login, (login) => {
    if (!isRegister.value) {
        form.email = login.email;
        form.password = login.password;
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

        router.visit('/app');
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
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Link href="/"><img :src="'/storage/logo/logo_dark.png'" alt="WERO" class="h-8 w-auto"></Link>
                    <LanguageSwitcher />
                </div>
                <h1 class="mt-6 max-w-2xl text-3xl font-semibold text-white sm:text-5xl">{{ isRegister ? 'Создайте workspace WERO' : 'Вход в WERO' }}</h1>
                <p class="mt-4 max-w-2xl text-sm leading-6 text-zinc-400 sm:text-base">Диалоги сайта и Telegram, CRM-связи, AI-черновики и handoff оператору в одном дашборде.</p>
                <div class="mt-6 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-md border border-white/10 bg-white/5 p-4 text-sm text-zinc-300">Telegram-инбокс</div>
                    <div class="rounded-md border border-white/10 bg-white/5 p-4 text-sm text-zinc-300">Лиды с сайта</div>
                    <div class="rounded-md border border-white/10 bg-white/5 p-4 text-sm text-zinc-300">AI-черновики</div>
                </div>
            </section>

            <Card :title="isRegister ? 'Начать пробный период' : 'С возвращением'" :subtitle="isRegister ? `Выбранный тариф: ${plan}` : 'Войдите через email или Google.'">
                <a class="mb-4 flex h-11 items-center justify-center rounded-md border text-sm font-medium ui-text transition hover:bg-muted border-border" href="/auth/google">Продолжить с Google</a>
                <form class="space-y-4" @submit.prevent="submit">
                    <label v-if="isRegister" class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Имя</span>
                        <div class="relative">
                            <UserRound class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 ui-subtle" />
                            <Input v-model="form.name" class="h-11 pl-9" type="text" autocomplete="name" required />
                        </div>
                        <span v-if="errors.name" class="mt-1 block text-xs text-destructive">{{ errors.name }}</span>
                    </label>
                    <label v-if="isRegister" class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Workspace</span>
                        <div class="relative">
                            <Sparkles class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 ui-subtle" />
                            <Input v-model="form.workspace" class="h-11 pl-9" type="text" required />
                        </div>
                        <span v-if="errors.workspace" class="mt-1 block text-xs text-destructive">{{ errors.workspace }}</span>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Email</span>
                        <div class="relative">
                            <Mail class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 ui-subtle" />
                            <Input v-model="form.email" class="h-11 pl-9" type="email" autocomplete="email" required />
                        </div>
                        <span v-if="errors.email" class="mt-1 block text-xs text-destructive">{{ errors.email }}</span>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Пароль</span>
                        <div class="relative">
                            <LockKeyhole class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 ui-subtle" />
                            <Input v-model="form.password" class="h-11 pl-9" type="password" autocomplete="current-password" required />
                        </div>
                        <span v-if="errors.password" class="mt-1 block text-xs text-destructive">{{ errors.password }}</span>
                    </label>
                    <label v-if="isRegister" class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Подтвердите пароль</span>
                        <div class="relative">
                            <LockKeyhole class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 ui-subtle" />
                            <Input v-model="form.password_confirmation" class="h-11 pl-9" type="password" required />
                        </div>
                    </label>
                    <label v-if="!isRegister" class="flex items-center gap-2 text-sm ui-subtle">
                        <input v-model="form.remember" class="h-4 w-4 rounded border accent-primary border-border" type="checkbox">
                        Запомнить меня
                    </label>
                    <Button class="w-full" variant="primary" type="submit" :disabled="processing">{{ processing ? 'Подождите...' : (isRegister ? 'Создать workspace' : 'Войти') }}</Button>
                </form>
                <p class="mt-4 text-center text-sm ui-subtle">
                    <Link class="font-medium text-primary hover:underline" :href="isRegister ? '/login' : `/register?plan=${plan}`">{{ isRegister ? 'У меня уже есть аккаунт' : 'Создать аккаунт' }}</Link>
                </p>
            </Card>
        </div>
    </main>
</template>