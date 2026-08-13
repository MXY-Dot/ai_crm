<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { GraduationCap, KeyRound, Save, ShieldCheck, ShieldOff, Upload, User as UserIcon } from '@lucide/vue';
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
import { PhoneInput } from '../components/ui/phone-input';

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

    <Dialog v-model:open="twoFactorOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><ShieldCheck class="h-4 w-4 text-primary" />{{ locale.t('profile.twoFactor.setupTitle') }}</DialogTitle>
            </DialogHeader>

            <div v-if="twoFactorStep === 'setup'" class="space-y-4 py-2">
                <p class="text-sm ui-subtle">{{ locale.t('profile.twoFactor.setupHint') }}</p>
                <div v-if="qrSvg" class="flex justify-center rounded-lg border border-border bg-white p-3" v-html="qrSvg" />
                <div v-if="secretKey" class="rounded-lg border p-2 text-center font-mono text-sm ui-text border-border">{{ maskedSecret }}</div>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('profile.twoFactor.codeLabel') }}</span>
                    <Input v-model="confirmCode" class="text-center font-mono tracking-widest" placeholder="000000" inputmode="numeric" />
                </label>
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
