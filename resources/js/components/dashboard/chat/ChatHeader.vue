<script setup lang="ts">
import { computed } from 'vue';
import { Info, Menu } from '@lucide/vue';
import { useChatStore } from '@/stores/chat';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

defineEmits<{ 'open-info': []; 'open-sidebar': [] }>();

const chat = useChatStore();

const conversation = computed(() => chat.activeConversation);
const displayName = computed(() => conversation.value?.customer?.name ?? conversation.value?.subject ?? '');
const initial = computed(() => displayName.value[0]?.toUpperCase() ?? '?');

const isRecentlyActive = computed(() => {
    const lastMessageAt = conversation.value?.last_message_at;
    return Boolean(lastMessageAt && Date.now() - new Date(lastMessageAt).getTime() < 5 * 60 * 1000);
});

const typingLabel = computed(() => {
    const typers = chat.activeTypers;
    if (! typers.length) return null;

    return typers.length === 1 ? `${typers[0].name} печатает…` : `${typers.length} операторов печатают…`;
});
</script>

<template>
    <div v-if="conversation" class="flex items-center justify-between gap-3 border-b border-border p-3">
        <div class="flex min-w-0 items-center gap-3">
            <Button variant="ghost" size="icon" class="lg:hidden" aria-label="Диалоги" @click="$emit('open-sidebar')">
                <Menu class="h-4 w-4" />
            </Button>
            <Avatar class="size-9">
                <AvatarFallback class="bg-primary/10 font-semibold text-primary">{{ initial }}</AvatarFallback>
            </Avatar>
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold ui-text">{{ displayName }}</p>
                <p class="truncate text-xs ui-subtle">
                    <span v-if="typingLabel" class="text-primary">{{ typingLabel }}</span>
                    <span v-else>{{ isRecentlyActive ? 'Активен(на) недавно' : conversation.channel?.name ?? '' }}</span>
                </p>
            </div>
            <Badge v-if="conversation.priority === 'high'" tone="amber">Высокий приоритет</Badge>
            <Badge v-if="conversation.assigned_user" tone="blue">Ведёт: {{ conversation.assigned_user.name }}</Badge>
        </div>
        <Button variant="outline" size="icon" aria-label="Информация о диалоге" title="Информация о диалоге" @click="$emit('open-info')">
            <Info class="h-4 w-4" />
        </Button>
    </div>
</template>
