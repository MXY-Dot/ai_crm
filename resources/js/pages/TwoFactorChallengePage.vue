<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { authPost } from '@/lib/authClient';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

const code = ref('');
const error = ref('');
const processing = ref(false);

async function submit(): Promise<void> {
    if (processing.value || ! code.value.trim()) return;

    processing.value = true;
    error.value = '';

    try {
        await authPost('/two-factor-challenge', { code: code.value.trim() });
        router.visit('/app');
    } catch (caught) {
        error.value = caught instanceof Error && 'errors' in caught
            ? Object.values((caught as Error & { errors: Record<string, string> }).errors)[0] ?? 'Неверный код'
            : 'Неверный код';
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <main class="dark relative flex min-h-screen items-center justify-center overflow-hidden bg-zinc-950 px-4 py-8 text-zinc-100">
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-primary/30 blur-3xl" />
            <div class="absolute -right-24 bottom-0 h-80 w-80 rounded-full bg-emerald-400/20 blur-3xl" />
        </div>
        <Card class="relative w-full max-w-sm border-white/10 bg-zinc-900/70 shadow-2xl shadow-black/40 backdrop-blur-xl" title="Двухфакторная аутентификация" subtitle="Введите код из приложения-аутентификатора или резервный код.">
            <form class="space-y-4" @submit.prevent="submit">
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Код подтверждения</span>
                    <div class="relative">
                        <ShieldCheck class="pointer-events-none absolute left-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 ui-subtle" />
                        <Input
                            v-model="code"
                            class="h-11 pl-9 lg:pl-9 text-center font-mono tracking-widest"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            placeholder="000000"
                            autofocus
                            required
                        />
                    </div>
                    <span v-if="error" class="mt-1 block text-xs text-destructive">{{ error }}</span>
                </label>
                <Button class="w-full" variant="primary" type="submit" :disabled="processing">{{ processing ? 'Проверяем...' : 'Подтвердить' }}</Button>
            </form>
            <p class="mt-4 text-center text-xs ui-subtle">Нет доступа к приложению? Используйте один из резервных кодов, выданных при подключении 2FA.</p>
        </Card>
    </main>
</template>
