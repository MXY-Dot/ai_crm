<script setup lang="ts">
import { computed } from 'vue';
import { CheckCircle2, Mail, Users } from '@lucide/vue';
import type { TenantUser } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Card } from '../ui/card';

const props = defineProps<{ members: TenantUser[] }>();
const locale = useLocaleStore();

const tiles = computed(() => [
    { label: locale.t('team.totalMembers'), value: props.members.length, icon: Users },
    { label: locale.t('team.activeMembers'), value: props.members.filter((member) => member.status === 'active').length, icon: CheckCircle2 },
    { label: locale.t('team.invitedMembers'), value: props.members.filter((member) => member.status === 'invited').length, icon: Mail },
]);
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-3">
        <Card v-for="tile in tiles" :key="tile.label" class="min-h-24">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm ui-subtle">{{ tile.label }}</p>
                    <p class="mt-2 font-display text-3xl font-bold ui-text">{{ tile.value }}</p>
                </div>
                <div class="grid h-10 w-10 place-items-center rounded-lg bg-muted text-primary">
                    <component :is="tile.icon" class="h-5 w-5" />
                </div>
            </div>
        </Card>
    </div>
</template>
