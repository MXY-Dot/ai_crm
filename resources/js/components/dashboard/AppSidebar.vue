<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { BarChart3, Bot, BookOpen, Building2, CalendarCheck, Inbox, LayoutDashboard, Plug, Settings, ShoppingBag, Target, Users } from '@lucide/vue';
import { pagePaths, type DashboardPage } from '../../lib/pages';
import { useLocaleStore } from '../../stores/locale';

const locale = useLocaleStore();
const items = computed<Array<{ id: DashboardPage; label: string; icon: unknown }>>(() => [
    { id: 'overview', label: locale.t('nav.overview'), icon: LayoutDashboard },
    { id: 'inbox', label: locale.t('nav.inbox'), icon: Inbox },
    { id: 'leads', label: locale.t('nav.leads'), icon: Target },
    { id: 'customers', label: locale.t('nav.customers'), icon: Users },
    { id: 'crm', label: locale.t('nav.crm'), icon: ShoppingBag },
    { id: 'ai', label: locale.t('nav.ai'), icon: Bot },
    { id: 'knowledge', label: locale.t('nav.knowledge'), icon: BookOpen },
    { id: 'analytics', label: locale.t('nav.analytics'), icon: BarChart3 },
    { id: 'integrations', label: locale.t('nav.integrations'), icon: Plug },
    { id: 'settings', label: locale.t('nav.settings'), icon: Settings },
]);

function itemClass(active: string, id: DashboardPage): string {
    return active === id
        ? 'border-blue-500/40 bg-blue-600 text-white shadow-sm shadow-blue-950/30'
        : 'border-transparent ui-subtle hover:border-[var(--border)] hover:bg-[var(--muted)] hover:text-[var(--foreground)]';
}

defineProps<{ active: string; tenantName: string }>();
</script>

<template>
    <aside class="hidden w-64 shrink-0 border-r px-3 py-4 lg:block" style="border-color: var(--border); background: color-mix(in srgb, var(--background) 96%, #020617)">
        <Link class="flex items-center gap-3 px-2" :href="pagePaths.overview">
            <div class="grid h-9 w-9 place-items-center rounded-lg bg-blue-600 text-white"><Building2 class="h-4 w-4" /></div>
            <div class="min-w-0"><p class="truncate text-sm font-semibold ui-text">Gravity AI</p><p class="truncate text-xs text-blue-400">CRM</p></div>
        </Link>
        <nav class="mt-6 space-y-1">
            <Link v-for="item in items" :key="item.id" :href="pagePaths[item.id]" class="flex h-9 w-full items-center gap-2 rounded-md border px-3 text-sm font-medium transition" :class="itemClass(active, item.id)">
                <component :is="item.icon" class="h-4 w-4" />{{ item.label }}
            </Link>
        </nav>
        <div class="mt-6 rounded-lg border p-3 ui-muted"><div class="flex items-center gap-2 text-sm font-medium ui-text"><CalendarCheck class="h-4 w-4 text-blue-400" />{{ locale.t('company.sidebarTitle') }}</div><p class="mt-2 text-xs leading-5 ui-subtle">{{ tenantName }}</p></div>
    </aside>
</template>