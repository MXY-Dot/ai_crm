<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { Link, usePage } from '@inertiajs/vue3';
import { Check, ChevronDown, ChevronLeft, ChevronRight, ChevronsUpDown, LayoutGrid, LogOut, ShieldCheck } from '@lucide/vue';
import { useThemeStore } from '../../stores/theme';
import { Avatar, AvatarFallback, AvatarImage } from '../ui/avatar';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '../ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '../ui/tooltip';

export type SidebarNavItem = { href: string; label: string; icon: unknown; badge?: number };
export type SidebarNavGroup = { id: string; label: string; icon: unknown; children: SidebarNavItem[] };
export type SidebarEntry = SidebarNavItem | SidebarNavGroup;
export type SidebarUser = { name: string; email: string; avatar_url?: string | null; role?: string } | null;

function isGroup(entry: SidebarEntry): entry is SidebarNavGroup {
    return 'children' in entry;
}

const props = defineProps<{
    collapsed: boolean;
    navItems: SidebarEntry[];
    activeHref: string;
    user: SidebarUser;
    logoutProcessing: boolean;
    profileHref: string;
    logoutLabel: string;
    workspaceHref: string;
    switcherMode?: 'workspace' | 'admin';
}>();
defineEmits<{ toggle: []; logout: [] }>();
defineSlots<{ companyCard?: () => unknown }>();

const { isDark } = storeToRefs(useThemeStore());
const logoSrc = computed(() => isDark.value ? '/storage/logo/logo_dark.png' : '/storage/logo/logo.png');
const appVersion = computed(() => usePage<{ appVersion: string }>().props.appVersion);

function itemClass(activeHref: string, href: string): string {
    return activeHref === href
        ? 'border-primary bg-card text-primary shadow-sm font-semibold'
        : 'border-transparent ui-subtle hover:bg-muted hover:text-foreground';
}

// No room for a nested disclosure UI once the sidebar itself is icon-only --
// every group's children just join the flat icon list instead, same as an
// ungrouped item always has.
const flatItems = computed<SidebarNavItem[]>(() => props.navItems.flatMap((entry) => (isGroup(entry) ? entry.children : [entry])));

// A group containing the current page starts open, so navigating deep into a
// module never hides where you are; other groups start closed to keep the
// list short. Re-derived on every navigation rather than persisted, which
// already keeps "where am I" correct without needing localStorage.
const openGroups = ref<Set<string>>(new Set());

function groupContainsActive(group: SidebarNavGroup): boolean {
    return group.children.some((child) => child.href === props.activeHref);
}

function syncOpenGroups(): void {
    const next = new Set(openGroups.value);
    for (const entry of props.navItems) {
        if (isGroup(entry) && groupContainsActive(entry)) next.add(entry.id);
    }
    openGroups.value = next;
}

watch(() => props.activeHref, syncOpenGroups, { immediate: true });

function toggleGroup(id: string): void {
    const next = new Set(openGroups.value);
    if (next.has(id)) next.delete(id); else next.add(id);
    openGroups.value = next;
}
</script>

<template>
    <aside
        class="relative hidden shrink-0 flex-col border-r border-sidebar-border bg-sidebar px-3 py-5 transition-[width] duration-300 ease-in-out lg:flex"
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

        <DropdownMenu v-if="switcherMode">
            <DropdownMenuTrigger as-child>
                <button type="button" data-tour="logo" class="flex w-full shrink-0 items-center gap-3 rounded-lg px-2 py-1 text-left transition hover:bg-muted" :class="collapsed ? 'justify-center px-0' : ''">
                    <div v-if="collapsed" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg font-display text-sm font-bold bg-sidebar-primary text-sidebar-primary-foreground">W</div>
                    <template v-else>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <img :src="logoSrc" alt="WERO" class="h-7 w-auto shrink-0">
                                <span class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-bold bg-primary text-primary-foreground">v{{ appVersion }}</span>
                            </div>
                            <p v-if="switcherMode === 'admin'" class="mt-1 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-widest text-primary">
                                <ShieldCheck class="h-3 w-3" /> Super Admin
                            </p>
                            <p v-else class="mt-1 truncate text-[10px] font-semibold uppercase tracking-widest text-primary">Omni-channel AI</p>
                        </div>
                        <ChevronsUpDown class="h-4 w-4 shrink-0 ui-subtle" />
                    </template>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-60">
                <DropdownMenuItem disabled class="opacity-100">
                    <component :is="switcherMode === 'admin' ? ShieldCheck : LayoutGrid" class="h-4 w-4 text-primary" />
                    <span class="flex-1">{{ switcherMode === 'admin' ? 'Super Admin' : 'Рабочее пространство' }}</span>
                    <Check class="h-4 w-4 text-primary" />
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem as-child>
                    <a :href="switcherMode === 'admin' ? workspaceHref : '/super-admin/companies'" class="flex items-center gap-2">
                        <component :is="switcherMode === 'admin' ? LayoutGrid : ShieldCheck" class="h-4 w-4 ui-subtle" />
                        <span class="flex-1">{{ switcherMode === 'admin' ? 'Рабочее пространство' : 'Super Admin' }}</span>
                    </a>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <Link v-else data-tour="logo" class="flex shrink-0 items-center gap-3 px-2" :href="workspaceHref">
            <div v-if="collapsed" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg font-display text-sm font-bold bg-sidebar-primary text-sidebar-primary-foreground">W</div>
            <div v-else class="min-w-0 flex-1">
                <img :src="logoSrc" alt="WERO" class="h-7 w-auto shrink-0">
                <p class="mt-1 truncate text-[10px] font-semibold uppercase tracking-widest text-primary">Omni-channel AI</p>
            </div>
        </Link>

        <TooltipProvider :delay-duration="200">
            <div class="mt-6 min-h-0 flex-1 overflow-y-auto">
                <nav v-if="collapsed" class="space-y-1" data-tour="nav">
                    <Tooltip v-for="item in flatItems" :key="item.href" :disabled="false">
                        <TooltipTrigger as-child>
                            <Link
                                :href="item.href"
                                class="flex h-10 w-full items-center justify-center gap-3 rounded-lg border-l-4 px-0 text-sm font-medium transition"
                                :class="itemClass(activeHref, item.href)"
                            >
                                <span class="relative shrink-0">
                                    <component :is="item.icon" class="h-4 w-4" />
                                    <span
                                        v-if="item.badge"
                                        class="absolute -right-1.5 -top-1.5 grid h-3.5 min-w-3.5 place-items-center rounded-full px-0.5 text-[9px] font-bold bg-primary text-primary-foreground"
                                    >{{ item.badge > 9 ? '9+' : item.badge }}</span>
                                </span>
                            </Link>
                        </TooltipTrigger>
                        <TooltipContent side="right">{{ item.label }}</TooltipContent>
                    </Tooltip>
                </nav>

                <nav v-else class="space-y-1" data-tour="nav">
                    <template v-for="entry in navItems" :key="isGroup(entry) ? entry.id : entry.href">
                        <Link
                            v-if="! isGroup(entry)"
                            :href="entry.href"
                            class="flex h-10 w-full items-center gap-3 rounded-lg border-l-4 px-3 text-sm font-medium transition"
                            :class="itemClass(activeHref, entry.href)"
                        >
                            <component :is="entry.icon" class="h-4 w-4 shrink-0" />
                            <span class="min-w-0 flex-1 truncate">{{ entry.label }}</span>
                            <span
                                v-if="entry.badge"
                                class="grid h-5 min-w-5 shrink-0 place-items-center rounded-full px-1.5 text-[10px] font-bold bg-primary text-primary-foreground"
                            >{{ entry.badge > 99 ? '99+' : entry.badge }}</span>
                        </Link>

                        <div v-else>
                            <button
                                type="button"
                                class="flex h-10 w-full items-center gap-3 rounded-lg border-l-4 px-3 text-sm font-medium transition"
                                :class="groupContainsActive(entry) && ! openGroups.has(entry.id) ? itemClass(activeHref, entry.id) : 'border-transparent ui-subtle hover:bg-muted hover:text-foreground'"
                                @click="toggleGroup(entry.id)"
                            >
                                <component :is="entry.icon" class="h-4 w-4 shrink-0" />
                                <span class="min-w-0 flex-1 truncate text-left">{{ entry.label }}</span>
                                <ChevronDown class="h-3.5 w-3.5 shrink-0 transition-transform" :class="openGroups.has(entry.id) ? 'rotate-180' : ''" />
                            </button>
                            <div
                                class="grid transition-[grid-template-rows] duration-200 ease-in-out"
                                :style="{ gridTemplateRows: openGroups.has(entry.id) ? '1fr' : '0fr' }"
                            >
                                <div class="overflow-hidden">
                                    <div class="mt-1 ml-3 space-y-1 border-l border-sidebar-border pl-3 pt-1">
                                        <Link
                                            v-for="child in entry.children" :key="child.href"
                                            :href="child.href"
                                            class="flex h-9 w-full items-center gap-3 rounded-lg border-l-4 px-3 text-sm font-medium transition"
                                            :class="itemClass(activeHref, child.href)"
                                        >
                                            <component :is="child.icon" class="h-3.5 w-3.5 shrink-0" />
                                            <span class="min-w-0 flex-1 truncate">{{ child.label }}</span>
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </nav>
            </div>

            <div class="mt-4 shrink-0 border-t pt-4 border-sidebar-border" :class="collapsed ? 'flex flex-col items-center gap-2' : ''">
                <div v-if="!collapsed" class="overflow-hidden rounded-lg border border-sidebar-border">
                    <div v-if="$slots.companyCard" class="border-b border-sidebar-border">
                        <slot name="companyCard" />
                    </div>
                    <div class="flex items-center gap-2 p-2">
                        <Link :href="profileHref" data-tour="profile" class="flex min-w-0 flex-1 items-center gap-2 rounded-lg p-1 transition hover:bg-muted" :title="user?.email">
                            <Avatar class="size-9 shrink-0">
                                <AvatarImage v-if="user?.avatar_url" :src="user.avatar_url" alt="" />
                                <AvatarFallback class="text-xs font-semibold bg-primary text-primary-foreground">{{ user?.name?.[0] ?? '?' }}</AvatarFallback>
                            </Avatar>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium ui-text">{{ user?.name }}</span>
                                <span class="block truncate text-xs ui-subtle">{{ user?.email }}</span>
                            </span>
                        </Link>
                        <button
                            type="button"
                            class="grid h-8 w-8 shrink-0 place-items-center rounded-lg transition hover:bg-muted"
                            :disabled="logoutProcessing"
                            :title="logoutLabel"
                            @click="$emit('logout')"
                        >
                            <LogOut class="h-4 w-4 ui-subtle" />
                        </button>
                    </div>
                </div>

                <template v-else>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Link :href="profileHref" data-tour="profile">
                                <Avatar class="size-9">
                                    <AvatarImage v-if="user?.avatar_url" :src="user.avatar_url" alt="" />
                                    <AvatarFallback class="text-xs font-semibold bg-primary text-primary-foreground">{{ user?.name?.[0] ?? '?' }}</AvatarFallback>
                                </Avatar>
                            </Link>
                        </TooltipTrigger>
                        <TooltipContent side="right">{{ user?.name }}</TooltipContent>
                    </Tooltip>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="grid h-9 w-9 shrink-0 place-items-center rounded-lg transition hover:bg-muted"
                                :disabled="logoutProcessing"
                                @click="$emit('logout')"
                            >
                                <LogOut class="h-4 w-4 ui-subtle" />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent side="right">{{ logoutLabel }}</TooltipContent>
                    </Tooltip>
                </template>
            </div>
        </TooltipProvider>
    </aside>
</template>
