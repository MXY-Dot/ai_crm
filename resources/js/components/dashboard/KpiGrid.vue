<script setup lang="ts">
import { computed } from 'vue';
import { Activity, MessagesSquare, Target, Users } from '@lucide/vue';
import { shortNumber } from '../../lib/format';
import { useLocaleStore } from '../../stores/locale';

const props = defineProps<{ stats: Record<string, number> }>();
const locale = useLocaleStore();
const icons = [Users, Users, Target, Activity];
const labels: Record<string, string> = {
    Companies: 'kpi.companies',
    Customers: 'kpi.customers',
    Leads: 'kpi.leads',
    'Open tasks': 'kpi.openTasks',
};
const items = computed(() => Object.entries(props.stats).map(([label, value]) => ({
    label: locale.t(labels[label] ?? label),
    value,
})));
</script>

<template>
    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <article
            v-for="(item, index) in items"
            :key="item.label"
            class="animate-fade-up rounded-md border border-white/10 bg-zinc-900 p-4 transition-all duration-200 hover:-translate-y-0.5 hover:border-white/20 hover:shadow-lg"
            :style="{ animationDelay: `${index * 60}ms` }"
        >
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm text-zinc-400">{{ item.label }}</p>
                <component :is="icons[index] ?? MessagesSquare" class="h-4 w-4 text-emerald-300" />
            </div>
            <p class="mt-3 text-3xl font-semibold text-white">{{ shortNumber(item.value) }}</p>
        </article>
    </section>
</template>