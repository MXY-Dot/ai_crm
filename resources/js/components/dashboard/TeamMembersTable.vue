<script setup lang="ts">
import { computed, ref } from 'vue';
import { Search } from '@lucide/vue';
import { useCrmDashboardStore, type TenantUser } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { timeAgo } from '../../lib/format';
import { Avatar, AvatarFallback } from '../ui/avatar';
import { Badge } from '../ui/badge';
import { Button } from '../ui/button';
import { Input } from '../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select';
import { Tabs, TabsList, TabsTrigger } from '../ui/tabs';

const props = defineProps<{ members: TenantUser[]; selfId: number | null }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();
const roles: TenantUser['role'][] = ['super_admin', 'owner', 'manager', 'operator'];
const query = ref('');
const statusFilter = ref('all');

const filtered = computed(() => props.members
    .filter((member) => statusFilter.value === 'all' || member.status === statusFilter.value)
    .filter((member) => {
        const needle = query.value.trim().toLowerCase();
        return ! needle || [member.name, member.email].some((value) => value.toLowerCase().includes(needle));
    }));

function tone(status: string): 'green' | 'blue' | 'amber' | 'neutral' {
    if (status === 'active') return 'green';
    if (status === 'invited') return 'blue';
    if (status === 'disabled') return 'amber';

    return 'neutral';
}

function isProtectedOwner(member: TenantUser): boolean {
    return member.id === props.selfId && ['super_admin', 'owner'].includes(member.role);
}

async function updateRole(member: TenantUser, role: TenantUser['role']): Promise<void> {
    if (member.role !== role) await store.updateTenantUser(member.id, { role });
}

async function toggleStatus(member: TenantUser): Promise<void> {
    if (isProtectedOwner(member)) return;
    await store.updateTenantUser(member.id, { status: member.status === 'disabled' ? 'active' : 'disabled' });
}
</script>

<template>
    <div class="rounded-xl border border-border bg-card">
        <div class="flex flex-wrap items-center gap-3 border-b p-4 border-border">
            <div class="relative flex-1 sm:max-w-xs">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 ui-subtle" />
                <Input v-model="query" class="h-9 pl-9 lg:pl-10" :placeholder="locale.t('team.searchPlaceholder')" />
            </div>
            <Tabs v-model="statusFilter">
                <TabsList>
                    <TabsTrigger value="all">{{ locale.t('team.allStatuses') }}</TabsTrigger>
                    <TabsTrigger value="active">{{ locale.t('team.statusActive') }}</TabsTrigger>
                    <TabsTrigger value="invited">{{ locale.t('team.statusInvited') }}</TabsTrigger>
                    <TabsTrigger value="disabled">{{ locale.t('team.statusDisabled') }}</TabsTrigger>
                </TabsList>
            </Tabs>
        </div>

        <div class="divide-y border-border">
            <div v-for="member in filtered" :key="member.id" class="flex flex-wrap items-center gap-3 p-4">
                <Avatar class="size-9">
                    <AvatarFallback class="text-xs font-semibold bg-accent text-accent-foreground">{{ member.name.slice(0, 2).toUpperCase() }}</AvatarFallback>
                </Avatar>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium ui-text">{{ member.name }}</p>
                    <p class="truncate text-xs ui-subtle">{{ member.email }} &middot; {{ member.last_login_at ? timeAgo(member.last_login_at, locale.locale) : locale.t('team.neverLoggedIn') }}</p>
                </div>
                <Badge :tone="tone(member.status)">{{ locale.t(`team.status${member.status.charAt(0).toUpperCase()}${member.status.slice(1)}`) }}</Badge>
                <Select :model-value="member.role" @update:model-value="(value) => updateRole(member, value as TenantUser['role'])">
                    <SelectTrigger class="h-9 w-40"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="role in roles" :key="role" :value="role">{{ locale.t(`team.roles.${role}`) }}</SelectItem>
                    </SelectContent>
                </Select>
                <Button size="sm" variant="secondary" :disabled="store.busy || isProtectedOwner(member)" @click="toggleStatus(member)">
                    {{ member.status === 'disabled' ? locale.t('team.activate') : locale.t('team.disable') }}
                </Button>
            </div>
            <p v-if="! filtered.length" class="p-6 text-center text-sm ui-subtle">{{ locale.t('common.noResults') }}</p>
        </div>
    </div>
</template>
