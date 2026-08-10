<script setup lang="ts">
import { Check } from '@lucide/vue';
import type { Plan } from '../../../lib/plans';
import { Button } from '../../ui/button';

defineProps<{ plan: Plan; current: boolean; busy: boolean }>();
defineEmits<{ select: [] }>();
</script>

<template>
    <article
        class="relative flex h-full flex-col rounded-xl border p-6"
        :style="{ borderColor: current ? 'var(--primary)' : 'var(--border)', borderWidth: current ? '2px' : '1px', background: 'var(--card)' }"
    >
        <span v-if="current" class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full px-3 py-1 text-[10px] font-bold uppercase tracking-wide" style="background: var(--primary); color: var(--primary-foreground)">
            Текущий
        </span>
        <h3 class="font-display text-lg font-semibold ui-text">{{ plan.name }}</h3>
        <p class="mt-2 font-display text-2xl font-bold ui-text">{{ plan.price }}</p>
        <ul class="mt-4 flex-1 space-y-2">
            <li v-for="feature in plan.features" :key="feature" class="flex items-center gap-2 text-sm ui-subtle">
                <Check class="h-4 w-4 shrink-0 text-primary" />{{ feature }}
            </li>
        </ul>
        <Button
            class="mt-6 w-full"
            :variant="current ? 'secondary' : 'primary'"
            :disabled="current || busy"
            @click="$emit('select')"
        >
            {{ current ? 'Текущий тариф' : `Перейти на ${plan.name}` }}
        </Button>
    </article>
</template>
