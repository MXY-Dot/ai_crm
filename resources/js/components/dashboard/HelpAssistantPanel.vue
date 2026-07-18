<script setup lang="ts">
import { computed, ref } from 'vue';
import { Bot, Send, Sparkles } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Button } from '../ui/button';
import { Card } from '../ui/card';
import { Input } from '../ui/input';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { company } = storeToRefs(store);
const question = ref('');
const answer = ref('');

const facts = computed(() => {
    const brand = company.value?.brand_settings ?? {};
    return {
        address: company.value?.address || locale.t('assistant.noData'),
        booking: brand.booking_rules || locale.t('assistant.noData'),
        cancellation: brand.cancellation_policy || locale.t('assistant.noData'),
        hours: company.value?.working_hours?.summary || locale.t('assistant.noData'),
        phone: company.value?.phone || locale.t('assistant.noData'),
        services: brand.services || locale.t('assistant.noData'),
    };
});

const faqs = computed(() => [
    { label: locale.t('assistant.faq.prices'), value: facts.value.services },
    { label: locale.t('assistant.faq.hours'), value: facts.value.hours },
    { label: locale.t('assistant.faq.booking'), value: facts.value.booking },
    { label: locale.t('assistant.faq.cancel'), value: facts.value.cancellation },
    { label: locale.t('assistant.faq.contact'), value: `${facts.value.phone}\n${facts.value.address}` },
]);

function ask(text = question.value): void {
    const normalized = text.toLowerCase();
    const hit = faqs.value.find((item) => normalized.includes(item.label.toLowerCase().split(' ')[0]))
        ?? (/(price|service|cost|\\u0446\\u0435\\u043d\\u0430|\\u0441\\u0442\\u043e\\u0438\\u043c|\\u0443\\u0441\\u043b\\u0443\\u0433|\\u043f\\u0440\\u0430\\u0439\\u0441)/i.test(text) ? faqs.value[0] : null)
        ?? (/(hour|time|open|\\u0440\\u0430\\u0431\\u043e\\u0442|\\u0432\\u0440\\u0435\\u043c\\u044f|\\u0433\\u0440\\u0430\\u0444\\u0438\\u043a)/i.test(text) ? faqs.value[1] : null)
        ?? (/(book|appoint|\\u0437\\u0430\\u043f\\u0438\\u0441|\\u0431\\u0440\\u043e\\u043d)/i.test(text) ? faqs.value[2] : null)
        ?? (/(cancel|\\u043e\\u0442\\u043c\\u0435\\u043d)/i.test(text) ? faqs.value[3] : null)
        ?? (/(phone|address|contact|тел|адрес|контакт)/i.test(text) ? faqs.value[4] : null);

    answer.value = hit
        ? `${hit.label}\n${hit.value}`
        : locale.t('assistant.fallback');
    question.value = text;
}
</script>

<template>
    <Card :title="locale.t('assistant.title')" :subtitle="locale.t('assistant.subtitle')">
        <div class="grid gap-4 lg:grid-cols-[0.9fr_1.1fr]">
            <div class="flex flex-col gap-2">
                <button v-for="item in faqs" :key="item.label" class="flex w-full items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-left text-sm text-foreground transition hover:bg-muted" type="button" @click="ask(item.label)">
                    <Sparkles class="text-primary" />
                    {{ item.label }}
                </button>
            </div>

            <div class="rounded-xl border border-border bg-background p-4">
                <div class="flex items-center gap-2 text-sm font-medium text-foreground">
                    <Bot class="text-primary" />
                    {{ locale.t('assistant.askTitle') }}
                </div>
                <div class="mt-3 flex gap-2">
                    <Input v-model="question" :placeholder="locale.t('assistant.placeholder')" @keydown.enter="ask()" />
                    <Button size="icon" type="button" @click="ask()"><Send /></Button>
                </div>
                <p class="mt-4 whitespace-pre-line rounded-lg bg-muted/50 p-3 text-sm leading-6 text-muted-foreground">
                    {{ answer || locale.t('assistant.empty') }}
                </p>
            </div>
        </div>
    </Card>
</template>