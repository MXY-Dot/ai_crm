<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';
import InviteTeamMemberDialog from '../components/dashboard/InviteTeamMemberDialog.vue';
import RolePermissionsTable from '../components/dashboard/RolePermissionsTable.vue';
import TeamMembersTable from '../components/dashboard/TeamMembersTable.vue';
import TeamStatsBento from '../components/dashboard/TeamStatsBento.vue';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { tenantUsers, user } = storeToRefs(store);

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('team.title') }}</h2>
                <p class="mt-1 text-sm ui-subtle">{{ locale.t('team.subtitle') }}</p>
            </div>
            <InviteTeamMemberDialog />
        </div>

        <TeamStatsBento :members="tenantUsers" />
        <RolePermissionsTable />
        <TeamMembersTable :members="tenantUsers" :self-id="user?.id ?? null" />
    </section>
</template>
