<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { BarChart3, Blocks, Bot, BookOpen, CalendarCheck, ChevronLeft, ChevronRight, CreditCard, Inbox, LayoutDashboard, Plug, Settings, Target, Users, Users2 } from '@lucide/vue';
import { pagePaths, type DashboardPage } from '../../lib/pages';
import { APP_VERSION } from '../../lib/version';
import { useLocaleStore } from '../../stores/locale';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '../ui/tooltip';

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

defineProps<{ active: string; tenantName: string; collapsed: boolean }>();
defineEmits<{ toggle: [] }>();
</script>

<template>
    <aside
        class="relative hidden shrink-0 border-r px-3 py-5 transition-[width] lg:block"
        :style="{ width: collapsed ? '76px' : '260px', borderColor: 'var(--sidebar-border)', background: 'var(--sidebar)' }"
    >
        <button
            type="button"
            class="absolute top-6 -right-3 z-10 grid h-6 w-6 place-items-center rounded-full border shadow-sm"
            style="border-color: var(--sidebar-border); background: var(--card)"
            :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
            @click="$emit('toggle')"
        >
            <ChevronRight v-if="collapsed" class="h-3.5 w-3.5 ui-subtle" />
            <ChevronLeft v-else class="h-3.5 w-3.5 ui-subtle" />
        </button>

        <Link class="flex items-center gap-3 px-2" :href="pagePaths.overview">
            <div class="grid h-9 w-9 shrink-0 place-items-center rounded-lg font-display text-sm font-bold" style="background: var(--sidebar-primary); color: var(--sidebar-primary-foreground)">W</div>
            <div v-if="!collapsed" class="min-w-0 flex-1">
                <div class="flex items-center gap-1.5">
                    <p class="truncate font-display text-sm font-bold ui-text">WERO</p>
                    <span class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-bold" style="background: var(--primary); color: var(--primary-foreground)">v{{ APP_VERSION }}</span>
                </div>
                <p class="truncate text-[10px] font-semibold uppercase tracking-widest" style="color: var(--primary)">Omni-channel AI</p>
            </div>
        </Link>

        <TooltipProvider :delay-duration="200">
            <nav class="mt-6 space-y-1">
                <Tooltip v-for="item in items" :key="item.id" :disabled="!collapsed">
                    <TooltipTrigger as-child>
                        <Link
                            :href="pagePaths[item.id]"
                            class="flex h-10 w-full items-center gap-3 rounded-lg border-l-4 px-3 text-sm font-medium transition"
                            :class="[itemClass(active, item.id), collapsed ? 'justify-center px-0' : '']"
                        >
                            <component :is="item.icon" class="h-4 w-4 shrink-0" />
                            <span v-if="!collapsed" class="truncate">{{ item.label }}</span>
                        </Link>
                    </TooltipTrigger>
                    <TooltipContent side="right">{{ item.label }}</TooltipContent>
                </Tooltip>
            </nav>
        </TooltipProvider>

        <div v-if="!collapsed" class="mt-6 rounded-lg border p-3 ui-muted" style="border-color: var(--sidebar-border)">
            <div class="flex items-center gap-2 text-sm font-medium ui-text"><CalendarCheck class="h-4 w-4" style="color: var(--primary)" />{{ locale.t('company.sidebarTitle') }}</div>
            <p class="mt-2 text-xs leading-5 ui-subtle">{{ tenantName }}</p>
        </div>
    </aside>
</template>
