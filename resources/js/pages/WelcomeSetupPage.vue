<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { apiRequest } from '@/lib/apiClient';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

const props = defineProps<{ name: string; email: string }>();

const name = ref(props.name);
const password = ref('');
const passwordConfirmation = ref('');
const processing = ref(false);
const error = ref('');

const canSubmit = computed(() => password.value.length >= 8 && password.value === passwordConfirmation.value);

async function submit(): Promise<void> {
    if (processing.value || ! canSubmit.value) return;

    processing.value = true;
    error.value = '';

    try {
        await apiRequest('/api/welcome-setup/complete', {
            method: 'POST',
            body: { name: name.value.trim() || undefined, password: password.value },
        });
        router.visit('/app');
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : 'Не удалось сохранить';
        processing.value = false;
    }
}
</script>

<template>
    <main class="dark relative flex min-h-screen items-center justify-center overflow-hidden bg-zinc-950 px-4 py-10 text-zinc-100">
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-primary/20 blur-3xl" />
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,0.06)_1px,transparent_0)] [background-size:28px_28px]" />
        </div>

        <Card class="relative w-full max-w-md border-white/10 bg-zinc-900/70 shadow-2xl shadow-black/40 backdrop-blur-xl">
            <div class="px-2 py-2">
                <div class="mb-5 flex items-center gap-2">
                    <span class="grid size-9 place-items-center rounded-full bg-primary/15 text-primary"><Sparkles class="h-4 w-4" /></span>
                    <div>
                        <h1 class="text-lg font-bold text-white">Добро пожаловать в WERO!</h1>
                        <p class="text-xs text-zinc-400">{{ props.email }}</p>
                    </div>
                </div>

                <p class="mb-4 text-sm leading-6 text-zinc-400">
                    Вы вошли по ссылке из письма-приглашения. Осталось задать пароль для входа — а имя, если нужно,
                    можно поправить прямо здесь.
                </p>

                <form class="space-y-4" @submit.prevent="submit">
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase text-zinc-400">Имя</span>
                        <Input v-model="name" class="h-11" type="text" autocomplete="name" />
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase text-zinc-400">Пароль</span>
                        <Input v-model="password" class="h-11" type="password" autocomplete="new-password" placeholder="Не менее 8 символов" required />
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase text-zinc-400">Повторите пароль</span>
                        <Input v-model="passwordConfirmation" class="h-11" type="password" autocomplete="new-password" required />
                        <span v-if="password.length > 0 && passwordConfirmation.length > 0 && password !== passwordConfirmation" class="mt-1 block text-xs text-destructive">Пароли не совпадают</span>
                    </label>
                    <span v-if="error" class="block text-xs text-destructive">{{ error }}</span>

                    <Button class="w-full" variant="primary" type="submit" :disabled="processing || ! canSubmit">{{ processing ? 'Сохраняем...' : 'Продолжить в WERO' }}</Button>
                </form>
            </div>
        </Card>
    </main>
</template>
