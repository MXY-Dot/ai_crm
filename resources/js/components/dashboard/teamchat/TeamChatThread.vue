<script setup lang="ts">
import { computed } from 'vue';
import { ArrowLeft } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore } from '@/stores/crmDashboard';
import { useTeamChatStore, type TeamMessage } from '@/stores/teamChat';
import { useLocaleStore } from '@/stores/locale';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import { Marker, MarkerContent } from '@/components/ui/marker';
import { Message, MessageAvatar, MessageContent, MessageFooter, MessageGroup, MessageHeader } from '@/components/ui/message';
import {
    MessageScroller,
    MessageScrollerButton,
    MessageScrollerContent,
    MessageScrollerItem,
    MessageScrollerProvider,
    MessageScrollerViewport,
} from '@/components/ui/message-scroller';
import { Skeleton } from '@/components/ui/skeleton';
import TeamChatComposer from './TeamChatComposer.vue';

defineEmits<{ back: [] }>();

const dashboard = useCrmDashboardStore();
const { user } = storeToRefs(dashboard);
const team = useTeamChatStore();
const locale = useLocaleStore();

function initials(name: string): string {
    return name.slice(0, 2).toUpperCase();
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

// Same day-bucketing + consecutive-same-sender grouping ChatMessages.vue
// uses for customer conversations, ported to TeamMessage's simpler shape
// (sender_id instead of sender_type -- there's no AI/customer/operator split
// here, only "me" vs "them").
type ThreadRow =
    | { kind: 'date'; key: string; label: string }
    | { kind: 'group'; key: string; messages: TeamMessage[] };

const GROUP_WINDOW_MS = 5 * 60 * 1000;

function dateLabel(value: string): string {
    const date = new Date(value);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (date.toDateString() === today.toDateString()) return locale.t('teamChat.today');
    if (date.toDateString() === yesterday.toDateString()) return locale.t('teamChat.yesterday');

    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: date.getFullYear() !== today.getFullYear() ? 'numeric' : undefined }).format(date);
}

const rows = computed<ThreadRow[]>(() => {
    const result: ThreadRow[] = [];
    let lastDate: string | null = null;
    let currentGroup: TeamMessage[] = [];
    let lastSenderId: number | null = null;
    let lastTime = 0;

    function flushGroup(): void {
        if (currentGroup.length) result.push({ kind: 'group', key: `group-${currentGroup[0].id}`, messages: currentGroup });
        currentGroup = [];
    }

    for (const message of team.messages) {
        const day = new Date(message.created_at).toDateString();

        if (day !== lastDate) {
            flushGroup();
            result.push({ kind: 'date', key: `date-${day}`, label: dateLabel(message.created_at) });
            lastDate = day;
            lastSenderId = null;
        }

        const time = new Date(message.created_at).getTime();
        const sameSender = message.sender_id === lastSenderId;
        const withinWindow = time - lastTime < GROUP_WINDOW_MS;

        if (! sameSender || ! withinWindow) flushGroup();

        currentGroup.push(message);
        lastSenderId = message.sender_id;
        lastTime = time;
    }

    flushGroup();

    return result;
});

function isMine(message: TeamMessage): boolean {
    return message.sender_id === user.value?.id;
}
</script>

<template>
    <div v-if="team.activeThread" class="flex min-h-0 flex-1 flex-col">
        <div class="flex items-center gap-2 border-b border-border p-3">
            <Button variant="ghost" size="icon" class="lg:hidden" :aria-label="locale.t('teamChat.backToList')" @click="$emit('back')">
                <ArrowLeft class="h-4 w-4" />
            </Button>
            <Avatar class="size-9 shrink-0">
                <AvatarImage v-if="team.activeThread.user.avatar_url" :src="team.activeThread.user.avatar_url" alt="" />
                <AvatarFallback class="bg-primary/10 font-semibold text-primary">{{ initials(team.activeThread.user.name) }}</AvatarFallback>
            </Avatar>
            <div class="min-w-0">
                <p class="truncate font-display text-sm font-semibold ui-text">{{ team.activeThread.user.name }}</p>
                <p class="truncate text-xs ui-subtle">{{ locale.t(`team.roles.${team.activeThread.user.role}`) }}</p>
            </div>
        </div>

        <div v-if="team.loadingMessages && ! team.messages.length" class="flex flex-1 flex-col justify-end gap-4 p-4">
            <Skeleton v-for="i in 4" :key="i" class="h-12 w-2/3 rounded-2xl" :class="i % 2 ? 'self-start' : 'self-end'" />
        </div>

        <MessageScrollerProvider v-else :key="team.activeUserId ?? undefined" auto-scroll default-scroll-position="end" class="min-h-0 flex-1">
            <MessageScroller class="h-full">
                <MessageScrollerViewport>
                    <MessageScrollerContent class="px-4 py-4">
                        <template v-for="row in rows" :key="row.key">
                            <Marker v-if="row.kind === 'date'" variant="separator" class="my-2">
                                <MarkerContent>{{ row.label }}</MarkerContent>
                            </Marker>

                            <MessageScrollerItem v-else :message-id="String(row.messages[0].id)" scroll-anchor>
                                <MessageGroup>
                                    <Message
                                        v-for="(message, index) in row.messages" :key="message.id"
                                        :align="isMine(message) ? 'end' : 'start'"
                                    >
                                        <MessageAvatar v-if="index === row.messages.length - 1">
                                            <Avatar class="size-7">
                                                <AvatarFallback class="text-xs font-semibold bg-primary/10 text-primary">
                                                    {{ initials(isMine(message) ? (user?.name ?? '?') : team.activeThread.user.name) }}
                                                </AvatarFallback>
                                            </Avatar>
                                        </MessageAvatar>
                                        <MessageAvatar v-else />

                                        <MessageContent>
                                            <MessageHeader v-if="index === 0 && ! isMine(message)">{{ team.activeThread.user.name }}</MessageHeader>
                                            <Bubble :variant="isMine(message) ? 'default' : 'muted'" :align="isMine(message) ? 'end' : 'start'">
                                                <BubbleContent>
                                                    <p class="whitespace-pre-line">{{ message.body }}</p>
                                                </BubbleContent>
                                            </Bubble>
                                            <MessageFooter>{{ formatTime(message.created_at) }}</MessageFooter>
                                        </MessageContent>
                                    </Message>
                                </MessageGroup>
                            </MessageScrollerItem>
                        </template>

                        <p v-if="! rows.length" class="py-10 text-center text-sm ui-subtle">{{ locale.t('teamChat.startConversation') }}</p>
                    </MessageScrollerContent>
                </MessageScrollerViewport>
                <MessageScrollerButton direction="end" />
            </MessageScroller>
        </MessageScrollerProvider>

        <TeamChatComposer />
    </div>

    <div v-else class="grid h-full flex-1 place-items-center text-sm ui-subtle">{{ locale.t('teamChat.selectColleague') }}</div>
</template>
