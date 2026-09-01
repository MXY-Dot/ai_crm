<script setup lang="ts">
import { computed } from 'vue';
import { BarChart3, Blocks, Bot, BookOpen, Building2, CalendarCheck, CalendarClock, CreditCard, Hotel, Inbox, Layers, LayoutDashboard, LifeBuoy, Package, Plug, Scissors, Settings, ShoppingCart, Target, Users, Users2, Utensils } from '@lucide/vue';
import { pagePaths, type DashboardPage } from '../../lib/pages';
import { canAccessPage } from '../../lib/permissions';
import { useLocaleStore } from '../../stores/locale';
import { useUnreadStore } from '../../stores/unread';
import Sidebar, { type SidebarEntry, type SidebarUser } from './Sidebar.vue';

const props = defineProps<{ active: string; tenantName: string; collapsed: boolean; user: SidebarUser; logoutProcessing: boolean }>();
defineEmits<{ toggle: []; logout: [] }>();

const locale = useLocaleStore();
const unread = useUnreadStore();

type RawItem = { id: DashboardPage; label: string; icon: unknown };
type RawGroup = { groupId: string; label: string; icon: unknown; children: RawItem[] };
type RawEntry = RawItem | RawGroup;

function isRawGroup(entry: RawEntry): entry is RawGroup {
    return 'children' in entry;
}

// Split into semantic groups so the list stays scannable as more modules ship
// (each new business module lands in "Модули" automatically) -- the everyday
// items (overview/inbox/leads/contacts/analytics) stay ungrouped so they're
// never an extra click away.
const allEntries: RawEntry[] = [
    { id: 'overview', label: 'nav.overview', icon: LayoutDashboard },
    { id: 'inbox', label: 'nav.inbox', icon: Inbox },
    { id: 'leads', label: 'nav.leads', icon: Target },
    { id: 'contacts', label: 'nav.contacts', icon: Users },
    {
        groupId: 'modules', label: 'nav.groupModules', icon: Layers,
        children: [
            { id: 'booking', label: 'nav.booking', icon: CalendarClock },
            { id: 'booking-settings', label: 'nav.bookingSettings', icon: Scissors },
            { id: 'orders', label: 'nav.orders', icon: ShoppingCart },
            { id: 'catalog-settings', label: 'nav.catalogSettings', icon: Package },
            { id: 'restaurant-settings', label: 'nav.restaurantSettings', icon: Utensils },
            { id: 'hotel-settings', label: 'nav.hotelSettings', icon: Hotel },
        ],
    },
    {
        groupId: 'ai', label: 'nav.groupAi', icon: Bot,
        children: [
            { id: 'ai', label: 'nav.ai', icon: Bot },
            { id: 'knowledge', label: 'nav.knowledge', icon: BookOpen },
        ],
    },
    { id: 'analytics', label: 'nav.analytics', icon: BarChart3 },
    {
        groupId: 'connections', label: 'nav.groupConnections', icon: Plug,
        children: [
            { id: 'integrations', label: 'nav.integrations', icon: Plug },
            { id: 'marketplace', label: 'nav.marketplace', icon: Blocks },
        ],
    },
    {
        groupId: 'company', label: 'nav.groupCompany', icon: Building2,
        children: [
            { id: 'team', label: 'nav.team', icon: Users2 },
            { id: 'support', label: 'nav.support', icon: LifeBuoy },
            { id: 'billing', label: 'nav.billing', icon: CreditCard },
            { id: 'settings', label: 'nav.settings', icon: Settings },
        ],
    },
];

function toNavItem(item: RawItem) {
    return {
        href: pagePaths[item.id],
        label: locale.t(item.label),
        icon: item.icon,
        badge: item.id === 'inbox' ? unread.total : undefined,
    };
}

const items = computed<SidebarEntry[]>(() => allEntries.reduce<SidebarEntry[]>((acc, entry) => {
    if (! isRawGroup(entry)) {
        if (canAccessPage(props.user?.role, entry.id)) acc.push(toNavItem(entry));
        return acc;
    }

    const children = entry.children.filter((child) => canAccessPage(props.user?.role, child.id)).map(toNavItem);
    if (children.length) acc.push({ id: entry.groupId, label: locale.t(entry.label), icon: entry.icon, children });

    return acc;
}, []));

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
