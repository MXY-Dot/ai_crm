<script setup lang="ts">
import { ref, watch } from 'vue';
import type { Component } from 'vue';
import { ChevronLeft, ChevronRight, Globe2, Pin, PinOff } from '@lucide/vue';
import { useChatStore } from '@/stores/chat';
import type { ChatConversation } from '@/lib/chat/types';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger } from '@/components/ui/context-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import SearchInput from '@/components/dashboard/SearchInput.vue';
import InstagramIcon from '@/components/icons/InstagramIcon.vue';
import TelegramIcon from '@/components/icons/TelegramIcon.vue';
import WhatsappIcon from '@/components/icons/WhatsappIcon.vue';

const chat = useChatStore();

function togglePin(conversation: ChatConversation): void {
    void chat.togglePin(conversation.id);
}

const searchInput = ref(chat.search);
let searchTimer: number | undefined;
watch(searchInput, (value) => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => chat.setSearch(value), 300);
});

const channelIcons: Record<string, Component> = {
    telegram: TelegramIcon,
    whatsapp: WhatsappIcon,
    instagram: InstagramIcon,
    website: Globe2,
    web: Globe2,
};

function channelIcon(conversation: ChatConversation) {
    return channelIcons[conversation.channel?.provider ?? ''] ?? TelegramIcon;
}

function displayName(conversation: ChatConversation): string {
    return conversation.customer?.name ?? conversation.subject;
}

function initial(conversation: ChatConversation): string {
    return displayName(conversation)[0]?.toUpperCase() ?? '?';
}

function formatTime(value: string | null): string {
    if (! value) return '';
    const date = new Date(value);
    const now = new Date();
    const sameDay = date.toDateString() === now.toDateString();

    return sameDay
        ? new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(date)
        : new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short' }).format(date);
}

/**
 * There's no real presence/online tracking for customers (no such field exists
 * on the Customer model) — this is an honest heuristic, not fake live presence:
 * "active" means the conversation had activity in the last 5 minutes.
 */
function isRecentlyActive(conversation: ChatConversation): boolean {
    if (! conversation.last_message_at) return false;

    return Date.now() - new Date(conversation.last_message_at).getTime() < 5 * 60 * 1000;
}
</script>

<template>
    <div class="flex h-full flex-col border-r border-border">
        <div class="border-b border-border p-3">
            <SearchInput v-model="searchInput" placeholder="Поиск диалогов..." class="w-full" />
        </div>

        <div class="flex-1 overflow-y-auto">
            <div v-if="chat.conversationsLoading" class="space-y-1 p-2">
                <Skeleton v-for="i in 6" :key="i" class="h-16 w-full rounded-lg" />
            </div>

            <p v-else-if="! chat.conversations.length" class="p-6 text-center text-sm ui-subtle">Диалоги не найдены</p>

            <ul v-else>
                <li v-for="conversation in chat.conversations" :key="conversation.id">
                    <ContextMenu>
                        <ContextMenuTrigger as-child>
                            <button
                                type="button"
                                class="group flex w-full items-start gap-3 border-b p-3 text-left transition border-border/60 hover:bg-muted"
                                :class="chat.activeConversationId === conversation.id ? 'bg-muted' : ''"
                                @click="chat.selectConversation(conversation.id)"
                            >
                                <div class="relative shrink-0">
                                    <Avatar class="size-10">
                                        <AvatarFallback class="bg-primary/10 font-semibold text-primary">{{ initial(conversation) }}</AvatarFallback>
                                    </Avatar>
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <span
                                                class="absolute -bottom-0.5 -right-0.5 size-3 rounded-full ring-2 ring-card"
                                                :class="isRecentlyActive(conversation) ? 'bg-emerald-500' : 'bg-muted-foreground/40'"
                                            />
                                        </TooltipTrigger>
                                        <TooltipContent>{{ isRecentlyActive(conversation) ? 'Активен(на)' : 'Не активен(на)' }} · по последнему сообщению</TooltipContent>
                                    </Tooltip>
                                    <span class="absolute -left-1 -top-1 grid size-4 place-items-center rounded-full border bg-card border-border">
                                        <component :is="channelIcon(conversation)" class="size-2.5 ui-subtle" />
                                    </span>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="flex min-w-0 items-center gap-1">
                                            <Pin v-if="conversation.is_pinned" class="size-3 shrink-0 fill-current text-primary" />
                                            <span class="truncate text-sm font-semibold ui-text">{{ displayName(conversation) }}</span>
                                        </span>
                                        <span class="flex shrink-0 items-center gap-1.5">
                                            <Tooltip v-if="conversation.assigned_user">
                                                <TooltipTrigger as-child>
                                                    <span class="grid size-4 place-items-center rounded-full bg-primary/15 text-[9px] font-bold text-primary">
                                                        {{ conversation.assigned_user.name[0]?.toUpperCase() }}
                                                    </span>
                                                </TooltipTrigger>
                                                <TooltipContent>Ведёт: {{ conversation.assigned_user.name }}</TooltipContent>
                                            </Tooltip>
                                            <span class="text-[11px] ui-subtle">{{ formatTime(conversation.last_message_at) }}</span>
                                        </span>
                                    </div>
                                    <div class="mt-0.5 flex items-center justify-between gap-2">
                                        <span class="truncate text-xs ui-subtle">{{ conversation.ai_summary ?? conversation.subject }}</span>
                                        <span class="flex shrink-0 items-center gap-1.5">
                                            <span v-if="isRecentlyActive(conversation)" class="flex items-center gap-1 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
                                                <span class="size-1.5 rounded-full bg-emerald-500" />Активен
                                            </span>
                                            <Badge v-if="conversation.unread_count > 0" tone="green" class="rounded-full px-1.5 py-0 text-[10px]">
                                                {{ conversation.unread_count > 99 ? '99+' : conversation.unread_count }}
                                            </Badge>
                                        </span>
                                    </div>
                                </div>
                            </button>
                        </ContextMenuTrigger>
                        <ContextMenuContent>
                            <ContextMenuItem @select="togglePin(conversation)">
                                <PinOff v-if="conversation.is_pinned" class="h-4 w-4" />
                                <Pin v-else class="h-4 w-4" />
                                {{ conversation.is_pinned ? 'Открепить' : 'Закрепить наверх' }}
                            </ContextMenuItem>
                        </ContextMenuContent>
                    </ContextMenu>
                </li>
            </ul>
        </div>

        <div v-if="chat.conversationsMeta.last_page > 1" class="flex items-center justify-between border-t border-border p-2">
            <Button
                size="icon-sm"
                variant="outline"
                :disabled="chat.conversationsMeta.current_page <= 1"
                @click="chat.loadConversationsPage(chat.conversationsMeta.current_page - 1)"
            >
                <ChevronLeft class="h-4 w-4" />
            </Button>
            <span class="font-mono text-xs ui-subtle">{{ chat.conversationsMeta.current_page }} / {{ chat.conversationsMeta.last_page }}</span>
            <Button
                size="icon-sm"
                variant="outline"
                :disabled="chat.conversationsMeta.current_page >= chat.conversationsMeta.last_page"
                @click="chat.loadConversationsPage(chat.conversationsMeta.current_page + 1)"
            >
                <ChevronRight class="h-4 w-4" />
            </Button>
        </div>
    </div>
</template>
