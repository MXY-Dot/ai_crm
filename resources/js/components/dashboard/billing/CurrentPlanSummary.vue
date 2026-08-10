<script setup lang="ts">
import { computed } from 'vue';
import { useLocaleStore } from '../../../stores/locale';
import type { Plan } from '../../../lib/plans';

const props = defineProps<{ plan: Plan; tenantStatus: string; trialEndsAt: string | null }>();
const locale = useLocaleStore();

const trialLabel = computed(() => (props.trialEndsAt
    ? new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(props.trialEndsAt))
    : null));
</script>

<template>
    <div class="flex h-full flex-col justify-between rounded-xl border p-6" style="border-color: var(--border); background: var(--card)">
        <div>
            <h2 class="text-[11px] font-semibold uppercase tracking-wider ui-subtle">Текущий тариф</h2>
            <div class="mb-1 mt-2 flex items-baseline gap-2">
                <span class="font-display text-2xl font-bold ui-text">{{ plan.name }}</span>
                <span class="rounded px-2 py-0.5 text-xs font-semibold" style="background: color-mix(in srgb, var(--primary) 15%, transparent); color: var(--primary)">
                    {{ locale.t('status.' + tenantStatus) }}
                </span>
            </div>
            <p v-if="trialLabel" class="font-mono text-xs ui-subtle">Пробный период до {{ trialLabel }}</p>
        </div>
        <div class="mt-4 flex items-center justify-between border-t pt-4" style="border-color: var(--border)">
            <span class="text-sm ui-text">Стоимость</span>
            <span class="font-display text-lg font-semibold ui-text">{{ plan.price }}</span>
        </div>
    </div>
</template>
