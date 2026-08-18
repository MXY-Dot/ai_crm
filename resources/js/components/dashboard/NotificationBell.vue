<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Bell, MessageCircle, Megaphone } from '@lucide/vue';
import { apiRequest } from '@/lib/apiClient';
import { pagePaths } from '@/lib/pages';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useUnreadStore } from '@/stores/unread';

const props = defineProps<{ admin?: boolean }>();
const unread = useUnreadStore();

type NotificationItem = {
    id: string;
    type: string;
    title: string;
    body: string | null;
    action_url: string | null;
    read_at: string | null;
    created_at: string;
};

const items = ref<NotificationItem[]>([]);
const unreadCount = ref(0);
const open = ref(false);
const loaded = ref(false);
let knownIds = new Set<string>();

function formatTime(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

async function load(): Promise<void> {
    try {
        const response = await apiRequest<{ data: NotificationItem[]; unread_count: number }>('/api/notifications');

        if (loaded.value) {
            const fresh = response.data.filter((n) => ! n.read_at && ! knownIds.has(n.id));
            for (const n of fresh) {
                toast.message(n.title, { description: n.body ?? undefined });
            }
        }

        items.value = response.data;
        unreadCount.value = response.unread_count;
        knownIds = new Set(response.data.map((n) => n.id));
        loaded.value = true;
    } catch {
        // silent: polling failure shouldn't interrupt the page
    }
}

let timer: number | undefined;
onMounted(() => {
    load();
    timer = window.setInterval(load, 20000);
});
onBeforeUnmount(() => {
    if (timer) window.clearInterval(timer);
});

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

    if (item.action_url) {
        open.value = false;
        router.visit(item.action_url);
    }
}

async function markAllRead(): Promise<void> {
    if (! unreadCount.value) return;
    items.value.forEach((n) => { n.read_at = n.read_at ?? new Date().toISOString(); });
    unreadCount.value = 0;
    try {
        await apiRequest('/api/notifications/read-all', { method: 'POST' });
    } catch {
        // ignore
    }
}

const combinedUnread = computed(() => unreadCount.value + unread.total);
const badgeLabel = computed(() => (combinedUnread.value > 9 ? '9+' : String(combinedUnread.value)));

function goToInbox(): void {
    open.value = false;
    router.visit(pagePaths.inbox);
}

const announceOpen = ref(false);
const announceBusy = ref(false);
const announceForm = reactive({ title: '', body: '' });

async function sendAnnouncement(): Promise<void> {
    if (! announceForm.title.trim()) return;
    announceBusy.value = true;
    try {
        const result = await apiRequest<{ notified: number }>('/api/admin/announcements', {
            method: 'POST',
            body: { title: announceForm.title.trim(), body: announceForm.body.trim() || undefined },
        });
        toast.success(`Объявление отправлено (${result.notified} получателей)`);
        announceForm.title = '';
        announceForm.body = '';
        announceOpen.value = false;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось отправить объявление');
    } finally {
        announceBusy.value = false;
    }
}
</script>

<template>
    <DropdownMenu v-model:open="open">
        <DropdownMenuTrigger as-child>
            <Button size="icon" variant="secondary" class="relative" aria-label="Уведомления">
                <Bell class="h-4 w-4" />
                <span
                    v-if="combinedUnread > 0"
                    class="absolute -right-1 -top-1 grid h-4 min-w-4 place-items-center rounded-full px-1 text-[10px] font-bold bg-primary text-primary-foreground"
                >{{ badgeLabel }}</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-[calc(100vw-2rem)] max-w-96 p-0">
            <div class="flex items-center justify-between border-b p-3 border-border">
                <span class="text-sm font-semibold ui-text">Уведомления</span>
                <button
                    v-if="unreadCount > 0"
                    type="button"
                    class="text-xs font-medium text-primary hover:underline"
                    @click="markAllRead"
                >Прочитать все</button>
            </div>

            <div class="max-h-96 overflow-y-auto">
                <button
                    v-if="unread.total > 0"
                    type="button"
                    class="flex w-full items-start gap-2.5 border-b p-3 text-left transition bg-primary/5 border-border hover:bg-primary/10"
                    @click="goToInbox"
                >
                    <MessageCircle class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium ui-text">{{ unread.total }} {{ unread.total === 1 ? 'непрочитанное сообщение' : 'непрочитанных сообщений' }}</span>
                        <span class="mt-0.5 block text-xs ui-subtle">В чатах — нажмите, чтобы открыть</span>
                    </span>
                </button>

                <button
                    v-for="item in items"
                    :key="item.id"
                    type="button"
                    class="flex w-full items-start gap-2.5 border-b p-3 text-left transition last:border-b-0 border-border hover:bg-muted"
                    :class="! item.read_at ? 'bg-primary/5' : ''"
                    @click="markRead(item)"
                >
                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full" :class="! item.read_at ? 'bg-primary' : 'bg-transparent'" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium ui-text">{{ item.title }}</span>
                        <span v-if="item.body" class="mt-0.5 block truncate text-xs ui-subtle">{{ item.body }}</span>
                        <span class="mt-1 block text-[11px] ui-subtle">{{ formatTime(item.created_at) }}</span>
                    </span>
                </button>

                <p v-if="loaded && ! items.length" class="p-6 text-center text-sm ui-subtle">Нет уведомлений</p>
            </div>

            <div v-if="props.admin" class="border-t p-2 border-border">
                <button
                    type="button"
                    class="flex w-full items-center justify-center gap-2 rounded-lg p-2 text-xs font-medium transition hover:bg-muted ui-subtle"
                    @click="open = false; announceOpen = true"
                >
                    <Megaphone class="h-3.5 w-3.5" /> Отправить объявление платформы
                </button>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>

    <Dialog v-model:open="announceOpen">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="sendAnnouncement">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><Megaphone class="h-4 w-4 text-primary" />Объявление платформы</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Заголовок</span>
                        <Input v-model="announceForm.title" placeholder="Например: Обновление платформы" required />
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Текст (необязательно)</span>
                        <Textarea v-model="announceForm.body" rows="4" placeholder="Что изменилось..." />
                    </label>
                    <p class="text-xs ui-subtle">Уведомление получат все пользователи всех компаний платформы.</p>
                </div>
                <DialogFooter>
                    <Button type="submit" variant="primary" :disabled="announceBusy || ! announceForm.title.trim()">Отправить</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
