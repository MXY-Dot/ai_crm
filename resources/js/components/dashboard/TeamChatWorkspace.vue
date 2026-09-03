<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { ArrowLeft, Send } from '@lucide/vue';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useTeamChatStore } from '../../stores/teamChat';
import { useLocaleStore } from '../../stores/locale';
import { Avatar, AvatarFallback, AvatarImage } from '../ui/avatar';
import { Badge } from '../ui/badge';
import { Button } from '../ui/button';
import { Input } from '../ui/input';

const dashboard = useCrmDashboardStore();
const { user } = storeToRefs(dashboard);
const team = useTeamChatStore();
const locale = useLocaleStore();

const draft = ref('');
const scrollEl = ref<HTMLElement | null>(null);

function initials(name: string): string {
    return name.slice(0, 2).toUpperCase();
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function scrollToBottom(): void {
    nextTick(() => {
        if (scrollEl.value) scrollEl.value.scrollTop = scrollEl.value.scrollHeight;
    });
}

watch(() => team.messages.length, scrollToBottom);
watch(() => team.activeUserId, scrollToBottom);

async function submit(): Promise<void> {
    if (! draft.value.trim()) return;
    const body = draft.value;
    draft.value = '';
    await team.send(body);
}

// The thread LIST's own polling is owned by InboxPage.vue (keeps the tab's
// unread badge live even while this component isn't mounted) -- this only
// needs to stop the active-thread MESSAGE polling started when a colleague
// was picked, so it doesn't keep hitting the API after switching away.
onBeforeUnmount(() => team.unselectThread());

const isEmpty = computed(() => ! team.loadingThreads && ! team.threads.length);
</script>

<template>
    <section class="flex h-[calc(100vh-200px)] min-h-[620px] overflow-hidden rounded-xl border border-border bg-card">
        <div class="w-full shrink-0 flex-col border-r border-border lg:flex lg:w-80" :class="team.activeUserId ? 'hidden lg:flex' : 'flex'">
            <div class="border-b border-border p-3">
                <p class="text-sm font-semibold ui-text">{{ locale.t('teamChat.title') }}</p>
                <p class="mt-0.5 text-xs ui-subtle">{{ locale.t('teamChat.subtitle') }}</p>
            </div>
            <div class="flex-1 overflow-y-auto">
                <button
                    v-for="thread in team.threads" :key="thread.user.id"
                    type="button"
                    class="flex w-full items-center gap-3 border-b border-border p-3 text-left transition hover:bg-muted"
                    :class="team.activeUserId === thread.user.id ? 'bg-muted' : ''"
                    @click="team.selectThread(thread.user.id)"
                >
                    <Avatar class="size-9 shrink-0">
                        <AvatarImage v-if="thread.user.avatar_url" :src="thread.user.avatar_url" alt="" />
                        <AvatarFallback class="text-xs font-semibold bg-accent text-accent-foreground">{{ initials(thread.user.name) }}</AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium ui-text">{{ thread.user.name }}</p>
                        <p class="truncate text-xs ui-subtle">{{ thread.last_message ?? locale.t('teamChat.noMessages') }}</p>
                    </div>
                    <Badge v-if="thread.unread_count" tone="green">{{ thread.unread_count }}</Badge>
                </button>
                <p v-if="isEmpty" class="p-6 text-center text-sm ui-subtle">{{ locale.t('teamChat.noColleagues') }}</p>
            </div>
        </div>

        <div class="flex min-h-0 min-w-0 flex-1 flex-col" :class="team.activeUserId ? 'flex' : 'hidden lg:flex'">
            <template v-if="team.activeThread">
                <div class="flex items-center gap-2 border-b border-border p-3">
                    <button type="button" class="rounded-lg p-1.5 hover:bg-muted lg:hidden" @click="team.unselectThread">
                        <ArrowLeft class="h-4 w-4 ui-subtle" />
                    </button>
                    <Avatar class="size-8 shrink-0">
                        <AvatarImage v-if="team.activeThread.user.avatar_url" :src="team.activeThread.user.avatar_url" alt="" />
                        <AvatarFallback class="text-xs font-semibold bg-accent text-accent-foreground">{{ initials(team.activeThread.user.name) }}</AvatarFallback>
                    </Avatar>
                    <p class="text-sm font-semibold ui-text">{{ team.activeThread.user.name }}</p>
                </div>

                <div ref="scrollEl" class="flex-1 space-y-2 overflow-y-auto p-4">
                    <div v-for="msg in team.messages" :key="msg.id" class="flex" :class="msg.sender_id === user?.id ? 'justify-end' : 'justify-start'">
                        <div
                            class="max-w-[70%] rounded-lg px-3 py-2 text-sm"
                            :class="msg.sender_id === user?.id ? 'bg-primary text-primary-foreground' : 'bg-muted ui-text'"
                        >
                            <p class="whitespace-pre-wrap break-words">{{ msg.body }}</p>
                            <p class="mt-1 text-[10px] tabular-nums opacity-70">{{ formatTime(msg.created_at) }}</p>
                        </div>
                    </div>
                    <p v-if="! team.loadingMessages && ! team.messages.length" class="text-center text-sm ui-subtle">{{ locale.t('teamChat.startConversation') }}</p>
                </div>

                <form class="flex items-center gap-2 border-t border-border p-3" @submit.prevent="submit">
                    <Input v-model="draft" class="flex-1" :placeholder="locale.t('teamChat.placeholder')" />
                    <Button type="submit" size="icon" :disabled="! draft.trim() || team.sending">
                        <Send class="h-4 w-4" />
                    </Button>
                </form>
            </template>

            <div v-else class="grid h-full place-items-center text-sm ui-subtle">{{ locale.t('teamChat.selectColleague') }}</div>
        </div>
    </section>
</template>
