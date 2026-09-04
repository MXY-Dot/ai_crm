<script setup lang="ts">
import { ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { BarChart3, Blocks, Building2, CreditCard, Crown, Cpu, Languages, LayoutDashboard, Lightbulb, LifeBuoy, LogOut, Mail, Menu, ShieldAlert, Users } from '@lucide/vue';
import LanguageSwitcher from '@/components/dashboard/LanguageSwitcher.vue';
import NotificationBell from '@/components/dashboard/NotificationBell.vue';
import Sidebar, { type SidebarUser } from '@/components/dashboard/Sidebar.vue';
import ThemeSwitcher from '@/components/dashboard/ThemeSwitcher.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Drawer, DrawerContent, DrawerHeader, DrawerTitle } from '@/components/ui/drawer';
import { Toaster } from '@/components/ui/sonner';

defineProps<{ title: string; subtitle?: string }>();

const page = usePage<{ currentUser: SidebarUser }>();
const user = page.props.currentUser;

const navItems = [
    { href: '/super-admin/overview', label: 'Обзор', icon: LayoutDashboard },
    { href: '/super-admin/analytics', label: 'Аналитика', icon: BarChart3 },
    { href: '/super-admin/companies', label: 'Компании', icon: Building2 },
    { href: '/super-admin/vip', label: 'VIP-клиенты', icon: Crown },
    { href: '/super-admin/users', label: 'Пользователи', icon: Users },
    { href: '/super-admin/billing', label: 'Биллинг', icon: CreditCard },
    { href: '/super-admin/llm-providers', label: 'LLM-провайдеры', icon: Cpu },
    { href: '/super-admin/incidents', label: 'Аварийный режим', icon: ShieldAlert },
    { href: '/super-admin/support', label: 'Техподдержка', icon: LifeBuoy },
    { href: '/super-admin/insights', label: 'AI-инсайты', icon: Lightbulb },
    { href: '/super-admin/language-quality', label: 'Языковые датасеты', icon: Languages },
    { href: '/super-admin/business-modules', label: 'Отраслевые модули', icon: Blocks },
    { href: '/super-admin/messaging', label: 'Рассылки', icon: Mail },
];

const mobileMenuOpen = ref(false);
const logoutProcessing = ref(false);

function logout(): void {
    if (logoutProcessing.value) return;
    logoutProcessing.value = true;
    router.post('/logout', {}, { onFinish: () => { logoutProcessing.value = false; } });
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
            <Sidebar
                :collapsed="sidebarCollapsed"
                :nav-items="navItems"
                :active-href="page.url"
                :user="user"
                :logout-processing="logoutProcessing"
                profile-href="/profile"
                logout-label="Выйти"
                workspace-href="/app"
                switcher-mode="admin"
                @toggle="toggleSidebar"
                @logout="logout"
            />

            <main class="h-full min-w-0 flex-1 overflow-y-auto">
                <header class="sticky top-0 z-10 border-b px-4 py-3 backdrop-blur sm:px-6 lg:px-8 lg:py-4 border-border bg-card/92">
                    <div class="hidden lg:flex lg:items-center lg:justify-between">
                        <div>
                            <h1 class="font-display text-2xl font-bold ui-text sm:text-3xl">{{ title }}</h1>
                            <p v-if="subtitle" class="mt-2 max-w-3xl text-sm leading-6 ui-subtle">{{ subtitle }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <LanguageSwitcher />
                            <ThemeSwitcher />
                            <NotificationBell admin />
                        </div>
                    </div>

                    <div class="flex items-center gap-2 lg:hidden">
                        <h1 class="min-w-0 flex-1 truncate font-display text-lg font-bold ui-text">{{ title }}</h1>
                        <button
                            type="button"
                            class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border transition hover:bg-muted border-border"
                            aria-label="Menu"
                            @click="mobileMenuOpen = true"
                        >
                            <Menu class="h-4 w-4 ui-subtle" />
                        </button>
                    </div>
                </header>

                <Drawer v-model:open="mobileMenuOpen" direction="bottom">
                    <DrawerContent class="lg:hidden">
                        <DrawerHeader>
                            <DrawerTitle>WERO — Super Admin</DrawerTitle>
                        </DrawerHeader>
                        <div class="flex flex-col gap-3 p-4">
                            <div class="flex items-center justify-between gap-2">
                                <LanguageSwitcher />
                                <ThemeSwitcher />
                                <NotificationBell admin />
                            </div>
                            <Link href="/profile" class="flex items-center gap-2 rounded-lg border px-3 py-2 transition hover:bg-muted border-border" :title="user?.email" @click="mobileMenuOpen = false">
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
                                @click="mobileMenuOpen = false; logout()"
                            >
                                <LogOut class="h-4 w-4" /> Выйти
                            </button>
                        </div>
                    </DrawerContent>
                </Drawer>

                <div class="mx-auto flex w-full max-w-[1480px] flex-col gap-6 px-4 py-5 sm:px-6 lg:px-8">
                    <slot />
                </div>
            </main>
        </div>
        <Toaster position="top-right" rich-colors close-button />
    </div>
</template>
