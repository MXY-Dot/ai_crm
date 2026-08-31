<script setup lang="ts">
import { Card } from '../../ui/card';
import { Skeleton } from '../../ui/skeleton';

export type FunnelStage = { stage: string; label: string; count: number; percent_of_total: number };

defineProps<{ data: FunnelStage[] | null; loading: boolean }>();
</script>

<template>
    <Card title="Воронка обращений" subtitle="От первого сообщения до успешного завершения диалога">
        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 8" :key="i" class="h-8 rounded-lg" />
        </div>
        <div v-else-if="data && data[0]?.count" class="grid gap-2 pb-2">
            <div v-for="stage in data" :key="stage.stage" class="grid gap-1">
                <div class="flex items-center justify-between text-xs">
                    <span class="ui-text">{{ stage.label }}</span>
                    <span class="ui-subtle">{{ stage.count }} · {{ stage.percent_of_total }}%</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-muted">
                    <div class="h-full rounded-full bg-primary transition-all" :style="{ width: stage.percent_of_total + '%' }" />
                </div>
            </div>
        </div>
        <p v-else class="pb-4 text-sm ui-subtle">Нет обращений за этот период.</p>
    </Card>
</template>
