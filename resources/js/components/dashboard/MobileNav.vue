<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Bot, Inbox, LayoutDashboard, Settings, Users } from '@lucide/vue';
import { pagePaths, type DashboardPage } from '../../lib/pages';
import { canAccessPage } from '../../lib/permissions';
import { useLocaleStore } from '../../stores/locale';

const props = defineProps<{ active: string; role?: string }>();
const locale = useLocaleStore();
const allItems: Array<{ id: DashboardPage; label: string; icon: unknown }> = [
    { id: 'overview', label: 'nav.home', icon: LayoutDashboard },
    { id: 'inbox', label: 'nav.inbox', icon: Inbox },
    { id: 'leads', label: 'nav.crm', icon: Users },
    { id: 'ai', label: 'nav.aiShort', icon: Bot },
    { id: 'settings', label: 'nav.settingsShort', icon: Settings },
];
const items = computed(() => allItems
    .filter((item) => canAccessPage(props.role, item.id))
    .map((item) => ({ ...item, label: locale.t(item.label) })));
</script>

<template>
    <nav class="fixed inset-x-0 bottom-0 z-20 grid border-t px-2 py-2 backdrop-blur lg:hidden border-border bg-background/95" :style="{ gridTemplateColumns: `repeat(${items.length}, minmax(0, 1fr))` }">
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
