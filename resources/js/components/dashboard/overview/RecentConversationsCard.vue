<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Globe2, MessagesSquare } from '@lucide/vue';
import { Badge } from '../../ui/badge';
import { Avatar, AvatarFallback } from '../../ui/avatar';
import DataTable from '../DataTable.vue';
import type { Conversation } from '../../../stores/crmDashboard';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { titleCase } from '../../../lib/format';
import { conversationStatusLabels, conversationStatusTone, sourceLabels } from '../../../lib/statusLabels';
import TelegramIcon from '../../icons/TelegramIcon.vue';
import WhatsappIcon from '../../icons/WhatsappIcon.vue';

const props = defineProps<{ conversations: Conversation[] }>();
const store = useCrmDashboardStore();

const channelIcons: Record<string, unknown> = { telegram: TelegramIcon, whatsapp: WhatsappIcon, website: Globe2, web: Globe2 };

const recent = computed(() => [...props.conversations]
    .sort((a, b) => new Date(b.last_message_at ?? 0).getTime() - new Date(a.last_message_at ?? 0).getTime())
    .slice(0, 5));

function initials(name: string): string {
    return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}
</script>

<template>
    <DataTable class="lg:col-span-2" :row-count="recent.length" :column-count="4" empty-message="Пока нет диалогов" min-width="">
        <template #toolbar>
            <h3 class="font-display text-base font-semibold ui-text">Последние диалоги</h3>
            <Link href="/inbox" class="text-sm font-medium text-primary hover:underline">Смотреть все</Link>
        </template>

        <template #thead>
            <th class="px-5 py-3">Клиент</th>
            <th class="px-5 py-3">Канал</th>
            <th class="px-5 py-3">Тема</th>
            <th class="px-5 py-3 text-right">Статус</th>
        </template>

        <tr
            v-for="item in recent"
            :key="item.id"
            class="cursor-pointer transition hover:bg-muted"
            @click="store.openConversation(item.id)"
        >
            <td class="px-5 py-3">
                <div class="flex items-center gap-3">
                    <Avatar class="size-8"><AvatarFallback class="text-xs">{{ initials(item.customer?.name ?? '?') }}</AvatarFallback></Avatar>
                    <span class="font-medium ui-text">{{ item.customer?.name ?? 'Без имени' }}</span>
                </div>
            </td>
            <td class="px-5 py-3 ui-subtle">
                <span class="inline-flex items-center gap-1">
                    <component :is="channelIcons[item.channel?.provider ?? ''] ?? MessagesSquare" class="h-4 w-4" />
                    {{ item.channel?.provider ? (sourceLabels[item.channel.provider] ?? item.channel.provider) : '—' }}
                </span>
            </td>
            <td class="max-w-[220px] truncate px-5 py-3 ui-subtle">{{ item.subject }}</td>
            <td class="px-5 py-3 text-right">
                <Badge :tone="conversationStatusTone[item.status] ?? 'neutral'">{{ conversationStatusLabels[item.status] ?? titleCase(item.status) }}</Badge>
            </td>
        </tr>
    </DataTable>
</template>
