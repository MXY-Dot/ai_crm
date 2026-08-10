<script setup lang="ts">
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import { Link } from '@inertiajs/vue3';
import { BarChart3, Blocks, Bot, BookOpen, CalendarCheck, ChevronLeft, ChevronRight, CreditCard, Inbox, LayoutDashboard, Plug, Settings, Target, Users, Users2 } from '@lucide/vue';
import { pagePaths, type DashboardPage } from '../../lib/pages';
import { APP_VERSION } from '../../lib/version';
import { useLocaleStore } from '../../stores/locale';
import { useThemeStore } from '../../stores/theme';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '../ui/tooltip';

const locale = useLocaleStore();
const { isDark } = storeToRefs(useThemeStore());
const logoSrc = computed(() => isDark.value ? '/storage/logo/logo_dark.png' : '/storage/logo/logo.png');
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
        class="relative hidden shrink-0 border-r border-sidebar-border bg-sidebar px-3 py-5 transition-[width] duration-300 ease-in-out lg:block"
        :style="{ width: collapsed ? '76px' : '260px' }"
    >
        <button
            type="button"
            data-tour="sidebar-toggle"
            class="absolute top-6 -right-3 z-10 grid h-6 w-6 place-items-center rounded-full border shadow-sm border-sidebar-border bg-card"
            :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
            @click="$emit('toggle')"
        >
            <ChevronRight v-if="collapsed" class="h-3.5 w-3.5 ui-subtle" />
            <ChevronLeft v-else class="h-3.5 w-3.5 ui-subtle" />
        </button>

        <Link data-tour="logo" class="flex items-center gap-3 px-2" :href="pagePaths.overview">
            <div v-if="collapsed" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg font-display text-sm font-bold bg-sidebar-primary text-sidebar-primary-foreground">W</div>
            <div v-else class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <img :src="logoSrc" alt="WERO" class="h-7 w-auto shrink-0">
                    <span class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-bold bg-primary text-primary-foreground">v{{ APP_VERSION }}</span>
                </div>
                <p class="mt-1 truncate text-[10px] font-semibold uppercase tracking-widest text-primary">Omni-channel AI</p>
            </div>
        </Link>

        <TooltipProvider :delay-duration="200">
            <nav class="mt-6 space-y-1" data-tour="nav">
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

        <div v-if="!collapsed" class="mt-6 rounded-lg border p-3 ui-muted border-sidebar-border">
            <div class="flex items-center gap-2 text-sm font-medium ui-text"><CalendarCheck class="h-4 w-4 text-primary" />{{ locale.t('company.sidebarTitle') }}</div>
            <p class="mt-2 text-xs leading-5 ui-subtle">{{ tenantName }}</p>
        </div>
    </aside>
</template>
