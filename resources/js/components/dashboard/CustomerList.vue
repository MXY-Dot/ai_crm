<script setup lang="ts">
import { Mail, Phone } from '@lucide/vue';
import type { Customer } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Badge } from '../ui/badge';
import { Card } from '../ui/card';

defineProps<{ customers: Customer[]; selectedId: number | null }>();
defineEmits<{ select: [id: number] }>();
const locale = useLocaleStore();
</script>

<template>
    <Card :title="locale.t('crm.customers')" :subtitle="locale.t('crm.customersSubtitle')">
        <div class="divide-y divide-white/10">
            <button
                v-for="customer in customers"
                :key="customer.id"
                class="block w-full py-3 text-left first:pt-0 last:pb-0"
                @click="$emit('select', customer.id)"
            >
                <div :class="['rounded-md border p-3 transition', selectedId === customer.id ? 'border-emerald-300/40 bg-emerald-300/10' : 'border-transparent hover:bg-white/[0.04]']">
                    <div class="flex items-start justify-between gap-3">
                        <p class="font-medium text-white">{{ customer.name }}</p>
                        <Badge>{{ customer.source ?? locale.t('common.manual') }}</Badge>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-3 text-sm text-zinc-400">
                        <span v-if="customer.phone" class="inline-flex items-center gap-1"><Phone class="h-3 w-3" />{{ customer.phone }}</span>
                        <span v-if="customer.email" class="inline-flex items-center gap-1"><Mail class="h-3 w-3" />{{ customer.email }}</span>
                    </div>
                </div>
            </button>
        </div>
    </Card>
</template>