<script setup lang="ts">
import { computed, ref } from 'vue';
import { CheckCircle2, Menu, Tag, UserCheck, X } from '@lucide/vue';
import { useChatStore } from '@/stores/chat';
import { useCrmDashboardStore } from '@/stores/crmDashboard';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { conversationLabelText, conversationLabelTone, label as labelText } from '@/lib/statusLabels';

defineEmits<{ 'open-info': []; 'open-sidebar': [] }>();

const chat = useChatStore();
const dashboard = useCrmDashboardStore();

const conversation = computed(() => chat.activeConversation);
const displayName = computed(() => conversation.value?.customer?.name ?? conversation.value?.subject ?? '');
const initial = computed(() => displayName.value[0]?.toUpperCase() ?? '?');

// ЭТАП 8.3/8.5 — claiming stops AI auto-reply (see ProcessAiReplyJob's
// assigned_user_id gate), releasing hands the conversation back to AI. A
// conversation claimed by someone else just shows the badge — no one-click
// takeover from another operator's assignment.
const isAssignedToMe = computed(() => conversation.value?.assigned_user_id === dashboard.user?.id);
const isAssignedToOther = computed(() => !! conversation.value?.assigned_user_id && ! isAssignedToMe.value);

function claim(): void {
    if (conversation.value) chat.setAssignee(conversation.value.id, true);
}

// ЭТАП 13.6 — first real writer of status='closed'; nothing set it before this.
const isClosed = computed(() => conversation.value?.status === 'closed');

function resolve(): void {
    if (conversation.value) chat.resolveConversation(conversation.value.id);
}

const isRecentlyActive = computed(() => {
    const lastMessageAt = conversation.value?.last_message_at;
    return Boolean(lastMessageAt && Date.now() - new Date(lastMessageAt).getTime() < 5 * 60 * 1000);
});

const typingLabel = computed(() => {
    const typers = chat.activeTypers;
    if (! typers.length) return null;

    return typers.length === 1 ? `${typers[0].name} печатает…` : `${typers.length} операторов печатают…`;
});

// ЭТАП 3.7 — freeform manual labels, layered on top of AI auto-labels (both live
// in the same Conversation.labels array, see AiWorkflow::process()).
function labelTone(value: string): 'green' | 'blue' | 'amber' | 'neutral' {
    return conversationLabelTone[value] ?? 'neutral';
}

const newLabel = ref('');

function addLabel(): void {
    const value = newLabel.value.trim();
    if (! value || ! conversation.value) return;

    chat.setLabels(conversation.value.id, [...(conversation.value.labels ?? []), value]);
    newLabel.value = '';
}

function removeLabel(value: string): void {
    if (! conversation.value) return;

    chat.setLabels(conversation.value.id, (conversation.value.labels ?? []).filter((l) => l !== value));
}
</script>

<template>
    <div v-if="conversation" class="flex items-center justify-between gap-3 border-b border-border p-3">
        <div class="flex min-w-0 items-center gap-3">
            <Button variant="ghost" size="icon" class="lg:hidden" aria-label="Диалоги" @click="$emit('open-sidebar')">
                <Menu class="h-4 w-4" />
            </Button>
            <button
                type="button"
                class="flex min-w-0 items-center gap-3 rounded-lg px-1 py-0.5 text-left transition hover:bg-muted"
                title="Информация о диалоге"
                @click="$emit('open-info')"
            >
                <Avatar class="size-9">
                    <AvatarFallback class="bg-primary/10 font-semibold text-primary">{{ initial }}</AvatarFallback>
                </Avatar>
                <div class="min-w-0">
                    <p class="truncate font-display text-sm font-semibold ui-text">{{ displayName }}</p>
                    <p class="truncate text-xs ui-subtle">
                        <span v-if="typingLabel" class="text-primary">{{ typingLabel }}</span>
                        <span v-else>{{ isRecentlyActive ? 'Активен(на) недавно' : conversation.channel?.name ?? '' }}</span>
                    </p>
                </div>
            </button>
            <Badge v-if="conversation.priority === 'high'" tone="amber">Высокий приоритет</Badge>
            <Badge v-if="conversation.assigned_user" tone="blue">Ведёт: {{ conversation.assigned_user.name }}</Badge>
            <Badge v-if="isClosed" tone="green">Закрыт</Badge>
            <Badge v-for="l in conversation.labels" :key="l" :tone="labelTone(l)" class="gap-1">
                {{ labelText(conversationLabelText, l, l) }}
                <button type="button" class="opacity-60 hover:opacity-100" :aria-label="`Убрать лейбл ${l}`" @click="removeLabel(l)">
                    <X class="h-3 w-3" />
                </button>
            </Badge>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <template v-if="! isClosed">
                <Button v-if="! isAssignedToMe && ! isAssignedToOther" variant="outline" size="sm" @click="claim">
                    <UserCheck class="h-4 w-4" />Взять в работу
                </Button>
                <Button variant="outline" size="sm" @click="resolve">
                    <CheckCircle2 class="h-4 w-4" />Закрыть диалог
                </Button>
            </template>
            <Popover>
                <PopoverTrigger as-child>
                    <Button variant="outline" size="icon" aria-label="Лейблы диалога" title="Лейблы диалога">
                        <Tag class="h-4 w-4" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent class="w-56">
                    <p class="mb-2 text-xs font-semibold uppercase ui-subtle">Добавить лейбл</p>
                    <Input v-model="newLabel" placeholder="Например: доставка" @keyup.enter="addLabel" />
                </PopoverContent>
            </Popover>
        </div>
    </div>
</template>
