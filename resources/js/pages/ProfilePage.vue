<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { GraduationCap, KeyRound, Save, Send, ShieldCheck, ShieldOff, Upload, User as UserIcon } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import AppLayout from '@/layouts/AppLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { maybeStartPageTour, restartAllTours } from '../lib/onboarding';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';
import { Avatar, AvatarFallback, AvatarImage } from '../components/ui/avatar';
import { Button } from '../components/ui/button';
import { Card } from '../components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../components/ui/dialog';
import { Input } from '../components/ui/input';
import { InputOTP, InputOTPGroup, InputOTPSlot } from '../components/ui/input-otp';
import { PhoneInput } from '../components/ui/phone-input';
import { REGEXP_ONLY_DIGITS } from 'vue-input-otp';

defineOptions({ layout: AppLayout });

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { user, busy } = storeToRefs(store);

const form = reactive({ name: '', phone: '' });
const fileInput = ref<HTMLInputElement | null>(null);

watch(user, (value) => {
    if (! value) return;
    form.name = value.name ?? '';
    form.phone = value.phone ?? '';
}, { immediate: true });

async function save(): Promise<void> {
    await store.updateProfile({ name: form.name.trim(), phone: form.phone.trim() || null });
}

async function onFileSelected(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (! file) return;

    await store.uploadAvatar(file);
    input.value = '';
}

function repeatTour(): void {
    restartAllTours(() => maybeStartPageTour('profile'));
}

type TwoFactorStep = 'idle' | 'setup' | 'recovery';

const twoFactorOpen = ref(false);
const twoFactorStep = ref<TwoFactorStep>('idle');
const twoFactorBusy = ref(false);
const qrSvg = ref('');
const secretKey = ref('');
const confirmCode = ref('');
const recoveryCodes = ref<string[]>([]);

async function openTwoFactorSetup(): Promise<void> {
    twoFactorOpen.value = true;
    twoFactorStep.value = 'setup';
    confirmCode.value = '';
    twoFactorBusy.value = true;
    try {
        const data = await apiRequest<{ secret: string; qr_svg: string }>('/api/profile/2fa/setup', { method: 'POST' });
        qrSvg.value = data.qr_svg;
        secretKey.value = data.secret;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось начать настройку 2FA');
        twoFactorOpen.value = false;
    } finally {
        twoFactorBusy.value = false;
    }
}

async function confirmTwoFactor(): Promise<void> {
    if (! confirmCode.value.trim()) return;
    twoFactorBusy.value = true;
    try {
        const data = await apiRequest<{ recovery_codes: string[] }>('/api/profile/2fa/confirm', {
            method: 'POST',
            body: { code: confirmCode.value.trim() },
        });
        recoveryCodes.value = data.recovery_codes;
        twoFactorStep.value = 'recovery';
        if (user.value) user.value.two_factor_enabled = true;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Неверный код');
    } finally {
        twoFactorBusy.value = false;
    }
}

function closeTwoFactorSetup(): void {
    twoFactorOpen.value = false;
    twoFactorStep.value = 'idle';
    toast.success(locale.t('profile.twoFactor.enabledToast'));
}

const disableOpen = ref(false);
const disablePassword = ref('');
const disableBusy = ref(false);

async function disableTwoFactor(): Promise<void> {
    if (! disablePassword.value.trim()) return;
    disableBusy.value = true;
    try {
        await apiRequest('/api/profile/2fa/disable', { method: 'POST', body: { password: disablePassword.value } });
        if (user.value) user.value.two_factor_enabled = false;
        disableOpen.value = false;
        disablePassword.value = '';
        toast.success(locale.t('profile.twoFactor.disabledToast'));
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Неверный пароль');
    } finally {
        disableBusy.value = false;
    }
}

const maskedSecret = computed(() => secretKey.value.replace(/(.{4})/g, '$1 ').trim());

// ТЗ раздел 18 — per-user Telegram linking (see TenantTelegramChannel /
// TelegramWebhookController's /link handling). "Отключён" here just means no
// telegram_chat_id on file yet -- the actual on/off switch for whether
// Telegram delivery is attempted at all lives at the company level
// (NotificationPreferencesPanel.vue), this is only "who WERO can reach".
const telegramLinked = ref(false);
const telegramBusy = ref(false);
const telegramDialogOpen = ref(false);
const telegramCode = ref('');
const telegramBotUsername = ref<string | null>(null);
const telegramCodeExpiresIn = ref(10);
let telegramPollTimer: number | undefined;

async function loadTelegramStatus(): Promise<void> {
    try {
        const data = await apiRequest<{ telegram_linked: boolean }>('/api/notification-settings/status');
        telegramLinked.value = data.telegram_linked;
    } catch {
        // non-fatal -- the card just shows "not linked" until this succeeds
    }
}

onMounted(loadTelegramStatus);
onBeforeUnmount(() => { if (telegramPollTimer) window.clearInterval(telegramPollTimer); });

async function openTelegramLink(): Promise<void> {
    telegramBusy.value = true;
    try {
        const data = await apiRequest<{ code: string; bot_username: string | null; expires_in_minutes: number }>('/api/notification-settings/telegram-link-code', { method: 'POST' });
        telegramCode.value = data.code;
        telegramBotUsername.value = data.bot_username;
        telegramCodeExpiresIn.value = data.expires_in_minutes;
        telegramDialogOpen.value = true;

        // Polls while the dialog is open so the card flips to "подключён" the moment
        // the person actually taps the Telegram link/sends the command, instead of
        // requiring a manual page refresh to notice it worked.
        telegramPollTimer = window.setInterval(async () => {
            await loadTelegramStatus();
            if (telegramLinked.value) {
                telegramDialogOpen.value = false;
                if (telegramPollTimer) window.clearInterval(telegramPollTimer);
                toast.success('Telegram подключён');
            }
        }, 3000);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось получить код для Telegram');
    } finally {
        telegramBusy.value = false;
    }
}

function closeTelegramDialog(): void {
    telegramDialogOpen.value = false;
    if (telegramPollTimer) window.clearInterval(telegramPollTimer);
}

async function unlinkTelegram(): Promise<void> {
    telegramBusy.value = true;
    try {
        await apiRequest('/api/notification-settings/telegram-unlink', { method: 'POST' });
        telegramLinked.value = false;
        toast.success('Telegram отключён');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось отключить Telegram');
    } finally {
        telegramBusy.value = false;
    }
}
</script>

<template>
    <Card v-if="user" data-tour="profile-form" :title="locale.t('profile.title')" :subtitle="locale.t('profile.subtitle')">
        <form class="grid gap-5" @submit.prevent="save">
            <div class="flex items-center gap-4">
                <Avatar class="size-16 ring-4 ring-accent">
                    <AvatarImage v-if="user.avatar_url" :src="user.avatar_url" alt="" />
                    <AvatarFallback class="text-xl font-semibold bg-primary text-primary-foreground">
                        <UserIcon v-if="! user.name" class="h-6 w-6" />
                        <template v-else>{{ user.name[0] }}</template>
                    </AvatarFallback>
                </Avatar>
                <div>
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('profile.photo') }}</span>
                    <Button type="button" size="sm" variant="outline" :disabled="busy" @click="fileInput?.click()">
                        <Upload class="h-4 w-4" />{{ locale.t('profile.uploadPhoto') }}
                    </Button>
                    <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFileSelected">
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('profile.name') }}</span>
                    <Input v-model="form.name" required />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('profile.email') }}</span>
                    <Input :model-value="user.email" disabled :title="locale.t('profile.emailLocked')" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('profile.phone') }}</span>
                    <PhoneInput v-model="form.phone" />
                </label>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <Button type="button" variant="outline" data-tour="profile-repeat-tour" @click="repeatTour">
                    <GraduationCap class="h-4 w-4" /> {{ locale.t('profile.repeatTour') }}
                </Button>
                <Button variant="primary" type="submit" :disabled="busy">
                    <Save class="h-4 w-4" /> {{ busy ? locale.t('common.waiting') : locale.t('profile.save') }}
                </Button>
            </div>
        </form>
    </Card>

    <Card v-if="user" class="mt-6" :title="locale.t('profile.twoFactor.title')" :subtitle="locale.t('profile.twoFactor.subtitle')">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="grid size-10 shrink-0 place-items-center rounded-lg" :class="user.two_factor_enabled ? 'bg-primary/10' : 'bg-muted'">
                    <ShieldCheck v-if="user.two_factor_enabled" class="h-5 w-5 text-primary" />
                    <ShieldOff v-else class="h-5 w-5 ui-subtle" />
                </div>
                <div>
                    <p class="text-sm font-medium ui-text">{{ user.two_factor_enabled ? locale.t('profile.twoFactor.enabled') : locale.t('profile.twoFactor.disabled') }}</p>
                    <p class="text-xs ui-subtle">{{ user.two_factor_enabled ? locale.t('profile.twoFactor.enabledHint') : locale.t('profile.twoFactor.disabledHint') }}</p>
                </div>
            </div>
            <Button v-if="! user.two_factor_enabled" variant="primary" size="sm" @click="openTwoFactorSetup"><ShieldCheck class="h-4 w-4" />{{ locale.t('profile.twoFactor.enable') }}</Button>
            <Button v-else variant="outline" size="sm" @click="disableOpen = true"><ShieldOff class="h-4 w-4" />{{ locale.t('profile.twoFactor.disable') }}</Button>
        </div>
    </Card>

    <Card v-if="user" class="mt-6" title="Telegram-уведомления" subtitle="Получайте уведомления WERO лично в Telegram, если это включено в настройках компании">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="grid size-10 shrink-0 place-items-center rounded-lg" :class="telegramLinked ? 'bg-primary/10' : 'bg-muted'">
                    <Send class="h-5 w-5" :class="telegramLinked ? 'text-primary' : 'ui-subtle'" />
                </div>
                <div>
                    <p class="text-sm font-medium ui-text">{{ telegramLinked ? 'Telegram подключён' : 'Telegram не подключён' }}</p>
                    <p class="text-xs ui-subtle">{{ telegramLinked ? 'WERO может присылать уведомления в ваш Telegram.' : 'Привяжите личный Telegram через бота компании.' }}</p>
                </div>
            </div>
            <Button v-if="! telegramLinked" variant="primary" size="sm" :disabled="telegramBusy" @click="openTelegramLink"><Send class="h-4 w-4" />Подключить</Button>
            <Button v-else variant="outline" size="sm" :disabled="telegramBusy" @click="unlinkTelegram">Отключить</Button>
        </div>
    </Card>

    <Dialog :open="telegramDialogOpen" @update:open="closeTelegramDialog">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><Send class="h-4 w-4 text-primary" />Подключить Telegram</DialogTitle>
            </DialogHeader>
            <div class="space-y-3 py-2 text-sm">
                <p class="ui-subtle">Откройте бота компании в Telegram — код подставится автоматически.</p>
                <a v-if="telegramBotUsername" :href="`https://t.me/${telegramBotUsername}?start=${telegramCode}`" target="_blank" rel="noopener" class="block">
                    <Button type="button" variant="primary" class="w-full"><Send class="h-4 w-4" />Открыть в Telegram</Button>
                </a>
                <p class="ui-subtle">Или напишите боту вручную: <span class="rounded bg-muted px-1.5 py-0.5 font-mono ui-text">/link {{ telegramCode }}</span></p>
                <p class="text-xs ui-subtle">Код действует {{ telegramCodeExpiresIn }} минут. Окно закроется автоматически, как только Telegram подключится.</p>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="twoFactorOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><ShieldCheck class="h-4 w-4 text-primary" />{{ locale.t('profile.twoFactor.setupTitle') }}</DialogTitle>
            </DialogHeader>

            <div v-if="twoFactorStep === 'setup'" class="space-y-4 py-2">
                <p class="text-sm ui-subtle">{{ locale.t('profile.twoFactor.setupHint') }}</p>
                <div v-if="qrSvg" class="flex justify-center rounded-lg border border-border bg-white p-3" v-html="qrSvg" />
                <div v-if="secretKey" class="rounded-lg border p-2 text-center font-mono text-sm ui-text border-border">{{ maskedSecret }}</div>
                <div class="flex flex-col items-center gap-1">
                    <span class="mb-1 block self-start text-xs font-semibold uppercase ui-subtle">{{ locale.t('profile.twoFactor.codeLabel') }}</span>
                    <InputOTP v-model="confirmCode" :maxlength="6" :pattern="REGEXP_ONLY_DIGITS" @complete="confirmTwoFactor">
                        <InputOTPGroup>
                            <InputOTPSlot :index="0" />
                            <InputOTPSlot :index="1" />
                            <InputOTPSlot :index="2" />
                            <InputOTPSlot :index="3" />
                            <InputOTPSlot :index="4" />
                            <InputOTPSlot :index="5" />
                        </InputOTPGroup>
                    </InputOTP>
                </div>
                <DialogFooter>
                    <Button variant="primary" :disabled="twoFactorBusy || ! confirmCode.trim()" @click="confirmTwoFactor">{{ locale.t('profile.twoFactor.confirmEnable') }}</Button>
                </DialogFooter>
            </div>

            <div v-else-if="twoFactorStep === 'recovery'" class="space-y-4 py-2">
                <p class="text-sm ui-subtle">{{ locale.t('profile.twoFactor.recoveryHint') }}</p>
                <div class="grid grid-cols-2 gap-2 rounded-lg border p-3 font-mono text-sm ui-text border-border">
                    <span v-for="code in recoveryCodes" :key="code"><KeyRound class="mr-1 inline h-3 w-3 ui-subtle" />{{ code }}</span>
                </div>
                <DialogFooter>
                    <Button variant="primary" @click="closeTwoFactorSetup">{{ locale.t('profile.twoFactor.done') }}</Button>
                </DialogFooter>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="disableOpen">
        <DialogContent class="sm:max-w-sm">
            <form @submit.prevent="disableTwoFactor">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><ShieldOff class="h-4 w-4 text-destructive" />{{ locale.t('profile.twoFactor.disableTitle') }}</DialogTitle>
                </DialogHeader>
                <div class="py-4">
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('profile.twoFactor.confirmPassword') }}</span>
                        <Input v-model="disablePassword" type="password" required />
                    </label>
                </div>
                <DialogFooter>
                    <Button type="submit" variant="destructive" :disabled="disableBusy || ! disablePassword.trim()">{{ locale.t('profile.twoFactor.confirmDisable') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
