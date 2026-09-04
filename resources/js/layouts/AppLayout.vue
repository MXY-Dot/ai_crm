<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { storeToRefs } from 'pinia';
import { LogOut, Menu } from '@lucide/vue';
import AppSidebar from '@/components/dashboard/AppSidebar.vue';
import EmailConfirmationBanner from '@/components/dashboard/EmailConfirmationBanner.vue';
import EmergencyBanner from '@/components/dashboard/EmergencyBanner.vue';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import GlobalSearch from '@/components/dashboard/GlobalSearch.vue';
import LanguageSwitcher from '@/components/dashboard/LanguageSwitcher.vue';
import MobileNav from '@/components/dashboard/MobileNav.vue';
import NotificationBell from '@/components/dashboard/NotificationBell.vue';
import ThemeSwitcher from '@/components/dashboard/ThemeSwitcher.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Drawer, DrawerContent, DrawerHeader, DrawerTitle } from '@/components/ui/drawer';
import { Toaster } from '@/components/ui/sonner';
import { pageFromPath, pagePaths } from '@/lib/pages';
import { hasSeenOnboarding, maybeStartPageTour, startOnboarding } from '@/lib/onboarding';
import { type Bootstrap, useCrmDashboardStore } from '@/stores/crmDashboard';
import { useLocaleStore } from '@/stores/locale';
import { useThemeStore } from '@/stores/theme';
import { useUnreadStore } from '@/stores/unread';
import { useEmergencyStore } from '@/stores/emergency';

const page = usePage<{ bootstrap: Bootstrap }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();
const unread = useUnreadStore();
const emergency = useEmergencyStore();
useThemeStore();
const { tenant, company, hasData, user, enabledModules } = storeToRefs(store);
const activePage = computed(() => pageFromPath(new URL(page.url, window.location.origin).pathname));

watch(
    () => page.props.bootstrap,
    (bootstrap) => bootstrap && store.hydrateBootstrap(bootstrap),
    { immediate: true },
);

watch(hasData, (value) => {
    if (! value) return;

    unread.start();
    emergency.start();

    nextTick(() => {
        if (! hasSeenOnboarding()) {
            startOnboarding(() => maybeStartPageTour(activePage.value));
        } else {
            maybeStartPageTour(activePage.value);
        }
    });
}, { immediate: true });

watch(activePage, (page) => {
    if (! hasData.value || ! hasSeenOnboarding()) return;
    nextTick(() => maybeStartPageTour(page));
});

let dashboardRefreshTimer: number | null = null;
let dashboardRefreshInFlight = false;

async function refreshDashboardQuietly(): Promise<void> {
    if (! hasData.value || document.hidden || dashboardRefreshInFlight) return;

    dashboardRefreshInFlight = true;
    try {
        await store.refreshDashboard();
    } finally {
        dashboardRefreshInFlight = false;
    }
}

onMounted(() => {
    dashboardRefreshTimer = window.setInterval(refreshDashboardQuietly, 10000);
});

onBeforeUnmount(() => {
    if (dashboardRefreshTimer !== null) window.clearInterval(dashboardRefreshTimer);
    unread.stop();
    emergency.stop();
});

const logoutProcessing = ref(false);

function logout(): void {
    if (logoutProcessing.value) return;

    logoutProcessing.value = true;
    router.post('/logout', {}, {
        onFinish: () => { logoutProcessing.value = false; },
    });
}

const mobileMenuOpen = ref(false);

function logoutFromMenu(): void {
    mobileMenuOpen.value = false;
    logout();
}

const sidebarCollapsed = ref(localStorage.getItem('gravity_sidebar_collapsed') === '1');

function toggleSidebar(): void {
    sidebarCollapsed.value = ! sidebarCollapsed.value;
    localStorage.setItem('gravity_sidebar_collapsed', sidebarCollapsed.value ? '1' : '0');
}
</script>

<template>
    <div class="h-screen overflow-hidden antialiased bg-background text-foreground">
        <div class="flex h-screen">
            <AppSidebar
                :active="activePage"
                :tenant-name="tenant?.name ?? locale.t('common.noTenant')"
                :collapsed="sidebarCollapsed"
                :user="user"
                :enabled-modules="enabledModules"
                :logout-processing="logoutProcessing"
                @toggle="toggleSidebar"
                @logout="logout"
            />

            <main class="h-full min-w-0 flex-1 overflow-y-auto pb-20 lg:pb-0">
                <div v-if="emergency.mode === 'emergency'" class="px-4 pt-3 sm:px-6 lg:px-8">
                    <EmergencyBanner />
                </div>
                <div v-if="user?.email_verified_at && !user?.email_link_confirmed_at" class="px-4 pt-3 sm:px-6 lg:px-8">
                    <EmailConfirmationBanner />
                </div>
                <header class="sticky top-0 z-10 border-b px-4 py-3 backdrop-blur sm:px-6 lg:px-8 lg:py-4 border-border bg-card/92">
                    <!-- Desktop / tablet header -->
                    <div class="hidden lg:flex lg:flex-col lg:gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div>
                            <h1 class="font-display text-2xl font-bold ui-text sm:text-3xl">{{ company?.name ?? 'WERO' }}</h1>
                            <p class="mt-2 max-w-3xl text-sm leading-6 ui-subtle">{{ locale.t('header.description') }}</p>
                        </div>

                        <div class="grid gap-2 sm:grid-cols-[minmax(14rem,20rem)_auto_auto_auto] sm:items-center">
                            <GlobalSearch data-tour="search" />
                            <LanguageSwitcher />
                            <ThemeSwitcher data-tour="theme" />
                            <NotificationBell />
                        </div>
                    </div>

                    <!-- Mobile header -->
                    <div class="flex items-center gap-2 lg:hidden">
                        <h1 class="min-w-0 flex-1 truncate font-display text-lg font-bold ui-text">{{ company?.name ?? 'WERO' }}</h1>
                        <button
                            type="button"
                            class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border transition hover:bg-muted border-border"
                            :aria-label="locale.t('common.menu')"
                            @click="mobileMenuOpen = true"
                        >
                            <Menu class="h-4 w-4 ui-subtle" />
                        </button>
                    </div>
                </header>

                <Drawer v-model:open="mobileMenuOpen" direction="bottom">
                    <DrawerContent class="lg:hidden">
                        <DrawerHeader>
                            <DrawerTitle>{{ company?.name ?? 'WERO' }}</DrawerTitle>
                        </DrawerHeader>
                        <div class="flex flex-col gap-3 p-4">
                            <GlobalSearch data-tour="search" />
                            <div class="flex items-center justify-between gap-2">
                                <LanguageSwitcher />
                                <ThemeSwitcher data-tour="theme" />
                                <NotificationBell />
                            </div>
                            <Link :href="pagePaths.profile" class="flex items-center gap-2 rounded-lg border px-3 py-2 transition hover:bg-muted border-border" :title="user?.email" @click="mobileMenuOpen = false">
                                <Avatar class="size-8 shrink-0">
                                    <AvatarImage v-if="user?.avatar_url" :src="user.avatar_url" alt="" />
                                    <AvatarFallback class="text-xs font-semibold bg-primary text-primary-foreground">{{ user?.name?.[0] ?? '?' }}</AvatarFallback>
                                </Avatar>
                                <span class="min-w-0 flex-1 truncate text-sm font-medium ui-text">{{ user?.name }}</span>
                            </Link>
                            <button
                                type="button"
                                class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium text-destructive transition hover:bg-muted border-border"
                                :disabled="logoutProcessing"
                                @click="logoutFromMenu"
                            >
                                <LogOut class="h-4 w-4" /> {{ locale.t('auth.logout') }}
                            </button>
                        </div>
                    </DrawerContent>
                </Drawer>

                <div class="mx-auto flex w-full max-w-[1480px] flex-col gap-6 px-4 py-5 sm:px-6 lg:px-8" data-tour="content">
                    <Transition
                        mode="out-in"
                        enter-active-class="transition duration-200 ease-out"
                        enter-from-class="opacity-0 translate-y-1.5"
                        enter-to-class="opacity-100 translate-y-0"
                        leave-active-class="transition duration-100 ease-in"
                        leave-from-class="opacity-100"
                        leave-to-class="opacity-0"
                    >
                        <div :key="activePage" class="flex flex-col gap-6">
                            <EmptyState v-if="!hasData" />
                            <slot v-else />
                        </div>
                    </Transition>
                </div>
            </main>
        </div>
        <MobileNav :active="activePage" :role="user?.role" />
        <Toaster position="top-right" rich-colors close-button />
    </div>
</template>
