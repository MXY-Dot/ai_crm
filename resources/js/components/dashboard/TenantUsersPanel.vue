<script setup lang="ts">
import { computed, reactive } from 'vue';
import { CheckCircle2, Minus, ShieldCheck, UserPlus, Users } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore, type TenantUser } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Alert, AlertDescription } from '../ui/alert';
import { Badge } from '../ui/badge';
import { Button } from '../ui/button';
import { Card } from '../ui/card';
import { Input } from '../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { tenantUsers, busy, error, user } = storeToRefs(store);
const form = reactive({ name: '', email: '', phone: '', role: 'operator' as TenantUser['role'], password: '' });
const roles: TenantUser['role'][] = ['super_admin', 'owner', 'manager', 'operator'];
const permissions = ['settings', 'team', 'crm', 'inbox', 'ai'];
const selfId = computed(() => user.value?.id ?? null);

async function createUser(): Promise<void> {
    await store.createTenantUser({ name: form.name, email: form.email, phone: form.phone || null, role: form.role, status: 'invited', password: form.password || undefined });
    Object.assign(form, { name: '', email: '', phone: '', role: 'operator', password: '' });
}

async function updateRole(user: TenantUser, role: TenantUser['role']): Promise<void> {
    if (user.role !== role) await store.updateTenantUser(user.id, { role });
}

async function toggleStatus(member: TenantUser): Promise<void> {
    if (member.id === selfId.value && ['super_admin', 'owner'].includes(member.role)) return;
    await store.updateTenantUser(member.id, { status: member.status === 'disabled' ? 'active' : 'disabled' });
}

function tone(status: string): 'green' | 'blue' | 'amber' | 'neutral' {
    if (status === 'active') return 'green';
    if (status === 'invited') return 'blue';
    if (status === 'disabled') return 'amber';
    return 'neutral';
}

function can(role: TenantUser['role'], permission: string): boolean {
    if (role === 'super_admin' || role === 'owner') return true;
    if (role === 'manager') return permission !== 'settings';
    return ['crm', 'inbox'].includes(permission);
}
</script>

<template>
    <Card :title="locale.t('team.title')" :subtitle="locale.t('team.subtitle')">
        <form class="mb-5 grid gap-3 rounded-xl border p-4" style="border-color: var(--border); background: var(--card)" @submit.prevent="createUser">
            <p class="flex items-center gap-2 text-sm font-medium ui-text"><UserPlus class="h-4 w-4 text-primary" /> {{ locale.t('team.inviteUser') }}</p>
            <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
            <div class="grid gap-3 sm:grid-cols-2">
                <Input v-model="form.name" :placeholder="locale.t('team.name')" required />
                <Input v-model="form.email" type="email" :placeholder="locale.t('team.email')" required />
                <Input v-model="form.phone" :placeholder="locale.t('team.phone')" />
                <Input v-model="form.password" type="password" :placeholder="locale.t('team.password')" />
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Select v-model="form.role">
                    <SelectTrigger class="w-48"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="role in roles" :key="role" :value="role">{{ locale.t(`team.roles.${role}`) }}</SelectItem>
                    </SelectContent>
                </Select>
                <Button variant="primary" type="submit" :disabled="busy"><UserPlus class="h-4 w-4" /> {{ locale.t('team.create') }}</Button>
            </div>
        </form>

        <div class="mb-5 rounded-xl border p-4" style="border-color: var(--border); background: var(--card)">
            <p class="flex items-center gap-2 font-medium ui-text"><ShieldCheck class="h-4 w-4 text-primary" />{{ locale.t('team.permissionsTitle') }}</p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[34rem] text-left text-sm">
                    <thead class="text-xs uppercase ui-subtle"><tr><th class="py-2">{{ locale.t('team.role') }}</th><th v-for="item in permissions" :key="item" class="py-2 text-center">{{ locale.t(`team.permissions.${item}`) }}</th></tr></thead>
                    <tbody class="divide-y" style="border-color: var(--border)">
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
        </div>

        <div class="space-y-3">
            <article v-for="member in tenantUsers" :key="member.id" class="rounded-xl border p-4" style="border-color: var(--border); background: var(--card)">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="flex items-center gap-2 font-medium ui-text"><Users class="h-4 w-4 text-primary" />{{ member.name }}</p>
                        <p class="mt-1 text-xs ui-subtle">{{ member.email }} - {{ member.phone ?? locale.t('common.unknown') }}</p>
                    </div>
                    <Badge :tone="tone(member.status)">{{ member.status }}</Badge>
                </div>
                <Alert v-if="member.id === selfId && ['super_admin', 'owner'].includes(member.role)" class="mt-3 border-amber-300/30 bg-amber-300/10"><AlertDescription class="text-amber-100">{{ locale.t('team.ownerWarning') }}</AlertDescription></Alert>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <Select :model-value="member.role" @update:model-value="(value) => updateRole(member, value as TenantUser['role'])">
                        <SelectTrigger class="h-9 w-40"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="role in roles" :key="role" :value="role">{{ locale.t(`team.roles.${role}`) }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button size="sm" variant="secondary" :disabled="busy || (member.id === selfId && ['super_admin', 'owner'].includes(member.role))" @click="toggleStatus(member)">{{ member.status === 'disabled' ? locale.t('team.activate') : locale.t('team.disable') }}</Button>
                </div>
            </article>
        </div>
    </Card>
</template>
