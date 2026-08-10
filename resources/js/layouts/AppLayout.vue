<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { storeToRefs } from 'pinia';
import { LogOut } from '@lucide/vue';
import AppSidebar from '@/components/dashboard/AppSidebar.vue';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import GlobalSearch from '@/components/dashboard/GlobalSearch.vue';
import LanguageSwitcher from '@/components/dashboard/LanguageSwitcher.vue';
import MobileNav from '@/components/dashboard/MobileNav.vue';
import ThemeSwitcher from '@/components/dashboard/ThemeSwitcher.vue';
import { Toaster } from '@/components/ui/sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { pageFromPath } from '@/lib/pages';
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

const tenantStatusLabel = computed(() => tenant.value?.status ? locale.t('status.' + tenant.value.status) : locale.t('common.setup'));
</script>

<template>
    <div class="min-h-screen antialiased" style="background: var(--background); color: var(--foreground)">
        <div class="flex min-h-screen">
            <AppSidebar :active="activePage" :tenant-name="tenant?.name ?? locale.t('common.noTenant')" />

            <main class="min-w-0 flex-1 pb-20 lg:pb-0">
                <header class="sticky top-0 z-10 border-b px-4 py-4 backdrop-blur sm:px-6 lg:px-8" style="border-color: var(--border); background: color-mix(in srgb, var(--card) 92%, transparent)">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="font-display text-2xl font-bold ui-text sm:text-3xl">{{ company?.name ?? 'WERO' }}</h1>
                                <Badge tone="green">{{ tenantStatusLabel }}</Badge>
                            </div>
                            <p class="mt-2 max-w-3xl text-sm leading-6 ui-subtle">{{ locale.t('header.description') }}</p>
                        </div>

                        <div class="grid gap-2 sm:grid-cols-[minmax(14rem,20rem)_auto_auto_auto_auto] sm:items-center">
                            <GlobalSearch />
                            <LanguageSwitcher />
                            <ThemeSwitcher />
                            <div class="rounded-lg border px-3 py-2 text-sm ui-muted" style="border-color: var(--border)">
                                <span class="block text-[10px] uppercase tracking-widest ui-subtle">{{ locale.t('auth.signedInAs') }}</span>
                                <span class="block max-w-40 truncate ui-text">{{ user?.email }}</span>
                            </div>
                            <Button variant="primary" type="button" :disabled="logoutProcessing" @click="logout">
                                <LogOut class="h-4 w-4" /> {{ locale.t('auth.logout') }}
                            </Button>
                        </div>
                    </div>
                </header>

                <div class="mx-auto flex w-full max-w-[1480px] flex-col gap-6 px-4 py-5 sm:px-6 lg:px-8">
                    <EmptyState v-if="!hasData" />
                    <slot v-else />
                </div>
            </main>
        </div>
        <MobileNav :active="activePage" />
        <Toaster position="top-right" rich-colors close-button />
    </div>
</template>
