<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Building2, Save } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Card } from '../ui/card';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { company, busy } = storeToRefs(store);
const form = reactive({
    name: '',
    industry: '',
    phone: '',
    address: '',
    working_hours: '',
    services: '',
    booking_rules: '',
    cancellation_policy: '',
});

watch(company, (value) => {
    if (! value) return;
    const brand = value.brand_settings ?? {};
    Object.assign(form, {
        name: value.name ?? '',
        industry: value.industry ?? '',
        phone: value.phone ?? '',
        address: value.address ?? '',
        working_hours: value.working_hours?.summary ?? '',
        services: brand.services ?? '',
        booking_rules: brand.booking_rules ?? '',
        cancellation_policy: brand.cancellation_policy ?? '',
    });
}, { immediate: true });

async function save(): Promise<void> {
    if (! company.value) return;
    await store.updateCompany(company.value.id, {
        name: form.name.trim(),
        industry: form.industry.trim(),
        phone: form.phone.trim(),
        address: form.address.trim(),
        working_hours: { summary: form.working_hours.trim() },
        brand_settings: {
            services: form.services.trim(),
            booking_rules: form.booking_rules.trim(),
            cancellation_policy: form.cancellation_policy.trim(),
        },
    });
}
</script>

<template>
    <Card :title="locale.t('company.title')" :subtitle="locale.t('company.subtitle')">
        <form class="grid gap-4" @submit.prevent="save">
            <div class="grid gap-3 sm:grid-cols-2">
                <input v-model="form.name" class="h-10 rounded-md border border-white/10 bg-zinc-950 px-3 text-sm text-white outline-none focus:border-emerald-300" :placeholder="locale.t('company.name')" required>
                <input v-model="form.industry" class="h-10 rounded-md border border-white/10 bg-zinc-950 px-3 text-sm text-white outline-none focus:border-emerald-300" :placeholder="locale.t('company.industry')">
                <input v-model="form.phone" class="h-10 rounded-md border border-white/10 bg-zinc-950 px-3 text-sm text-white outline-none focus:border-emerald-300" :placeholder="locale.t('company.phone')">
                <input v-model="form.address" class="h-10 rounded-md border border-white/10 bg-zinc-950 px-3 text-sm text-white outline-none focus:border-emerald-300" :placeholder="locale.t('company.address')">
            </div>

            <textarea v-model="form.working_hours" class="min-h-20 rounded-md border border-white/10 bg-zinc-950 px-3 py-2 text-sm text-white outline-none focus:border-emerald-300" :placeholder="locale.t('company.workingHours')" />
            <textarea v-model="form.services" class="min-h-24 rounded-md border border-white/10 bg-zinc-950 px-3 py-2 text-sm text-white outline-none focus:border-emerald-300" :placeholder="locale.t('company.services')" />
            <textarea v-model="form.booking_rules" class="min-h-24 rounded-md border border-white/10 bg-zinc-950 px-3 py-2 text-sm text-white outline-none focus:border-emerald-300" :placeholder="locale.t('company.bookingRules')" />
            <textarea v-model="form.cancellation_policy" class="min-h-20 rounded-md border border-white/10 bg-zinc-950 px-3 py-2 text-sm text-white outline-none focus:border-emerald-300" :placeholder="locale.t('company.cancellationPolicy')" />

            <button class="inline-flex h-10 w-fit items-center justify-center gap-2 rounded-md bg-emerald-300 px-4 text-sm font-semibold text-zinc-950 transition hover:bg-emerald-200 disabled:opacity-60" type="submit" :disabled="busy">
                <Save class="h-4 w-4" /> {{ busy ? locale.t('common.waiting') : locale.t('company.save') }}
            </button>
        </form>
    </Card>
</template>
