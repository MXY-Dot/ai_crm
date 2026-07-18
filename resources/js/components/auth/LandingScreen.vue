<script setup lang="ts">
import { Bot, Mail, MessageCircle, Send, Sparkles } from '@lucide/vue';
import { Button } from '../ui/button';

const plans = [
    { id: 'starter', name: 'Старт', price: '$19', text: 'Website + Telegram inbox, AI-черновики и один workspace.' },
    { id: 'growth', name: 'Рост', price: '$49', text: 'Роли команды, задачи handoff, база знаний и аналитика.' },
    { id: 'pro', name: 'Про', price: '$99', text: 'Больше лимитов, приоритетная настройка и коммерческие workflow позже.' },
];
</script>

<template>
    <main class="min-h-screen bg-zinc-950 text-white">
        <header class="mx-auto flex max-w-6xl items-center justify-between px-4 py-5 sm:px-6">
            <a href="/" class="flex items-center gap-2 font-semibold"><span class="grid h-9 w-9 place-items-center rounded-md bg-emerald-300 text-zinc-950"><Sparkles class="h-5 w-5" /></span>Gravity AI CRM</a>
            <nav class="flex items-center gap-2">
                <a class="rounded-md px-3 py-2 text-sm text-zinc-300 hover:bg-white/10" href="/login">Войти</a>
                <a class="rounded-md bg-emerald-300 px-3 py-2 text-sm font-medium text-zinc-950" href="/register">Начать</a>
            </nav>
        </header>

        <section class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 lg:grid-cols-[1fr_420px] lg:items-center lg:py-20">
            <div>
                <p class="inline-flex items-center gap-2 rounded-md border border-emerald-300/30 bg-emerald-300/10 px-3 py-1 text-sm text-emerald-100"><Bot class="h-4 w-4" /> AI-инбокс для продаж</p>
                <h1 class="mt-5 max-w-3xl text-4xl font-semibold tracking-normal sm:text-6xl">Лиды с сайта и Telegram в одном инбоксе оператора.</h1>
                <p class="mt-5 max-w-2xl text-base leading-7 text-zinc-400">Принимайте сообщения, создавайте CRM-записи, готовьте AI-ответы и передавайте сложные диалоги людям.</p>
                <div class="mt-7 flex flex-wrap gap-3">
                    <a href="/register?plan=growth"><Button variant="primary"><Send class="h-4 w-4" />Создать workspace</Button></a>
                    <a href="/login"><Button variant="ghost"><Mail class="h-4 w-4" />Войти</Button></a>
                </div>
            </div>

            <div class="rounded-md border border-white/10 bg-white/[0.04] p-4">
                <div class="rounded-md bg-zinc-900 p-4">
                    <p class="text-sm text-zinc-400">Активные каналы</p>
                    <div class="mt-4 space-y-3">
                        <div class="rounded-md border border-white/10 p-3"><p class="flex items-center gap-2 font-medium"><MessageCircle class="h-4 w-4 text-emerald-300" />Telegram</p><p class="mt-1 text-sm text-zinc-400">Webhook в CRM, AI-черновик и ответ оператора.</p></div>
                        <div class="rounded-md border border-white/10 p-3"><p class="flex items-center gap-2 font-medium"><Mail class="h-4 w-4 text-emerald-300" />Website</p><p class="mt-1 text-sm text-zinc-400">Чат сайта и формы как CRM-диалоги.</p></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-6xl px-4 pb-16 sm:px-6">
            <div class="grid gap-4 md:grid-cols-3">
                <article v-for="plan in plans" :key="plan.id" class="rounded-md border border-white/10 bg-white/[0.03] p-5">
                    <p class="text-lg font-semibold">{{ plan.name }}</p>
                    <p class="mt-2 text-3xl font-semibold">{{ plan.price }}<span class="text-sm text-zinc-500">/мес</span></p>
                    <p class="mt-3 min-h-16 text-sm leading-6 text-zinc-400">{{ plan.text }}</p>
                    <a class="mt-5 inline-flex w-full justify-center rounded-md border border-white/10 px-3 py-2 text-sm hover:bg-white/10" :href="`/register?plan=${plan.id}`">Выбрать {{ plan.name }}</a>
                </article>
            </div>
        </section>
    </main>
</template>