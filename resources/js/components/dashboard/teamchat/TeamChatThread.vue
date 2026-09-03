<script setup lang="ts">
import { computed, ref } from 'vue';
import { ArrowLeft } from '@lucide/vue';
import { useTeamChatStore, type TeamMessage } from '@/stores/teamChat';
import { useLocaleStore } from '@/stores/locale';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Marker, MarkerContent } from '@/components/ui/marker';
import { MessageGroup } from '@/components/ui/message';
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
import TeamMessageBubble from './TeamMessageBubble.vue';
import TeamProfileDrawer from './TeamProfileDrawer.vue';

defineEmits<{ back: [] }>();

const team = useTeamChatStore();
const locale = useLocaleStore();
const profileOpen = ref(false);

function initials(name: string): string {
    return name.slice(0, 2).toUpperCase();
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
</script>

<template>
    <div v-if="team.activeThread" class="flex min-h-0 flex-1 flex-col">
        <div class="flex items-center gap-2 border-b border-border p-3">
            <Button variant="ghost" size="icon" class="lg:hidden" :aria-label="locale.t('teamChat.backToList')" @click="$emit('back')">
                <ArrowLeft class="h-4 w-4" />
            </Button>
            <button
                type="button"
                class="flex min-w-0 flex-1 items-center gap-3 rounded-lg px-1 py-0.5 text-left transition hover:bg-muted"
                :title="locale.t('teamChat.viewProfile')"
                @click="profileOpen = true"
            >
                <Avatar class="size-9 shrink-0">
                    <AvatarImage v-if="team.activeThread.user.avatar_url" :src="team.activeThread.user.avatar_url" alt="" />
                    <AvatarFallback class="bg-primary/10 font-semibold text-primary">{{ initials(team.activeThread.user.name) }}</AvatarFallback>
                </Avatar>
                <div class="min-w-0">
                    <p class="truncate font-display text-sm font-semibold ui-text">{{ team.activeThread.user.name }}</p>
                    <p class="truncate text-xs ui-subtle">{{ locale.t(`team.roles.${team.activeThread.user.role}`) }}</p>
                </div>
            </button>
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
                                    <TeamMessageBubble
                                        v-for="(message, index) in row.messages" :key="message.id"
                                        :message="message"
                                        :show-header="index === 0"
                                        :show-avatar="index === row.messages.length - 1"
                                        :other-name="team.activeThread.user.name"
                                    />
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

        <TeamProfileDrawer v-model:open="profileOpen" :user="team.activeThread.user" />
    </div>

    <div v-else class="grid h-full flex-1 place-items-center text-sm ui-subtle">{{ locale.t('teamChat.selectColleague') }}</div>
</template>
