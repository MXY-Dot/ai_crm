<script setup lang="ts">
import { Bot, Check, Globe2, Inbox, Send, Sparkles, Target, Users } from '@lucide/vue';
import { Button } from '../ui/button';
import TelegramIcon from '../icons/TelegramIcon.vue';

const plans = [
    { id: 'starter', name: 'Старт', price: '$19', popular: false, features: ['Website + Telegram inbox', 'AI-черновики ответов', 'Один workspace'] },
    { id: 'growth', name: 'Рост', price: '$49', popular: true, features: ['Роли команды и права', 'Задачи и handoff оператору', 'База знаний и аналитика'] },
    { id: 'pro', name: 'Про', price: '$99', popular: false, features: ['Больше лимитов', 'Приоритетная настройка', 'Коммерческие workflow'] },
];

const features = [
    { icon: TelegramIcon, title: 'Telegram-инбокс', text: 'Webhook в CRM, AI-черновик и мгновенный ответ оператора.' },
    { icon: Globe2, title: 'Виджет сайта', text: 'Чат сайта и формы автоматически становятся CRM-диалогами.' },
    { icon: Bot, title: 'AI-черновики', text: 'AI классифицирует запрос, готовит ответ и оценивает уверенность.' },
    { icon: Users, title: 'CRM и команда', text: 'Лиды, клиенты и задачи в одном месте с ролями для команды.' },
];
</script>

<template>
    <main class="dark relative min-h-screen overflow-hidden bg-zinc-950 text-white">
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div class="landing-orb landing-orb-a absolute -left-32 -top-32 h-[28rem] w-[28rem] rounded-full bg-primary/25 blur-3xl" />
            <div class="landing-orb landing-orb-b absolute -right-24 top-1/4 h-96 w-96 rounded-full bg-emerald-400/15 blur-3xl" />
            <div class="landing-orb landing-orb-c absolute bottom-0 left-1/3 h-80 w-80 rounded-full bg-sky-400/10 blur-3xl" />
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,0.06)_1px,transparent_0)] [background-size:28px_28px]" />
        </div>

        <div class="relative">
            <header class="sticky top-0 z-20 border-b border-white/5 bg-zinc-950/70 backdrop-blur-xl">
                <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4 sm:px-6">
                    <a href="/" class="flex items-center"><img :src="'/storage/logo/logo_dark.png'" alt="WERO" class="h-8 w-auto"></a>
                    <nav class="flex items-center gap-2">
                        <a class="rounded-lg px-3 py-2 text-sm text-zinc-300 transition hover:bg-white/10" href="/login">Войти</a>
                        <a href="/register"><Button variant="primary" size="sm"><Sparkles class="h-4 w-4" />Начать бесплатно</Button></a>
                    </nav>
                </div>
            </header>

            <section class="mx-auto grid max-w-6xl gap-12 px-4 py-16 sm:px-6 lg:grid-cols-[1fr_440px] lg:items-center lg:py-24">
                <div>
                    <p class="inline-flex items-center gap-2 rounded-full border border-primary/30 bg-primary/10 px-3.5 py-1.5 text-sm font-medium text-primary">
                        <Bot class="h-4 w-4" /> AI-инбокс для продаж
                    </p>
                    <h1 class="mt-6 max-w-3xl text-4xl font-bold leading-[1.08] tracking-tight sm:text-6xl">
                        Лиды с сайта и Telegram в <span class="bg-gradient-to-r from-primary to-emerald-300 bg-clip-text text-transparent">одном инбоксе</span> оператора.
                    </h1>
                    <p class="mt-6 max-w-2xl text-lg leading-8 text-zinc-400">
                        Принимайте сообщения, создавайте CRM-записи, готовьте AI-ответы и передавайте сложные диалоги людям — всё в одном дашборде.
                    </p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <a href="/register?plan=growth"><Button variant="primary" size="lg"><Send class="h-4 w-4" />Создать workspace</Button></a>
                        <a href="/login"><Button variant="outline" size="lg">Войти</Button></a>
                    </div>
                    <div class="mt-10 flex flex-wrap items-center gap-x-8 gap-y-3 text-sm text-zinc-500">
                        <span class="flex items-center gap-1.5"><Check class="h-4 w-4 text-primary" />14 дней бесплатно</span>
                        <span class="flex items-center gap-1.5"><Check class="h-4 w-4 text-primary" />Без привязки карты</span>
                        <span class="flex items-center gap-1.5"><Check class="h-4 w-4 text-primary" />Отмена в любой момент</span>
                    </div>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-2 shadow-2xl shadow-black/40 backdrop-blur-sm">
                    <div class="rounded-xl border border-white/5 bg-zinc-900/80 p-5">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Активные каналы</p>
                            <span class="flex h-2 w-2 rounded-full bg-primary" />
                        </div>
                        <div class="mt-4 space-y-3">
                            <div class="rounded-xl border border-white/10 bg-white/[0.02] p-3.5 transition hover:border-primary/30">
                                <p class="flex items-center gap-2 font-medium"><TelegramIcon class="h-4 w-4 text-primary" />Telegram</p>
                                <p class="mt-1 text-sm text-zinc-400">Webhook в CRM, AI-черновик и ответ оператора.</p>
                            </div>
                            <div class="rounded-xl border border-white/10 bg-white/[0.02] p-3.5 transition hover:border-primary/30">
                                <p class="flex items-center gap-2 font-medium"><Globe2 class="h-4 w-4 text-primary" />Website</p>
                                <p class="mt-1 text-sm text-zinc-400">Чат сайта и формы как CRM-диалоги.</p>
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-3 gap-2 border-t border-white/5 pt-4">
                            <div class="rounded-lg bg-white/[0.02] p-2.5 text-center">
                                <p class="font-display text-lg font-bold text-white">180%</p>
                                <p class="text-[10px] uppercase text-zinc-500">AI-ответов</p>
                            </div>
                            <div class="rounded-lg bg-white/[0.02] p-2.5 text-center">
                                <p class="font-display text-lg font-bold text-white">40%</p>
                                <p class="text-[10px] uppercase text-zinc-500">Конверсия</p>
                            </div>
                            <div class="rounded-lg bg-white/[0.02] p-2.5 text-center">
                                <p class="font-display text-lg font-bold text-white">5</p>
                                <p class="text-[10px] uppercase text-zinc-500">Диалогов</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mx-auto max-w-6xl px-4 pb-4 sm:px-6">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="group rounded-2xl border border-white/10 bg-white/[0.02] p-5 backdrop-blur-sm transition hover:border-primary/30 hover:bg-white/[0.04]"
                    >
                        <span class="grid size-10 place-items-center rounded-xl bg-primary/15 text-primary transition group-hover:scale-110">
                            <component :is="feature.icon" class="h-5 w-5" />
                        </span>
                        <p class="mt-4 font-semibold text-white">{{ feature.title }}</p>
                        <p class="mt-1.5 text-sm leading-6 text-zinc-400">{{ feature.text }}</p>
                    </div>
                </div>
            </section>

            <section class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">Простые тарифы для роста</h2>
                    <p class="mt-3 text-zinc-400">Начните бесплатно, переходите на нужный план по мере роста команды.</p>
                </div>
                <div class="mt-12 grid gap-6 md:grid-cols-3">
                    <article
                        v-for="plan in plans"
                        :key="plan.id"
                        class="relative flex flex-col rounded-2xl border p-6 backdrop-blur-sm transition hover:-translate-y-1"
                        :class="plan.popular ? 'border-primary/50 bg-primary/[0.06] shadow-xl shadow-primary/10' : 'border-white/10 bg-white/[0.02]'"
                    >
                        <span
                            v-if="plan.popular"
                            class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-primary px-3 py-1 text-xs font-bold text-primary-foreground"
                        >Популярный</span>
                        <p class="text-lg font-semibold text-white">{{ plan.name }}</p>
                        <p class="mt-2 flex items-baseline gap-1">
                            <span class="text-4xl font-bold text-white">{{ plan.price }}</span>
                            <span class="text-sm text-zinc-500">/мес</span>
                        </p>
                        <ul class="mt-5 flex-1 space-y-2.5 text-sm text-zinc-300">
                            <li v-for="item in plan.features" :key="item" class="flex items-start gap-2">
                                <Check class="mt-0.5 h-4 w-4 shrink-0 text-primary" />{{ item }}
                            </li>
                        </ul>
                        <a :href="`/register?plan=${plan.id}`">
                            <Button class="mt-6 w-full" :variant="plan.popular ? 'primary' : 'outline'">Выбрать {{ plan.name }}</Button>
                        </a>
                    </article>
                </div>
            </section>

            <footer class="border-t border-white/5">
                <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-4 py-8 text-sm text-zinc-500 sm:flex-row sm:px-6">
                    <div class="flex items-center gap-2">
                        <img :src="'/storage/logo/logo_dark.png'" alt="WERO" class="h-5 w-auto opacity-70">
                        <span>© {{ new Date().getFullYear() }} WERO</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <Inbox class="h-3.5 w-3.5" /><Target class="h-3.5 w-3.5" /><Bot class="h-3.5 w-3.5" />
                        <span class="ml-1">Omnichannel AI CRM</span>
                    </div>
                </div>
            </footer>
        </div>
    </main>
</template>

<style scoped>
.landing-orb {
    animation: landing-float 16s ease-in-out infinite;
}
.landing-orb-b {
    animation-duration: 20s;
    animation-delay: -5s;
}
.landing-orb-c {
    animation-duration: 24s;
    animation-delay: -10s;
}
@keyframes landing-float {
    0%, 100% { transform: translate(0, 0) scale(1); }
    33% { transform: translate(3%, 4%) scale(1.08); }
    66% { transform: translate(-3%, -2%) scale(0.95); }
}
@media (prefers-reduced-motion: reduce) {
    .landing-orb { animation: none; }
}
</style>
