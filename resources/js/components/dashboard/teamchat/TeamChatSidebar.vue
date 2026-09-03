<script setup lang="ts">
import { ref, watch } from 'vue';
import { useTeamChatStore } from '@/stores/teamChat';
import { useLocaleStore } from '@/stores/locale';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import SearchInput from '@/components/dashboard/SearchInput.vue';

const team = useTeamChatStore();
const locale = useLocaleStore();

// Mirrors ChatSidebar.vue's own search box exactly (same debounce, same
// component) -- team.threads is small enough to just filter client-side,
// no need for the customer sidebar's server-side search.
const searchInput = ref('');
const filtered = ref(team.threads);
watch([() => team.threads, searchInput], () => {
    const needle = searchInput.value.trim().toLowerCase();
    filtered.value = ! needle
        ? team.threads
        : team.threads.filter((thread) => thread.user.name.toLowerCase().includes(needle) || thread.user.email.toLowerCase().includes(needle));
}, { immediate: true });

function initials(name: string): string {
    return name.slice(0, 2).toUpperCase();
}

// Real presence (last_seen_at, updated on every authenticated request via
// TrackLastSeen middleware) -- unlike ChatSidebar's customer list, which has
// to fall back to "message activity in the last 5 min" as a heuristic since
// customers have no such field, team members are real users we already track.
const ONLINE_THRESHOLD_MS = 120_000;

function isOnline(lastSeenAt: string | null): boolean {
    if (! lastSeenAt) return false;
    return Date.now() - new Date(lastSeenAt).getTime() < ONLINE_THRESHOLD_MS;
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
</script>

<template>
    <div class="flex h-full flex-col border-r border-border">
        <div class="border-b border-border p-3">
            <SearchInput v-model="searchInput" :placeholder="locale.t('teamChat.searchPlaceholder')" class="w-full" />
        </div>

        <div class="flex-1 overflow-y-auto">
            <div v-if="team.loadingThreads && ! team.threads.length" class="space-y-1 p-2">
                <Skeleton v-for="i in 5" :key="i" class="h-16 w-full rounded-lg" />
            </div>

            <p v-else-if="! filtered.length" class="p-6 text-center text-sm ui-subtle">{{ locale.t('teamChat.noColleagues') }}</p>

            <ul class="space-y-0.5 p-2">
                <li v-for="thread in filtered" :key="thread.user.id">
                    <button
                        type="button"
                        class="group flex w-full items-start gap-3 rounded-xl border-l-2 border-transparent p-3 text-left transition-all hover:-translate-y-0.5 hover:bg-muted hover:shadow-sm"
                        :class="team.activeUserId === thread.user.id ? 'border-l-primary bg-primary/5' : ''"
                        @click="team.selectThread(thread.user.id)"
                    >
                        <div class="relative shrink-0">
                            <Avatar class="size-10 ring-2 ring-border ring-offset-2 ring-offset-card">
                                <AvatarImage v-if="thread.user.avatar_url" :src="thread.user.avatar_url" alt="" />
                                <AvatarFallback class="bg-primary/10 font-semibold text-primary">{{ initials(thread.user.name) }}</AvatarFallback>
                            </Avatar>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <span
                                        class="absolute -bottom-0.5 -right-0.5 size-3 rounded-full ring-2 ring-card"
                                        :class="isOnline(thread.user.last_seen_at) ? 'bg-emerald-500' : 'bg-muted-foreground/40'"
                                    />
                                </TooltipTrigger>
                                <TooltipContent>{{ isOnline(thread.user.last_seen_at) ? locale.t('team.online') : locale.t('team.neverLoggedIn') }}</TooltipContent>
                            </Tooltip>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-sm font-semibold ui-text">{{ thread.user.name }}</span>
                                <span class="shrink-0 text-[11px] ui-subtle">{{ formatTime(thread.last_message_at) }}</span>
                            </div>
                            <div class="mt-0.5 flex items-center justify-between gap-2">
                                <span class="truncate text-xs ui-subtle">{{ thread.last_message ?? locale.t('teamChat.noMessages') }}</span>
                                <Badge v-if="thread.unread_count > 0" tone="green" class="shrink-0 rounded-full px-1.5 py-0 text-[10px]">
                                    {{ thread.unread_count > 99 ? '99+' : thread.unread_count }}
                                </Badge>
                            </div>
                        </div>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
