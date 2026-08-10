<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import { Save } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Button } from '../ui/button';
import { Card } from '../ui/card';
import { Input } from '../ui/input';
import { PhoneInput } from '../ui/phone-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select';
import { Separator } from '../ui/separator';
import { Textarea } from '../ui/textarea';
import { COMMON_TIMEZONES } from '../../lib/timezones';
import { INDUSTRY_VALUES } from '../../lib/industries';
import CompanyLogoField from './CompanyLogoField.vue';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { company, busy } = storeToRefs(store);

const form = reactive({
    name: '',
    industry: 'none',
    industry_other: '',
    phone: '',
    address: '',
    timezone: 'none',
    working_hours_start: '',
    working_hours_end: '',
    services: '',
    booking_rules: '',
    cancellation_policy: '',
});

const timezoneOptions = computed(() => (form.timezone !== 'none' && ! COMMON_TIMEZONES.includes(form.timezone)
    ? [form.timezone, ...COMMON_TIMEZONES]
    : COMMON_TIMEZONES));

watch(company, (value) => {
    if (! value) return;
    const brand = (value.brand_settings ?? {}) as Record<string, string>;
    const rawIndustry = value.industry ?? '';
    const knownIndustry = (INDUSTRY_VALUES as readonly string[]).includes(rawIndustry);
    Object.assign(form, {
        name: value.name ?? '',
        industry: ! rawIndustry ? 'none' : (knownIndustry ? rawIndustry : 'other'),
        industry_other: ! rawIndustry || knownIndustry ? '' : rawIndustry,
        phone: value.phone ?? '',
        address: value.address ?? '',
        timezone: value.timezone ?? 'none',
        working_hours_start: value.working_hours?.start ?? '',
        working_hours_end: value.working_hours?.end ?? '',
        services: brand.services ?? '',
        booking_rules: brand.booking_rules ?? '',
        cancellation_policy: brand.cancellation_policy ?? '',
    });
}, { immediate: true });

async function save(): Promise<void> {
    if (! company.value) return;

    const summary = form.working_hours_start && form.working_hours_end
        ? `${form.working_hours_start}–${form.working_hours_end}`
        : '';
    const industry = form.industry === 'none' ? '' : (form.industry === 'other' ? form.industry_other.trim() : form.industry);

    await store.updateCompany(company.value.id, {
        name: form.name.trim(),
        industry,
        phone: form.phone.trim(),
        address: form.address.trim(),
        timezone: form.timezone !== 'none' ? form.timezone : null,
        working_hours: { start: form.working_hours_start, end: form.working_hours_end, summary },
        brand_settings: {
            ...(company.value.brand_settings ?? {}),
            services: form.services.trim(),
            booking_rules: form.booking_rules.trim(),
            cancellation_policy: form.cancellation_policy.trim(),
        },
    });
}
</script>

<template>
    <Card :title="locale.t('company.title')" :subtitle="locale.t('company.subtitle')">
        <form class="grid gap-5" @submit.prevent="save">
            <CompanyLogoField v-if="company" :company-id="company.id" :logo-url="company.logo_url ?? null" />

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('company.name') }}</span>
                    <Input v-model="form.name" required />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('company.industry') }}</span>
                    <Select v-model="form.industry">
                        <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                        <SelectContent class="max-h-72">
                            <SelectItem value="none">{{ locale.t('common.unknown') }}</SelectItem>
                            <SelectItem v-for="value in INDUSTRY_VALUES" :key="value" :value="value">{{ locale.t(`company.industries.${value}`) }}</SelectItem>
                            <SelectItem value="other">{{ locale.t('company.industries.other') }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <Input v-if="form.industry === 'other'" v-model="form.industry_other" class="mt-2" :placeholder="locale.t('company.industryOtherPlaceholder')" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('company.phone') }}</span>
                    <PhoneInput v-model="form.phone" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('company.address') }}</span>
                    <Textarea v-model="form.address" class="min-h-20" />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('company.timezone') }}</span>
                    <Select v-model="form.timezone">
                        <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                        <SelectContent class="max-h-72">
                            <SelectItem value="none">{{ locale.t('common.unknown') }}</SelectItem>
                            <SelectItem v-for="zone in timezoneOptions" :key="zone" :value="zone">{{ zone }}</SelectItem>
                        </SelectContent>
                    </Select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('company.workingHours') }}</span>
                    <div class="flex items-center gap-2">
                        <Input v-model="form.working_hours_start" type="time" />
                        <span class="ui-subtle">&ndash;</span>
                        <Input v-model="form.working_hours_end" type="time" />
                    </div>
                </label>
            </div>

            <Separator />

            <label class="block">
                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('company.services') }}</span>
                <Textarea v-model="form.services" class="min-h-24" />
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('company.bookingRules') }}</span>
                <Textarea v-model="form.booking_rules" class="min-h-24" />
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('company.cancellationPolicy') }}</span>
                <Textarea v-model="form.cancellation_policy" class="min-h-20" />
            </label>

            <div class="flex justify-end">
                <Button variant="primary" type="submit" :disabled="busy">
                    <Save class="h-4 w-4" /> {{ busy ? locale.t('common.waiting') : locale.t('company.save') }}
                </Button>
            </div>
        </form>
    </Card>
</template>
