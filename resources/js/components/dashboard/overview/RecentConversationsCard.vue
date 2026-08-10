<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Globe2, MessageCircle, MessagesSquare, Send } from '@lucide/vue';
import { Badge } from '../../ui/badge';
import { Avatar, AvatarFallback } from '../../ui/avatar';
import type { Conversation } from '../../../stores/crmDashboard';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { badgeTone } from '../inbox/inboxUi';
import { titleCase } from '../../../lib/format';

const props = defineProps<{ conversations: Conversation[] }>();
const store = useCrmDashboardStore();

const channelIcons: Record<string, unknown> = { telegram: Send, whatsapp: MessageCircle, website: Globe2, web: Globe2 };

const recent = computed(() => [...props.conversations]
    .sort((a, b) => new Date(b.last_message_at ?? 0).getTime() - new Date(a.last_message_at ?? 0).getTime())
    .slice(0, 5));

function initials(name: string): string {
    return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}
</script>

<template>
    <div class="overflow-hidden rounded-xl border lg:col-span-2" style="border-color: var(--border); background: var(--card)">
        <div class="flex items-center justify-between border-b p-5" style="border-color: var(--border)">
            <h3 class="font-display text-base font-semibold ui-text">Последние диалоги</h3>
            <Link href="/inbox" class="text-sm font-medium text-primary hover:underline">Смотреть все</Link>
        </div>
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="text-[11px] font-semibold uppercase tracking-wider ui-subtle">
                    <th class="px-5 py-3 font-medium">Клиент</th>
                    <th class="px-5 py-3 font-medium">Канал</th>
                    <th class="px-5 py-3 font-medium">Тема</th>
                    <th class="px-5 py-3 text-right font-medium">Статус</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="item in recent"
                    :key="item.id"
                    class="cursor-pointer border-t transition hover:bg-muted"
                    style="border-color: var(--border)"
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
                            {{ titleCase(item.channel?.provider) }}
                        </span>
                    </td>
                    <td class="max-w-[220px] truncate px-5 py-3 ui-subtle">{{ item.subject }}</td>
                    <td class="px-5 py-3 text-right">
                        <Badge :tone="badgeTone(item.status)">{{ titleCase(item.status) }}</Badge>
                    </td>
                </tr>
                <tr v-if="! recent.length">
                    <td colspan="4" class="px-5 py-6 text-center text-sm ui-subtle">Пока нет диалогов</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
