<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Bot, Inbox, LayoutDashboard, Settings, Users } from '@lucide/vue';
import { pagePaths, type DashboardPage } from '../../lib/pages';
import { useLocaleStore } from '../../stores/locale';

const locale = useLocaleStore();
const items = computed<Array<{ id: DashboardPage; label: string; icon: unknown }>>(() => [
    { id: 'overview', label: locale.t('nav.home'), icon: LayoutDashboard },
    { id: 'inbox', label: locale.t('nav.inbox'), icon: Inbox },
    { id: 'crm', label: locale.t('nav.crm'), icon: Users },
    { id: 'ai', label: locale.t('nav.aiShort'), icon: Bot },
    { id: 'settings', label: locale.t('nav.settingsShort'), icon: Settings },
]);

defineProps<{ active: string }>();
</script>

<template>
    <nav class="fixed inset-x-0 bottom-0 z-20 grid grid-cols-5 border-t px-2 py-2 backdrop-blur lg:hidden" style="border-color: var(--border); background: color-mix(in srgb, var(--background) 95%, transparent)">
        <Link
            v-for="item in items"
            :key="item.id"
            :href="pagePaths[item.id]"
            class="flex h-12 flex-col items-center justify-center gap-1 rounded-lg text-xs font-medium"
            :class="active === item.id ? 'bg-[var(--card)] text-[var(--foreground)] shadow-sm' : 'ui-subtle'"
        >
            <component :is="item.icon" class="h-4 w-4" />
            {{ item.label }}
        </Link>
    </nav>
</template>