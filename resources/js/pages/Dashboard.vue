<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { LogOut, Smartphone } from '@lucide/vue';
import LandingScreen from '../components/auth/LandingScreen.vue';
import LoginScreen from '../components/auth/LoginScreen.vue';
import AiWorkspace from '../components/dashboard/AiWorkspace.vue';
import AppSidebar from '../components/dashboard/AppSidebar.vue';
import EmptyState from '../components/dashboard/EmptyState.vue';
import InboxWorkspace from '../components/dashboard/InboxWorkspace.vue';
import LanguageSwitcher from '../components/dashboard/LanguageSwitcher.vue';
import ThemeSwitcher from '../components/dashboard/ThemeSwitcher.vue';
import MobileNav from '../components/dashboard/MobileNav.vue';
import { Badge } from '../components/ui/badge';
import { Button } from '../components/ui/button';
import AnalyticsPage from './AnalyticsPage.vue';
import CrmPage from './CrmPage.vue';
import CustomerProfilePage from './CustomerProfilePage.vue';
import IntegrationsPage from './IntegrationsPage.vue';
import KnowledgePage from './KnowledgePage.vue';
import LeadsPage from './LeadsPage.vue';
import OverviewPage from './OverviewPage.vue';
import SettingsPage from './SettingsPage.vue';
import { authPost } from '../lib/authClient';
import { type Bootstrap, useCrmDashboardStore } from '../stores/crmDashboard';
import { type DashboardPage } from '../lib/pages';
import { useLocaleStore } from '../stores/locale';
import { useThemeStore } from '../stores/theme';

const props = defineProps<{ bootstrap: Bootstrap; page: DashboardPage }>();
const store = useCrmDashboardStore();
watch(
    () => [props.bootstrap, props.page] as const,
    ([bootstrap, page]) => store.hydrateBootstrap(bootstrap, page),
    { immediate: true },
);
const locale = useLocaleStore();
useThemeStore();
const { tenant, company, activeView, apiHeader, hasData, user, toasts } = storeToRefs(store);
const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

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

async function logout(): Promise<void> {
    if (logoutProcessing.value) return;

    logoutProcessing.value = true;
    try {
        await authPost('/logout');
        window.location.assign('/login');
    } finally {
        logoutProcessing.value = false;
    }
}
const tenantStatusLabel = computed(() => tenant.value?.status ? locale.t('status.' + tenant.value.status) : locale.t('common.setup'));

const currentPage = computed(() => {
    if (activeView.value === 'inbox') return InboxWorkspace;
    if (activeView.value === 'leads') return LeadsPage;
    if (activeView.value === 'customers') return CustomerProfilePage;
    if (activeView.value === 'crm') return CrmPage;
    if (activeView.value === 'ai') return AiWorkspace;
    if (activeView.value === 'knowledge') return KnowledgePage;
    if (activeView.value === 'analytics') return AnalyticsPage;
    if (activeView.value === 'integrations') return IntegrationsPage;
    if (activeView.value === 'settings') return SettingsPage;

    return OverviewPage;
});

</script>

<template>
    <LandingScreen v-if="store.authMode === 'landing'" />
    <LoginScreen v-else-if="store.authMode === 'login' || store.authMode === 'register'" />
    <div v-else class="min-h-screen antialiased" style="background: var(--background); color: var(--foreground)">
        <div class="flex min-h-screen">
            <AppSidebar
                :active="activeView"
                :tenant-name="tenant?.name ?? locale.t('common.noTenant')"
            />

            <main class="min-w-0 flex-1 pb-20 lg:pb-0">
                <header class="sticky top-0 z-10 border-b px-4 py-4 backdrop-blur sm:px-6 lg:px-8" style="border-color: var(--border); background: color-mix(in srgb, var(--background) 92%, transparent)">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge tone="green">{{ tenantStatusLabel }}</Badge>
                                <Badge>{{ company?.industry ?? locale.t('common.multiIndustry') }}</Badge>
                                <Badge tone="blue"><Smartphone class="mr-1 h-3 w-3" /> {{ locale.t('common.mobileReady') }}</Badge>
                            </div>
                            <h1 class="mt-3 text-2xl font-semibold ui-text sm:text-3xl">{{ company?.name ?? 'Gravity AI CRM' }}</h1>
                            <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-400">{{ locale.t('header.description') }}</p>
                        </div>

                        <div class="grid gap-2 sm:grid-cols-[1fr_auto_auto_auto_auto] sm:items-center">
                            <div class="rounded-md border px-4 py-3 text-sm ui-muted">
                                <span class="block text-xs uppercase tracking-wide text-zinc-500">{{ locale.t('common.apiTenantHeader') }}</span>
                                <code class="mt-1 block text-emerald-200">{{ apiHeader }}</code>
                            </div>
                            <LanguageSwitcher />
                            <ThemeSwitcher />
                            <div class="rounded-md border px-3 py-2 text-sm ui-muted">
                                <span class="block text-xs text-zinc-500">{{ locale.t('auth.signedInAs') }}</span>
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
                    <component :is="currentPage" v-else />
                </div>
            </main>
        </div>

        <MobileNav :active="activeView" />
        <div class="fixed right-4 top-4 z-50 flex w-[min(360px,calc(100vw-32px))] flex-col gap-2">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="rounded-md border px-4 py-3 text-sm shadow-xl backdrop-blur"
                :class="toast.tone === 'error' ? 'border-red-400/40 bg-red-950/90 text-red-50' : 'border-emerald-400/40 bg-zinc-950/95 text-emerald-50'"
            >
                <div class="flex items-start justify-between gap-3">
                    <span>{{ toast.message }}</span>
                    <button class="text-lg leading-none text-zinc-400 hover:text-white" type="button" @click="store.dismissToast(toast.id)">x</button>
                </div>
            </div>
        </div>
    </div>
</template>