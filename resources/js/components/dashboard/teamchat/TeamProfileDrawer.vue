<script setup lang="ts">
// Mirrors ConversationInfo.vue's own drawer layout (avatar, name, contact
// block) -- the customer-chat equivalent of "view profile" opened from
// ChatHeader's own avatar click.
import { computed } from 'vue';
import { Mail, Phone, Shield } from '@lucide/vue';
import type { TeamThreadUser } from '@/stores/teamChat';
import { useLocaleStore } from '@/stores/locale';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Drawer, DrawerContent, DrawerHeader, DrawerTitle } from '@/components/ui/drawer';

const props = defineProps<{ open: boolean; user: TeamThreadUser }>();
defineEmits<{ 'update:open': [boolean] }>();
const locale = useLocaleStore();

function initials(name: string): string {
    return name.slice(0, 2).toUpperCase();
}

const ONLINE_THRESHOLD_MS = 120_000;
const isOnline = computed(() => {
    if (! props.user.last_seen_at) return false;
    return Date.now() - new Date(props.user.last_seen_at).getTime() < ONLINE_THRESHOLD_MS;
});
</script>

<template>
    <Drawer :open="open" direction="right" @update:open="(v) => $emit('update:open', v)">
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>{{ locale.t('teamChat.profileTitle') }}</DrawerTitle>
            </DrawerHeader>
            <div class="flex h-full flex-col overflow-y-auto">
                <div class="flex flex-col items-center border-b p-5 text-center border-border">
                    <Avatar class="mb-3 size-16">
                        <AvatarImage v-if="user.avatar_url" :src="user.avatar_url" alt="" />
                        <AvatarFallback class="text-xl">{{ initials(user.name) }}</AvatarFallback>
                    </Avatar>
                    <h2 class="font-display text-base font-semibold ui-text">{{ user.name }}</h2>
                    <Badge :tone="isOnline ? 'green' : 'neutral'" class="mt-2">{{ isOnline ? locale.t('team.online') : locale.t('team.neverLoggedIn') }}</Badge>
                </div>

                <div class="space-y-4 px-4 py-4">
                    <div>
                        <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider ui-subtle">{{ locale.t('teamChat.contacts') }}</h4>
                        <div class="space-y-2 text-sm">
                            <p class="flex items-center gap-2 ui-text"><Shield class="h-4 w-4 ui-subtle" />{{ locale.t(`team.roles.${user.role}`) }}</p>
                            <p class="flex items-center gap-2 ui-text"><Mail class="h-4 w-4 ui-subtle" />{{ user.email }}</p>
                            <p v-if="user.phone" class="flex items-center gap-2 ui-text"><Phone class="h-4 w-4 ui-subtle" />{{ user.phone }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </DrawerContent>
    </Drawer>
</template>
