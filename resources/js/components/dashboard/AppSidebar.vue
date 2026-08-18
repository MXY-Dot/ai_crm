<script setup lang="ts">
import { computed } from 'vue';
import { BarChart3, Blocks, Bot, BookOpen, CalendarCheck, CreditCard, Inbox, LayoutDashboard, LifeBuoy, Plug, Settings, Target, Users, Users2 } from '@lucide/vue';
import { pagePaths, type DashboardPage } from '../../lib/pages';
import { canAccessPage } from '../../lib/permissions';
import { useLocaleStore } from '../../stores/locale';
import { useUnreadStore } from '../../stores/unread';
import Sidebar, { type SidebarUser } from './Sidebar.vue';

const props = defineProps<{ active: string; tenantName: string; collapsed: boolean; user: SidebarUser; logoutProcessing: boolean }>();
defineEmits<{ toggle: []; logout: [] }>();

const locale = useLocaleStore();
const unread = useUnreadStore();
const allItems: Array<{ id: DashboardPage; label: string; icon: unknown }> = [
    { id: 'overview', label: 'nav.overview', icon: LayoutDashboard },
    { id: 'inbox', label: 'nav.inbox', icon: Inbox },
    { id: 'leads', label: 'nav.leads', icon: Target },
    { id: 'contacts', label: 'nav.contacts', icon: Users },
    { id: 'ai', label: 'nav.ai', icon: Bot },
    { id: 'knowledge', label: 'nav.knowledge', icon: BookOpen },
    { id: 'analytics', label: 'nav.analytics', icon: BarChart3 },
    { id: 'integrations', label: 'nav.integrations', icon: Plug },
    { id: 'marketplace', label: 'nav.marketplace', icon: Blocks },
    { id: 'team', label: 'nav.team', icon: Users2 },
    { id: 'support', label: 'nav.support', icon: LifeBuoy },
    { id: 'billing', label: 'nav.billing', icon: CreditCard },
    { id: 'settings', label: 'nav.settings', icon: Settings },
];
const items = computed(() => allItems
    .filter((item) => canAccessPage(props.user?.role, item.id))
    .map((item) => ({
        href: pagePaths[item.id],
        label: locale.t(item.label),
        icon: item.icon,
        badge: item.id === 'inbox' ? unread.total : undefined,
    })));
const activeHref = computed(() => pagePaths[props.active as DashboardPage] ?? props.active);
</script>

<template>
    <Sidebar
        :collapsed="collapsed"
        :nav-items="items"
        :active-href="activeHref"
        :user="user"
        :logout-processing="logoutProcessing"
        :profile-href="pagePaths.profile"
        :logout-label="locale.t('auth.logout')"
        :workspace-href="pagePaths.overview"
        :switcher-mode="user?.role === 'super_admin' ? 'workspace' : undefined"
        @toggle="$emit('toggle')"
        @logout="$emit('logout')"
    >
        <template #companyCard>
            <div class="flex items-center gap-2 p-3">
                <CalendarCheck class="h-4 w-4 shrink-0 text-primary" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium ui-text">{{ locale.t('company.sidebarTitle') }}</span>
                    <span class="block truncate text-xs ui-subtle">{{ tenantName }}</span>
                </span>
            </div>
        </template>
    </Sidebar>
</template>
