<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { BarChart3, Blocks, Bot, BookOpen, CalendarCheck, CreditCard, Inbox, LayoutDashboard, Plug, Settings, Target, Users, Users2 } from '@lucide/vue';
import { pagePaths, type DashboardPage } from '../../lib/pages';
import { APP_VERSION } from '../../lib/version';
import { useLocaleStore } from '../../stores/locale';

const locale = useLocaleStore();
const items = computed<Array<{ id: DashboardPage; label: string; icon: unknown }>>(() => [
    { id: 'overview', label: locale.t('nav.overview'), icon: LayoutDashboard },
    { id: 'inbox', label: locale.t('nav.inbox'), icon: Inbox },
    { id: 'leads', label: locale.t('nav.leads'), icon: Target },
    { id: 'contacts', label: locale.t('nav.contacts'), icon: Users },
    { id: 'ai', label: locale.t('nav.ai'), icon: Bot },
    { id: 'knowledge', label: locale.t('nav.knowledge'), icon: BookOpen },
    { id: 'analytics', label: locale.t('nav.analytics'), icon: BarChart3 },
    { id: 'integrations', label: locale.t('nav.integrations'), icon: Plug },
    { id: 'marketplace', label: locale.t('nav.marketplace'), icon: Blocks },
    { id: 'team', label: locale.t('nav.team'), icon: Users2 },
    { id: 'billing', label: locale.t('nav.billing'), icon: CreditCard },
    { id: 'settings', label: locale.t('nav.settings'), icon: Settings },
]);

function itemClass(active: string, id: DashboardPage): string {
    return active === id
        ? 'border-primary bg-card text-primary shadow-sm font-semibold'
        : 'border-transparent ui-subtle hover:bg-muted hover:text-foreground';
}

defineProps<{ active: string; tenantName: string }>();
</script>

<template>
    <aside class="hidden shrink-0 border-r px-3 py-5 lg:block" style="width: 260px; border-color: var(--sidebar-border); background: var(--sidebar)">
        <Link class="flex items-center gap-3 px-2" :href="pagePaths.overview">
            <div class="grid h-9 w-9 place-items-center rounded-lg font-display text-sm font-bold" style="background: var(--sidebar-primary); color: var(--sidebar-primary-foreground)">W</div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-1.5">
                    <p class="truncate font-display text-sm font-bold ui-text">WERO</p>
                    <span class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-bold" style="background: var(--primary); color: var(--primary-foreground)">v{{ APP_VERSION }}</span>
                </div>
                <p class="truncate text-[10px] font-semibold uppercase tracking-widest" style="color: var(--primary)">Omni-channel AI</p>
            </div>
        </Link>
        <nav class="mt-6 space-y-1">
            <Link
                v-for="item in items"
                :key="item.id"
                :href="pagePaths[item.id]"
                class="flex h-10 w-full items-center gap-3 rounded-lg border-l-4 px-3 text-sm font-medium transition"
                :class="itemClass(active, item.id)"
            >
                <component :is="item.icon" class="h-4 w-4" />{{ item.label }}
            </Link>
        </nav>
        <div class="mt-6 rounded-lg border p-3 ui-muted" style="border-color: var(--sidebar-border)">
            <div class="flex items-center gap-2 text-sm font-medium ui-text"><CalendarCheck class="h-4 w-4" style="color: var(--primary)" />{{ locale.t('company.sidebarTitle') }}</div>
            <p class="mt-2 text-xs leading-5 ui-subtle">{{ tenantName }}</p>
        </div>
    </aside>
</template>
