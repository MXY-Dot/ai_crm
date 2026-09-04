<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { authPost } from '@/lib/authClient';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp';
import { REGEXP_ONLY_DIGITS } from 'vue-input-otp';

const props = defineProps<{ email: string }>();

const code = ref('');
const error = ref('');
const processing = ref(false);
const resendBusy = ref(false);
const resendCooldown = ref(0);
let cooldownTimer: number | undefined;

function startCooldown(seconds = 60): void {
    resendCooldown.value = seconds;
    if (cooldownTimer) window.clearInterval(cooldownTimer);
    cooldownTimer = window.setInterval(() => {
        resendCooldown.value -= 1;
        if (resendCooldown.value <= 0 && cooldownTimer) window.clearInterval(cooldownTimer);
    }, 1000);
}

onBeforeUnmount(() => { if (cooldownTimer) window.clearInterval(cooldownTimer); });

async function submit(): Promise<void> {
    if (processing.value || code.value.length !== 5) return;
    processing.value = true;
    error.value = '';

    try {
        const result = await authPost('/verify-email/code', { code: code.value.trim() });
        router.visit((result.redirect as string) ?? '/onboarding');
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : 'Неверный код';
        code.value = '';
    } finally {
        processing.value = false;
    }
}

async function resend(): Promise<void> {
    if (resendBusy.value || resendCooldown.value > 0) return;
    resendBusy.value = true;
    error.value = '';

    try {
        await authPost('/verify-email/resend');
        startCooldown();
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : 'Не удалось отправить код повторно';
    } finally {
        resendBusy.value = false;
    }
}
</script>

<template>
    <main class="dark relative flex min-h-screen items-center justify-center overflow-hidden bg-zinc-950 px-4 py-8 text-zinc-100">
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-primary/30 blur-3xl" />
            <div class="absolute -right-24 bottom-0 h-80 w-80 rounded-full bg-emerald-400/20 blur-3xl" />
        </div>
        <Card
            class="relative w-full max-w-sm border-white/10 bg-zinc-900/70 shadow-2xl shadow-black/40 backdrop-blur-xl"
            title="Подтвердите почту"
            :subtitle="`Мы отправили код на ${props.email}`"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <div class="flex flex-col items-center gap-1">
                    <InputOTP v-model="code" :maxlength="5" :pattern="REGEXP_ONLY_DIGITS" autofocus @complete="submit">
                        <InputOTPGroup>
                            <InputOTPSlot :index="0" />
                            <InputOTPSlot :index="1" />
                            <InputOTPSlot :index="2" />
                            <InputOTPSlot :index="3" />
                            <InputOTPSlot :index="4" />
                        </InputOTPGroup>
                    </InputOTP>
                    <span v-if="error" class="mt-1 block text-xs text-destructive">{{ error }}</span>
                </div>
                <Button class="w-full" variant="primary" type="submit" :disabled="processing || code.length !== 5">
                    {{ processing ? 'Проверяем...' : 'Подтвердить' }}
                </Button>
            </form>
            <button
                type="button"
                class="mt-4 block w-full text-center text-xs text-primary hover:underline disabled:pointer-events-none disabled:opacity-50"
                :disabled="resendBusy || resendCooldown > 0"
                @click="resend"
            >
                {{ resendCooldown > 0 ? `Отправить код повторно (${resendCooldown}с)` : 'Отправить код повторно' }}
            </button>
            <p class="mt-3 text-center text-xs ui-subtle">Письмо не пришло? Проверьте папку «Спам» — там же есть ссылка для подтверждения одним нажатием.</p>
        </Card>
    </main>
</template>
