<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { ArrowLeft, LifeBuoy } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { ticketMessagesToChat, ticketStatusLabels, ticketStatusTone, type TicketMessage, type TicketStatus } from '@/lib/supportTicket';
import ChatThread from '@/components/dashboard/inbox/ChatThread.vue';
import ReplyComposer from '@/components/dashboard/inbox/ReplyComposer.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';

defineOptions({ layout: SuperAdminLayout });

type TicketDetail = {
    id: number;
    subject: string;
    status: TicketStatus;
    company_name: string | null;
    requester: { id: number; name: string; email: string; avatar_url: string | null } | null;
    created_at: string;
    messages: TicketMessage[];
};

const ticketId = usePage<{ ticketId: number }>().props.ticketId;
const data = ref<TicketDetail | null>(null);
const loading = ref(true);
const busy = ref(false);

function formatTime(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

async function load(): Promise<void> {
    loading.value = true;
    try {
        data.value = await apiRequest<TicketDetail>(`/api/admin/support/tickets/${ticketId}`);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить обращение');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

const replyText = ref('');

async function sendReply(): Promise<void> {
    if (! replyText.value.trim()) return;
    busy.value = true;
    try {
        data.value = await apiRequest<TicketDetail>(`/api/admin/support/tickets/${ticketId}/messages`, {
            method: 'POST',
            body: { body: replyText.value.trim() },
        });
        replyText.value = '';
        toast.success('Ответ отправлен пользователю');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось отправить ответ');
    } finally {
        busy.value = false;
    }
}

async function updateStatus(status: TicketStatus): Promise<void> {
    busy.value = true;
    try {
        await apiRequest(`/api/admin/support/tickets/${ticketId}/status`, { method: 'PATCH', body: { status } });
        if (data.value) data.value.status = status;
        toast.success('Статус обращения обновлён');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось обновить статус');
    } finally {
        busy.value = false;
    }
}

const chatMessages = computed(() => (data.value ? ticketMessagesToChat(data.value.messages) : []));
</script>

<template>
    <div class="flex items-center gap-2">
        <Button variant="ghost" size="icon-sm" @click="router.visit('/super-admin/support')"><ArrowLeft class="h-4 w-4" /></Button>
        <h2 class="font-display text-xl font-bold ui-text">Обращение в техподдержку</h2>
    </div>

    <div v-if="loading" class="space-y-4">
        <Skeleton class="h-24 w-full rounded-xl" />
        <Skeleton class="h-96 w-full rounded-xl" />
    </div>

    <template v-else-if="data">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-4">
            <div class="min-w-0">
                <p class="truncate font-display text-lg font-semibold ui-text">{{ data.subject }}</p>
                <p class="mt-1 text-sm ui-subtle">
                    {{ data.requester?.name ?? 'Неизвестный пользователь' }} ({{ data.requester?.email }}) · {{ data.company_name ?? '—' }}
                </p>
                <p class="mt-0.5 text-xs ui-subtle">Создано {{ formatTime(data.created_at) }}</p>
            </div>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button type="button"><Badge :tone="ticketStatusTone[data.status]">{{ ticketStatusLabels[data.status] }}</Badge></button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem v-for="(label, key) in ticketStatusLabels" :key="key" @select="updateStatus(key as TicketStatus)">{{ label }}</DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <div class="flex min-h-[60vh] flex-col overflow-hidden rounded-xl border border-border bg-card">
            <div v-if="! data.messages.length" class="flex flex-1 flex-col items-center justify-center gap-2 text-sm ui-subtle">
                <LifeBuoy class="h-6 w-6 ui-subtle" />
                Нет сообщений
            </div>
            <ChatThread v-else :messages="chatMessages" />

            <ReplyComposer v-model:body="replyText" :busy="busy" can-reply @send="sendReply" />
        </div>
    </template>
</template>
