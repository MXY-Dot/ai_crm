<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { ChevronLeft, ChevronRight, LifeBuoy } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { ticketStatusLabels, ticketStatusTone, type TicketStatus } from '@/lib/supportTicket';
import SearchInput from '@/components/dashboard/SearchInput.vue';
import TableFiltersButton from '@/components/dashboard/TableFiltersButton.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';

defineOptions({ layout: SuperAdminLayout });

type TicketRow = {
    id: number;
    subject: string;
    status: TicketStatus;
    company_name: string | null;
    requester: { id: number; name: string; email: string } | null;
    last_message_at: string | null;
    last_message_preview: string | null;
    created_at: string;
};

const rows = ref<TicketRow[]>([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 });
const loading = ref(true);
const search = ref('');
const statusFilter = ref('all');
const page = ref(1);

function formatTime(value: string | null): string {
    if (! value) return '—';
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

async function load(): Promise<void> {
    loading.value = true;
    try {
        const params = new URLSearchParams({ page: String(page.value) });
        if (search.value.trim()) params.set('search', search.value.trim());
        if (statusFilter.value !== 'all') params.set('status', statusFilter.value);

        const response = await apiRequest<{ data: TicketRow[]; meta: typeof meta.value }>(`/api/admin/support/tickets?${params.toString()}`);
        rows.value = response.data;
        meta.value = response.meta;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить обращения');
    } finally {
        loading.value = false;
    }
}

let searchTimer: number | undefined;
watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => { page.value = 1; load(); }, 350);
});
watch(statusFilter, () => { page.value = 1; load(); });
watch(page, load);

onMounted(load);

function openTicket(id: number): void {
    router.visit(`/super-admin/support/${id}`);
}

const activeFilterCount = computed(() => (statusFilter.value !== 'all' ? 1 : 0));

function resetFilters(): void {
    statusFilter.value = 'all';
}
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-display text-xl font-bold ui-text">Техподдержка</h2>
            <p class="mt-1 text-sm ui-subtle">Обращения пользователей всех компаний платформы.</p>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-border bg-card">
        <div class="flex flex-wrap items-center gap-3 border-b p-4 border-border">
            <SearchInput v-model="search" placeholder="Поиск по теме..." />
            <TableFiltersButton :active-count="activeFilterCount" @reset="resetFilters">
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Статус</span>
                    <Select v-model="statusFilter">
                        <SelectTrigger class="h-9 w-full"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Все статусы</SelectItem>
                            <SelectItem v-for="(label, key) in ticketStatusLabels" :key="key" :value="key">{{ label }}</SelectItem>
                        </SelectContent>
                    </Select>
                </label>
            </TableFiltersButton>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem] text-left text-sm">
                <thead class="border-b border-border">
                    <tr class="text-xs font-semibold uppercase ui-subtle">
                        <th class="p-4">Тема</th>
                        <th class="p-4">Компания</th>
                        <th class="p-4">От кого</th>
                        <th class="p-4">Статус</th>
                        <th class="p-4">Обновлено</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <template v-if="loading">
                        <tr v-for="i in 5" :key="`skeleton-${i}`">
                            <td class="p-4" colspan="5"><Skeleton class="h-10 w-full" /></td>
                        </tr>
                    </template>
                    <tr v-else-if="! rows.length">
                        <td class="p-8 text-center text-sm ui-subtle" colspan="5">
                            <LifeBuoy class="mx-auto mb-2 h-6 w-6 ui-subtle" />
                            Обращений не найдено
                        </td>
                    </tr>
                    <tr v-else v-for="row in rows" :key="row.id" class="group cursor-pointer transition hover:bg-muted" @click="openTicket(row.id)">
                        <td class="p-4">
                            <div class="min-w-0 max-w-xs truncate text-sm font-semibold ui-text group-hover:text-primary">{{ row.subject }}</div>
                            <div class="mt-0.5 truncate text-xs ui-subtle">{{ row.last_message_preview }}</div>
                        </td>
                        <td class="p-4 text-sm ui-text">{{ row.company_name ?? '—' }}</td>
                        <td class="p-4">
                            <div class="text-sm ui-text">{{ row.requester?.name ?? '—' }}</div>
                            <div class="text-xs ui-subtle">{{ row.requester?.email }}</div>
                        </td>
                        <td class="p-4"><Badge :tone="ticketStatusTone[row.status]">{{ ticketStatusLabels[row.status] }}</Badge></td>
                        <td class="p-4 font-mono text-xs ui-subtle">{{ formatTime(row.last_message_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t p-4 border-border">
            <span class="text-sm ui-subtle">Показано {{ rows.length ? (meta.current_page - 1) * meta.per_page + 1 : 0 }}–{{ (meta.current_page - 1) * meta.per_page + rows.length }} из {{ meta.total }} обращений</span>
            <div class="flex items-center gap-1">
                <Button size="icon-sm" variant="outline" :disabled="meta.current_page <= 1" @click="page = meta.current_page - 1"><ChevronLeft class="h-4 w-4" /></Button>
                <span class="px-2 font-mono text-sm ui-text">{{ meta.current_page }} / {{ meta.last_page }}</span>
                <Button size="icon-sm" variant="outline" :disabled="meta.current_page >= meta.last_page" @click="page = meta.current_page + 1"><ChevronRight class="h-4 w-4" /></Button>
            </div>
        </div>
    </div>
</template>
