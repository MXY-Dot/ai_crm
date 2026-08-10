<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { GraduationCap, Save, Upload, User as UserIcon } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import AppLayout from '@/layouts/AppLayout.vue';
import { maybeStartPageTour, restartAllTours } from '../lib/onboarding';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';
import { Avatar, AvatarFallback, AvatarImage } from '../components/ui/avatar';
import { Button } from '../components/ui/button';
import { Card } from '../components/ui/card';
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
</script>

<template>
    <Card v-if="user" :title="locale.t('profile.title')" :subtitle="locale.t('profile.subtitle')">
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

            <div class="flex justify-end">
                <Button variant="primary" type="submit" :disabled="busy">
                    <Save class="h-4 w-4" /> {{ busy ? locale.t('common.waiting') : locale.t('profile.save') }}
                </Button>
            </div>
        </form>
    </Card>

    <Card v-if="user" class="mt-6" :title="locale.t('profile.tourTitle')" :subtitle="locale.t('profile.tourSubtitle')">
        <div class="flex justify-end">
            <Button type="button" variant="outline" @click="repeatTour">
                <GraduationCap class="h-4 w-4" /> {{ locale.t('profile.repeatTour') }}
            </Button>
        </div>
    </Card>
</template>
