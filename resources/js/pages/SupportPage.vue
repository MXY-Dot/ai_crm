<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import { LifeBuoy, Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { ticketMessagesToChat, ticketStatusLabels, ticketStatusTone, type TicketMessage, type TicketStatus } from '@/lib/supportTicket';
import ChatThread from '@/components/dashboard/inbox/ChatThread.vue';
import ReplyComposer from '@/components/dashboard/inbox/ReplyComposer.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';

defineOptions({ layout: AppLayout });

type TicketSummary = {
    id: number;
    subject: string;
    status: TicketStatus;
    last_message_at: string | null;
    last_message_preview: string | null;
    created_at: string;
};

type TicketDetail = { id: number; subject: string; status: TicketStatus; created_at: string; messages: TicketMessage[] };

const tickets = ref<TicketSummary[]>([]);
const loading = ref(true);
const selectedId = ref<number | null>(null);
const detail = ref<TicketDetail | null>(null);
const detailLoading = ref(false);

function formatTime(value: string | null): string {
    if (! value) return '—';
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

async function loadTickets(): Promise<void> {
    loading.value = true;
    try {
        const response = await apiRequest<{ data: TicketSummary[] }>('/api/support/tickets');
        tickets.value = response.data;
        if (! selectedId.value && response.data.length) {
            const requested = Number(new URLSearchParams(window.location.search).get('ticket'));
            const target = response.data.find((t) => t.id === requested)?.id ?? response.data[0].id;
            selectTicket(target);
        }
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить обращения');
    } finally {
        loading.value = false;
    }
}

async function selectTicket(id: number): Promise<void> {
    selectedId.value = id;
    detailLoading.value = true;
    try {
        detail.value = await apiRequest<TicketDetail>(`/api/support/tickets/${id}`);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить обращение');
    } finally {
        detailLoading.value = false;
    }
}

onMounted(loadTickets);

const createOpen = ref(false);
const createBusy = ref(false);
const createForm = reactive({ subject: '', body: '' });

async function createTicket(): Promise<void> {
    if (! createForm.subject.trim() || ! createForm.body.trim()) return;
    createBusy.value = true;
    try {
        const ticket = await apiRequest<TicketDetail>('/api/support/tickets', {
            method: 'POST',
            body: { subject: createForm.subject.trim(), body: createForm.body.trim() },
        });
        toast.success('Обращение отправлено в техподдержку');
        createForm.subject = '';
        createForm.body = '';
        createOpen.value = false;
        await loadTickets();
        selectTicket(ticket.id);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось отправить обращение');
    } finally {
        createBusy.value = false;
    }
}

const replyText = ref('');
const replyBusy = ref(false);

async function sendReply(): Promise<void> {
    if (! selectedId.value || ! replyText.value.trim()) return;
    replyBusy.value = true;
    try {
        detail.value = await apiRequest<TicketDetail>(`/api/support/tickets/${selectedId.value}/messages`, {
            method: 'POST',
            body: { body: replyText.value.trim() },
        });
        replyText.value = '';
        await loadTickets();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось отправить сообщение');
    } finally {
        replyBusy.value = false;
    }
}

const selectedSummary = computed(() => tickets.value.find((t) => t.id === selectedId.value) ?? null);
const chatMessages = computed(() => (detail.value ? ticketMessagesToChat(detail.value.messages) : []));
</script>

<template>
    <section class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-display text-2xl font-bold ui-text">Техподдержка</h2>
                <p class="mt-1 text-sm ui-subtle">Задайте вопрос команде WERO — мы ответим прямо здесь.</p>
            </div>
            <Button variant="primary" size="sm" @click="createOpen = true"><Plus class="h-4 w-4" />Новое обращение</Button>
        </div>

        <div class="grid gap-6 lg:grid-cols-[22rem_1fr]">
            <div class="overflow-hidden rounded-xl border border-border bg-card">
                <div v-if="loading" class="space-y-2 p-3">
                    <Skeleton v-for="i in 4" :key="i" class="h-16 w-full rounded-lg" />
                </div>
                <div v-else-if="! tickets.length" class="p-6 text-center text-sm ui-subtle">
                    <LifeBuoy class="mx-auto mb-2 h-6 w-6 ui-subtle" />
                    Обращений пока нет
                </div>
                <div v-else class="max-h-[65vh] divide-y divide-border overflow-y-auto">
                    <button
                        v-for="ticket in tickets"
                        :key="ticket.id"
                        type="button"
                        class="block w-full p-3 text-left transition hover:bg-muted"
                        :class="ticket.id === selectedId ? 'bg-muted' : ''"
                        @click="selectTicket(ticket.id)"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="min-w-0 truncate text-sm font-medium ui-text">{{ ticket.subject }}</span>
                            <Badge :tone="ticketStatusTone[ticket.status]" class="shrink-0">{{ ticketStatusLabels[ticket.status] }}</Badge>
                        </div>
                        <p class="mt-1 truncate text-xs ui-subtle">{{ ticket.last_message_preview }}</p>
                        <p class="mt-1 text-[11px] ui-subtle">{{ formatTime(ticket.last_message_at) }}</p>
                    </button>
                </div>
            </div>

            <div class="flex min-h-[65vh] flex-col overflow-hidden rounded-xl border border-border bg-card">
                <template v-if="selectedSummary">
                    <div class="flex items-center justify-between gap-2 border-b p-4 border-border">
                        <div class="min-w-0">
                            <p class="truncate font-display text-base font-semibold ui-text">{{ selectedSummary.subject }}</p>
                            <p class="text-xs ui-subtle">Создано {{ formatTime(selectedSummary.created_at) }}</p>
                        </div>
                        <Badge :tone="ticketStatusTone[selectedSummary.status]">{{ ticketStatusLabels[selectedSummary.status] }}</Badge>
                    </div>

                    <div v-if="detailLoading" class="flex-1 space-y-3 p-4">
                        <Skeleton class="h-16 w-2/3 rounded-2xl" />
                        <Skeleton class="ml-auto h-16 w-2/3 rounded-2xl" />
                    </div>
                    <ChatThread v-else :messages="chatMessages" />

                    <ReplyComposer v-model:body="replyText" :busy="replyBusy" can-reply @send="sendReply" />
                </template>
                <div v-else class="flex flex-1 flex-col items-center justify-center gap-2 text-center text-sm ui-subtle">
                    <LifeBuoy class="h-6 w-6 ui-subtle" />
                    Выберите обращение слева или создайте новое
                </div>
            </div>
        </div>
    </section>

    <Dialog v-model:open="createOpen">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="createTicket">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><LifeBuoy class="h-4 w-4 text-primary" />Новое обращение в техподдержку</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Тема</span>
                        <Input v-model="createForm.subject" placeholder="Коротко опишите проблему" required />
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Сообщение</span>
                        <Textarea v-model="createForm.body" rows="5" placeholder="Опишите подробнее, что случилось..." required />
                    </label>
                </div>
                <DialogFooter>
                    <Button type="submit" variant="primary" :disabled="createBusy || !createForm.subject.trim() || !createForm.body.trim()">Отправить</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
