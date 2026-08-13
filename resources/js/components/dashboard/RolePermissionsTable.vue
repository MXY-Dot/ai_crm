<script setup lang="ts">
import { CheckCircle2, Minus, ShieldCheck } from '@lucide/vue';
import type { TenantUser } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Card } from '../ui/card';

const locale = useLocaleStore();
const roles: TenantUser['role'][] = ['owner', 'manager', 'operator'];
const permissions = ['settings', 'team', 'crm', 'inbox', 'ai'];

function can(role: TenantUser['role'], permission: string): boolean {
    if (role === 'owner') return true;
    if (role === 'manager') return permission !== 'settings';

    return ['crm', 'inbox'].includes(permission);
}
</script>

<template>
    <Card :title="locale.t('team.permissionsTitle')">
        <template #actions>
            <ShieldCheck class="h-4 w-4 text-primary" />
        </template>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[34rem] text-left text-sm">
                <thead class="text-xs uppercase ui-subtle">
                    <tr>
                        <th class="py-2">{{ locale.t('team.role') }}</th>
                        <th v-for="item in permissions" :key="item" class="py-2 text-center">{{ locale.t(`team.permissions.${item}`) }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y border-border">
                    <tr v-for="role in roles" :key="role">
                        <td class="py-2 ui-text">{{ locale.t(`team.roles.${role}`) }}</td>
                        <td v-for="item in permissions" :key="item" class="py-2 text-center">
                            <CheckCircle2 v-if="can(role, item)" class="mx-auto h-4 w-4 text-primary" />
                            <Minus v-else class="mx-auto h-4 w-4 ui-subtle" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>
</template>
