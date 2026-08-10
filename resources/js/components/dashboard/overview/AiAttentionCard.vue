<script setup lang="ts">
import { computed } from 'vue';
import { AlertTriangle, Sparkles } from '@lucide/vue';
import type { AiRun } from '../../../stores/crmDashboard';

const props = defineProps<{ aiHandoffs: AiRun[] }>();
const items = computed(() => props.aiHandoffs.slice(0, 3));
</script>

<template>
    <div class="relative flex flex-col overflow-hidden rounded-xl border p-5" style="border-color: var(--primary); background: var(--card)">
        <div class="pointer-events-none absolute right-0 top-0 h-24 w-24 rounded-bl-full" style="background: color-mix(in srgb, var(--primary) 10%, transparent)" />
        <h3 class="mb-4 flex items-center gap-2 font-display text-base font-semibold ui-text">
            <Sparkles class="h-4 w-4 text-primary" /> Требует внимания AI
        </h3>
        <ul v-if="items.length" class="space-y-3 text-sm">
            <li v-for="item in items" :key="item.id" class="flex items-start gap-2">
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                <span class="ui-subtle">
                    <span class="font-medium ui-text">{{ item.conversation?.subject ?? item.lead?.title ?? 'Диалог' }}</span>
                    — уверенность AI {{ item.confidence }}%{{ item.next_action ? `, ${item.next_action}` : '' }}
                </span>
            </li>
        </ul>
        <p v-else class="text-sm ui-subtle">Все AI-ответы уверенные, вмешательство оператора не требуется.</p>
    </div>
</template>
