<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Bot, Inbox, LayoutDashboard, Settings, Users } from '@lucide/vue';
import { pagePaths, type DashboardPage } from '../../lib/pages';
import { canAccessPage } from '../../lib/permissions';
import { useLocaleStore } from '../../stores/locale';
import { useUnreadStore } from '../../stores/unread';

const props = defineProps<{ active: string; role?: string }>();
const locale = useLocaleStore();
const unread = useUnreadStore();
const allItems: Array<{ id: DashboardPage; label: string; icon: unknown }> = [
    { id: 'overview', label: 'nav.home', icon: LayoutDashboard },
    { id: 'inbox', label: 'nav.inbox', icon: Inbox },
    { id: 'leads', label: 'nav.crm', icon: Users },
    { id: 'ai', label: 'nav.aiShort', icon: Bot },
    { id: 'settings', label: 'nav.settingsShort', icon: Settings },
];
const items = computed(() => allItems
    .filter((item) => canAccessPage(props.role, item.id))
    .map((item) => ({ ...item, label: locale.t(item.label), badge: item.id === 'inbox' ? unread.total : 0 })));
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
            <span class="relative">
                <component :is="item.icon" class="h-4 w-4" />
                <span
                    v-if="item.badge"
                    class="absolute -right-2 -top-1.5 grid h-3.5 min-w-3.5 place-items-center rounded-full px-0.5 text-[9px] font-bold bg-destructive text-destructive-foreground"
                >{{ item.badge > 9 ? '9+' : item.badge }}</span>
            </span>
            {{ item.label }}
        </Link>
    </nav>
</template>
