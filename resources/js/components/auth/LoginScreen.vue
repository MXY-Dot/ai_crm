<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Bot, Inbox, LockKeyhole, Mail, Sparkles, Target, UserRound } from '@lucide/vue';
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

const highlights = [
    { icon: Inbox, label: 'Telegram-инбокс' },
    { icon: Target, label: 'Лиды с сайта' },
    { icon: Bot, label: 'AI-черновики' },
];

async function submit(): Promise<void> {
    if (processing.value) return;

    processing.value = true;
    errors.value = {};

    try {
        const result = await authPost(isRegister.value ? '/register' : '/login', isRegister.value ? {
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

        router.visit(result.two_factor ? '/two-factor-challenge' : '/app');
    } catch (caught) {
        errors.value = caught instanceof Error && 'errors' in caught ? (caught.errors as AuthErrors) : { email: 'Не удалось войти. Проверьте данные.' };
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <main class="dark relative flex min-h-screen items-center justify-center overflow-hidden bg-zinc-950 px-4 py-10 text-zinc-100">
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div class="login-orb login-orb-a absolute -left-32 -top-32 h-96 w-96 rounded-full bg-primary/30 blur-3xl" />
            <div class="login-orb login-orb-b absolute -right-24 top-1/3 h-80 w-80 rounded-full bg-emerald-400/20 blur-3xl" />
            <div class="login-orb login-orb-c absolute bottom-0 left-1/3 h-72 w-72 rounded-full bg-sky-400/10 blur-3xl" />
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,0.06)_1px,transparent_0)] [background-size:28px_28px]" />
        </div>

        <div class="relative grid w-full max-w-5xl gap-10 lg:grid-cols-[1fr_420px] lg:items-center">
            <section>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <Link href="/" class="flex items-center gap-2">
                        <img :src="'/storage/logo/logo_dark.png'" alt="WERO" class="h-8 w-auto">
                    </Link>
                    <LanguageSwitcher />
                </div>

                <span class="mt-8 inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                    <Sparkles class="h-3.5 w-3.5" /> Omnichannel AI CRM
                </span>

                <h1 class="mt-4 max-w-xl text-4xl font-bold leading-[1.1] tracking-tight text-white sm:text-5xl">
                    {{ isRegister ? 'Создайте workspace WERO' : 'С возвращением в WERO' }}
                </h1>
                <p class="mt-4 max-w-lg text-base leading-7 text-zinc-400">
                    Диалоги сайта и Telegram, CRM-связи, AI-черновики и handoff оператору в одном дашборде.
                </p>

                <div class="mt-8 grid gap-3 sm:grid-cols-3">
                    <div
                        v-for="item in highlights"
                        :key="item.label"
                        class="group flex items-center gap-2.5 rounded-xl border border-white/10 bg-white/[0.03] p-4 text-sm text-zinc-300 backdrop-blur-sm transition hover:border-primary/40 hover:bg-white/[0.06]"
                    >
                        <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-primary/15 text-primary transition group-hover:scale-110">
                            <component :is="item.icon" class="h-4 w-4" />
                        </span>
                        {{ item.label }}
                    </div>
                </div>
            </section>

            <Card class="relative border-white/10 bg-zinc-900/70 shadow-2xl shadow-black/40 backdrop-blur-xl" :title="isRegister ? 'Начать пробный период' : 'С возвращением'" :subtitle="isRegister ? `Выбранный тариф: ${plan}` : 'Войдите через email или Google.'">
                <a
                    class="mb-4 flex h-11 items-center justify-center gap-2.5 rounded-lg border text-sm font-medium ui-text transition hover:bg-muted border-border"
                    href="/auth/google"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" />
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.99.66-2.25 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.85A10.99 10.99 0 0 0 12 23z" />
                        <path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.05H2.18a11 11 0 0 0 0 9.9l3.66-2.85z" />
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.05l3.66 2.85C6.71 7.3 9.14 5.38 12 5.38z" />
                    </svg>
                    Продолжить с Google
                </a>

                <div class="mb-4 flex items-center gap-3 text-[11px] font-medium uppercase tracking-wider text-zinc-500">
                    <span class="h-px flex-1 bg-white/10" />или
                    <span class="h-px flex-1 bg-white/10" />
                </div>

                <form class="space-y-4" @submit.prevent="submit">
                    <label v-if="isRegister" class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Имя</span>
                        <div class="relative">
                            <UserRound class="pointer-events-none absolute left-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 ui-subtle" />
                            <Input v-model="form.name" class="h-11 pl-9 lg:pl-9" type="text" autocomplete="name" required />
                        </div>
                        <span v-if="errors.name" class="mt-1 block text-xs text-destructive">{{ errors.name }}</span>
                    </label>
                    <label v-if="isRegister" class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Workspace</span>
                        <div class="relative">
                            <Sparkles class="pointer-events-none absolute left-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 ui-subtle" />
                            <Input v-model="form.workspace" class="h-11 pl-9 lg:pl-9" type="text" required />
                        </div>
                        <span v-if="errors.workspace" class="mt-1 block text-xs text-destructive">{{ errors.workspace }}</span>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Email</span>
                        <div class="relative">
                            <Mail class="pointer-events-none absolute left-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 ui-subtle" />
                            <Input v-model="form.email" class="h-11 pl-9 lg:pl-9" type="email" autocomplete="email" required />
                        </div>
                        <span v-if="errors.email" class="mt-1 block text-xs text-destructive">{{ errors.email }}</span>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Пароль</span>
                        <div class="relative">
                            <LockKeyhole class="pointer-events-none absolute left-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 ui-subtle" />
                            <Input v-model="form.password" class="h-11 pl-9 lg:pl-9" type="password" autocomplete="current-password" required />
                        </div>
                        <span v-if="errors.password" class="mt-1 block text-xs text-destructive">{{ errors.password }}</span>
                    </label>
                    <label v-if="isRegister" class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Подтвердите пароль</span>
                        <div class="relative">
                            <LockKeyhole class="pointer-events-none absolute left-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 ui-subtle" />
                            <Input v-model="form.password_confirmation" class="h-11 pl-9 lg:pl-9" type="password" required />
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

<style scoped>
.login-orb {
    animation: login-float 14s ease-in-out infinite;
}
.login-orb-b {
    animation-duration: 18s;
    animation-delay: -4s;
}
.login-orb-c {
    animation-duration: 22s;
    animation-delay: -9s;
}
@keyframes login-float {
    0%, 100% { transform: translate(0, 0) scale(1); }
    33% { transform: translate(3%, 4%) scale(1.08); }
    66% { transform: translate(-3%, -2%) scale(0.95); }
}
@media (prefers-reduced-motion: reduce) {
    .login-orb { animation: none; }
}
</style>
