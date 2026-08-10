<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { storeToRefs } from 'pinia';
import { LogOut } from '@lucide/vue';
import AppSidebar from '@/components/dashboard/AppSidebar.vue';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import GlobalSearch from '@/components/dashboard/GlobalSearch.vue';
import LanguageSwitcher from '@/components/dashboard/LanguageSwitcher.vue';
import MobileNav from '@/components/dashboard/MobileNav.vue';
import ThemeSwitcher from '@/components/dashboard/ThemeSwitcher.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Toaster } from '@/components/ui/sonner';
import { Button } from '@/components/ui/button';
import { pageFromPath, pagePaths } from '@/lib/pages';
import { hasSeenOnboarding, maybeStartPageTour, startOnboarding } from '@/lib/onboarding';
import { type Bootstrap, useCrmDashboardStore } from '@/stores/crmDashboard';
import { useLocaleStore } from '@/stores/locale';
import { useThemeStore } from '@/stores/theme';

const page = usePage<{ bootstrap: Bootstrap }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();
useThemeStore();
const { tenant, company, hasData, user } = storeToRefs(store);
const activePage = computed(() => pageFromPath(new URL(page.url, window.location.origin).pathname));

watch(
    () => page.props.bootstrap,
    (bootstrap) => bootstrap && store.hydrateBootstrap(bootstrap),
    { immediate: true },
);

watch(hasData, (value) => {
    if (! value) return;

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
});

const logoutProcessing = ref(false);

function logout(): void {
    if (logoutProcessing.value) return;

    logoutProcessing.value = true;
    router.post('/logout', {}, {
        onFinish: () => { logoutProcessing.value = false; },
    });
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
                @toggle="toggleSidebar"
            />

            <main class="h-full min-w-0 flex-1 overflow-y-auto pb-20 lg:pb-0">
                <header class="sticky top-0 z-10 border-b px-4 py-4 backdrop-blur sm:px-6 lg:px-8 border-border bg-card/92">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="font-display text-2xl font-bold ui-text sm:text-3xl">{{ company?.name ?? 'WERO' }}</h1>
                            </div>
                            <p class="mt-2 max-w-3xl text-sm leading-6 ui-subtle">{{ locale.t('header.description') }}</p>
                        </div>

                        <div class="grid gap-2 sm:grid-cols-[minmax(14rem,20rem)_auto_auto_auto_auto] sm:items-center">
                            <GlobalSearch data-tour="search" />
                            <LanguageSwitcher data-tour="language" />
                            <ThemeSwitcher data-tour="theme" />
                            <Link :href="pagePaths.profile" data-tour="profile" class="flex items-center gap-2 rounded-lg border px-2.5 py-1.5 transition hover:bg-muted border-border" :title="user?.email">
                                <Avatar class="size-8 shrink-0">
                                    <AvatarImage v-if="user?.avatar_url" :src="user.avatar_url" alt="" />
                                    <AvatarFallback class="text-xs font-semibold bg-primary text-primary-foreground">{{ user?.name?.[0] ?? '?' }}</AvatarFallback>
                                </Avatar>
                                <span class="hidden max-w-32 truncate text-sm font-medium ui-text sm:block">{{ user?.name }}</span>
                            </Link>
                            <Button variant="primary" type="button" :disabled="logoutProcessing" @click="logout">
                                <LogOut class="h-4 w-4" /> {{ locale.t('auth.logout') }}
                            </Button>
                        </div>
                    </div>
                </header>

                <div class="mx-auto flex w-full max-w-[1480px] flex-col gap-6 px-4 py-5 sm:px-6 lg:px-8" data-tour="content">
                    <EmptyState v-if="!hasData" />
                    <slot v-else />
                </div>
            </main>
        </div>
        <MobileNav :active="activePage" />
        <Toaster position="top-right" rich-colors close-button />
    </div>
</template>
