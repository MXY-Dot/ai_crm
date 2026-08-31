<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';
import { apiRequest } from '@/lib/apiClient';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

defineOptions({ layout: AppLayout });

type NotificationItem = {
    id: string;
    type: string;
    title: string;
    body: string | null;
    action_url: string | null;
    priority: 'low' | 'normal' | 'high' | 'urgent';
    read_at: string | null;
    created_at: string;
};

type StatusFilter = 'all' | 'unread' | 'read' | 'critical';
type BucketFilter = 'all' | 'sales' | 'complaints' | 'ai_errors' | 'operators';

const STATUS_TABS: { value: StatusFilter; label: string }[] = [
    { value: 'all', label: 'Все' },
    { value: 'unread', label: 'Новые' },
    { value: 'read', label: 'Прочитанные' },
    { value: 'critical', label: 'Критические' },
];

const BUCKET_TABS: { value: BucketFilter; label: string }[] = [
    { value: 'all', label: 'Все темы' },
    { value: 'sales', label: 'Продажи' },
    { value: 'complaints', label: 'Жалобы' },
    { value: 'ai_errors', label: 'Ошибки AI' },
    { value: 'operators', label: 'Работа операторов' },
];

const PRIORITY_DOT: Record<NotificationItem['priority'], string> = {
    urgent: 'bg-destructive',
    high: 'bg-amber-500',
    normal: 'bg-primary',
    low: 'bg-muted-foreground',
};

const PRIORITY_LABEL: Record<NotificationItem['priority'], string> = {
    urgent: 'Срочно',
    high: 'Важно',
    normal: 'Обычный',
    low: 'Низкий',
};

const status = ref<StatusFilter>('all');
const bucket = ref<BucketFilter>('all');
const items = ref<NotificationItem[]>([]);
const unreadCount = ref(0);
const loading = ref(true);

function formatTime(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

async function load(): Promise<void> {
    loading.value = true;
    try {
        const params = new URLSearchParams({ status: status.value, limit: '100' });
        if (bucket.value !== 'all') params.set('bucket', bucket.value);

        const response = await apiRequest<{ data: NotificationItem[]; unread_count: number }>(`/api/notifications?${params.toString()}`);
        items.value = response.data;
        unreadCount.value = response.unread_count;
    } finally {
        loading.value = false;
    }
}

onMounted(load);
watch([status, bucket], load);

async function markRead(item: NotificationItem): Promise<void> {
    if (! item.read_at) {
        item.read_at = new Date().toISOString();
        unreadCount.value = Math.max(0, unreadCount.value - 1);
        try {
            await apiRequest(`/api/notifications/${item.id}/read`, { method: 'POST' });
        } catch {
            // ignore
        }
    }

    if (item.action_url) router.visit(item.action_url);
}

async function markAllRead(): Promise<void> {
    if (! unreadCount.value) return;
    try {
        await apiRequest('/api/notifications/read-all', { method: 'POST' });
        await load();
    } catch {
        // ignore
    }
}

const emptyLabel = computed(() => {
    if (bucket.value === 'operators') return 'Пока нет уведомлений о работе операторов — этот тип триггеров ещё не подключён.';
    if (status.value === 'unread') return 'Новых уведомлений нет.';
    if (status.value === 'critical') return 'Критических уведомлений нет.';
    return 'Уведомлений нет.';
});
</script>

<template>
    <section class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">Уведомления</h2>
                <p class="mt-1 text-sm ui-subtle">Все события, требующие внимания — по типу и статусу.</p>
            </div>
            <Button v-if="unreadCount > 0" variant="outline" size="sm" @click="markAllRead">Прочитать все ({{ unreadCount }})</Button>
        </div>

        <div class="flex flex-wrap gap-1 rounded-lg border border-border p-1 w-fit">
            <button
                v-for="tab in STATUS_TABS"
                :key="tab.value"
                type="button"
                class="rounded-md px-3 py-1.5 text-xs font-medium transition"
                :class="status === tab.value ? 'bg-secondary text-secondary-foreground' : 'ui-subtle hover:bg-muted'"
                @click="status = tab.value"
            >{{ tab.label }}</button>
        </div>

        <div class="flex flex-wrap gap-1.5">
            <button
                v-for="tab in BUCKET_TABS"
                :key="tab.value"
                type="button"
                class="rounded-full border px-3 py-1 text-xs font-medium transition"
                :class="bucket === tab.value ? 'border-primary bg-primary/10 text-primary' : 'border-border ui-subtle hover:bg-muted'"
                @click="bucket = tab.value"
            >{{ tab.label }}</button>
        </div>

        <Card>
            <div v-if="loading" class="space-y-2 py-2">
                <Skeleton v-for="i in 6" :key="i" class="h-16 rounded-lg" />
            </div>
            <p v-else-if="! items.length" class="py-8 text-center text-sm ui-subtle">{{ emptyLabel }}</p>
            <div v-else class="divide-y divide-border">
                <button
                    v-for="item in items"
                    :key="item.id"
                    type="button"
                    class="flex w-full items-start gap-3 py-3 text-left transition hover:bg-muted"
                    :class="! item.read_at ? 'bg-primary/5' : ''"
                    @click="markRead(item)"
                >
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="! item.read_at ? PRIORITY_DOT[item.priority] : 'bg-transparent'" />
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-1.5">
                            <span class="text-sm font-medium ui-text">{{ item.title }}</span>
                            <span
                                v-if="item.priority === 'urgent' || item.priority === 'high'"
                                class="shrink-0 rounded px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide"
                                :class="item.priority === 'urgent' ? 'bg-destructive/10 text-destructive' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'"
                            >{{ PRIORITY_LABEL[item.priority] }}</span>
                        </span>
                        <span v-if="item.body" class="mt-0.5 block text-xs ui-subtle">{{ item.body }}</span>
                        <span class="mt-1 block text-[11px] ui-subtle">{{ formatTime(item.created_at) }}</span>
                    </span>
                </button>
            </div>
        </Card>
    </section>
</template>
