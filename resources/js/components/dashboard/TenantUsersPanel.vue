<script setup lang="ts">
import { computed, reactive } from 'vue';
import { ShieldCheck, UserPlus, Users } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore, type TenantUser } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Badge } from '../ui/badge';
import { Button } from '../ui/button';
import { Card } from '../ui/card';

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
        <form class="mb-5 grid gap-3 rounded-md border border-white/10 bg-white/[0.03] p-4" @submit.prevent="createUser">
            <p class="flex items-center gap-2 text-sm font-medium text-white"><UserPlus class="h-4 w-4 text-emerald-300" /> {{ locale.t('team.inviteUser') }}</p>
            <p v-if="error" class="rounded-md border border-red-300/30 bg-red-300/10 p-3 text-sm text-red-100">{{ error }}</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <input v-model="form.name" class="h-10 rounded-md border border-white/10 bg-zinc-950 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300" :placeholder="locale.t('team.name')" required>
                <input v-model="form.email" type="email" class="h-10 rounded-md border border-white/10 bg-zinc-950 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300" :placeholder="locale.t('team.email')" required>
                <input v-model="form.phone" class="h-10 rounded-md border border-white/10 bg-zinc-950 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300" :placeholder="locale.t('team.phone')">
                <input v-model="form.password" type="password" class="h-10 rounded-md border border-white/10 bg-zinc-950 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300" :placeholder="locale.t('team.password')">
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <select v-model="form.role" class="h-10 rounded-md border border-white/10 bg-zinc-950 px-3 text-sm text-white outline-none focus:ring-2 focus:ring-emerald-300">
                    <option v-for="role in roles" :key="role" :value="role">{{ locale.t(`team.roles.${role}`) }}</option>
                </select>
                <Button variant="primary" type="submit" :disabled="busy"><UserPlus class="h-4 w-4" /> {{ locale.t('team.create') }}</Button>
            </div>
        </form>

        <div class="mb-5 rounded-md border border-white/10 bg-white/[0.03] p-4">
            <p class="flex items-center gap-2 font-medium text-white"><ShieldCheck class="h-4 w-4 text-emerald-300" />{{ locale.t('team.permissionsTitle') }}</p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[34rem] text-left text-sm">
                    <thead class="text-xs uppercase text-zinc-500"><tr><th class="py-2">{{ locale.t('team.role') }}</th><th v-for="item in permissions" :key="item" class="py-2">{{ locale.t(`team.permissions.${item}`) }}</th></tr></thead>
                    <tbody class="divide-y divide-white/10">
                        <tr v-for="role in roles" :key="role"><td class="py-2 text-white">{{ locale.t(`team.roles.${role}`) }}</td><td v-for="item in permissions" :key="item" class="py-2">{{ can(role, item) ? 'yes' : '-' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-3">
            <article v-for="member in tenantUsers" :key="member.id" class="rounded-md border border-white/10 bg-white/[0.03] p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="flex items-center gap-2 font-medium text-white"><Users class="h-4 w-4 text-emerald-300" />{{ member.name }}</p>
                        <p class="mt-1 text-xs text-zinc-500">{{ member.email }} - {{ member.phone ?? locale.t('common.unknown') }}</p>
                    </div>
                    <Badge :tone="tone(member.status)">{{ member.status }}</Badge>
                </div>
                <p v-if="member.id === selfId && ['super_admin', 'owner'].includes(member.role)" class="mt-3 rounded-md border border-amber-300/30 bg-amber-300/10 p-2 text-xs text-amber-100">{{ locale.t('team.ownerWarning') }}</p>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <select :value="member.role" class="h-9 rounded-md border border-white/10 bg-zinc-950 px-3 text-sm text-white outline-none" @change="updateRole(member, ($event.target as HTMLSelectElement).value as TenantUser['role'])">
                        <option v-for="role in roles" :key="role" :value="role">{{ locale.t(`team.roles.${role}`) }}</option>
                    </select>
                    <Button size="sm" variant="secondary" :disabled="busy || (member.id === selfId && ['super_admin', 'owner'].includes(member.role))" @click="toggleStatus(member)">{{ member.status === 'disabled' ? locale.t('team.activate') : locale.t('team.disable') }}</Button>
                </div>
            </article>
        </div>
    </Card>
</template>