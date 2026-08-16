<script setup lang="ts">
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { authPost } from '@/lib/authClient';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { InputOTP, InputOTPGroup, InputOTPSeparator, InputOTPSlot } from '@/components/ui/input-otp';
import { REGEXP_ONLY_DIGITS, REGEXP_ONLY_DIGITS_AND_CHARS } from 'vue-input-otp';

// Recovery codes (see TwoFactorController) are `XXXX-XXXX` — two 4-character
// groups, letters+digits, joined by a literal dash that isn't part of the
// typed value itself. Same InputOTP widget as the authenticator code below,
// just a different pattern (letters allowed, not digits-only) and the dash
// re-inserted before submit — TwoFactorChallengeController compares against
// the stored `XXXX-XXXX` string exactly.
const usingRecoveryCode = ref(false);
const otpCode = ref('');
const recoveryCode = ref('');
const error = ref('');
const processing = ref(false);

watch(usingRecoveryCode, () => {
    error.value = '';
});

async function submit(): Promise<void> {
    const code = usingRecoveryCode.value
        ? `${recoveryCode.value.slice(0, 4)}-${recoveryCode.value.slice(4, 8)}`.toUpperCase()
        : otpCode.value.trim();
    const codeReady = usingRecoveryCode.value ? recoveryCode.value.length === 8 : code.length === 6;
    if (processing.value || ! codeReady) return;

    processing.value = true;
    error.value = '';

    try {
        await authPost('/two-factor-challenge', { code });
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
        <Card class="relative w-full max-w-sm border-white/10 bg-zinc-900/70 shadow-2xl shadow-black/40 backdrop-blur-xl" title="Двухфакторная аутентификация" :subtitle="usingRecoveryCode ? 'Введите один из резервных кодов, выданных при подключении 2FA.' : 'Введите код из приложения-аутентификатора.'">
            <form class="space-y-4" @submit.prevent="submit">
                <div v-if="! usingRecoveryCode" class="flex flex-col items-center gap-1">
                    <InputOTP v-model="otpCode" :maxlength="6" :pattern="REGEXP_ONLY_DIGITS" autofocus @complete="submit">
                        <InputOTPGroup>
                            <InputOTPSlot :index="0" />
                            <InputOTPSlot :index="1" />
                            <InputOTPSlot :index="2" />
                            <InputOTPSlot :index="3" />
                            <InputOTPSlot :index="4" />
                            <InputOTPSlot :index="5" />
                        </InputOTPGroup>
                    </InputOTP>
                    <span v-if="error" class="mt-1 block text-xs text-destructive">{{ error }}</span>
                </div>

                <div v-else class="flex flex-col items-center gap-1">
                    <InputOTP v-model="recoveryCode" :maxlength="8" :pattern="REGEXP_ONLY_DIGITS_AND_CHARS" class="uppercase" autofocus @complete="submit">
                        <InputOTPGroup>
                            <InputOTPSlot :index="0" />
                            <InputOTPSlot :index="1" />
                            <InputOTPSlot :index="2" />
                            <InputOTPSlot :index="3" />
                        </InputOTPGroup>
                        <InputOTPSeparator />
                        <InputOTPGroup>
                            <InputOTPSlot :index="4" />
                            <InputOTPSlot :index="5" />
                            <InputOTPSlot :index="6" />
                            <InputOTPSlot :index="7" />
                        </InputOTPGroup>
                    </InputOTP>
                    <span v-if="error" class="mt-1 block text-xs text-destructive">{{ error }}</span>
                </div>

                <Button class="w-full" variant="primary" type="submit" :disabled="processing">{{ processing ? 'Проверяем...' : 'Подтвердить' }}</Button>
            </form>
            <button type="button" class="mt-4 block w-full text-center text-xs text-primary hover:underline" @click="usingRecoveryCode = ! usingRecoveryCode">
                {{ usingRecoveryCode ? 'Ввести код из приложения-аутентификатора' : 'Нет доступа к приложению? Использовать резервный код' }}
            </button>
        </Card>
    </main>
</template>
