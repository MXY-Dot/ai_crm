<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { MessageSquare, Users } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import InboxWorkspace from '@/components/dashboard/InboxWorkspace.vue';
import TeamChatWorkspace from '@/components/dashboard/TeamChatWorkspace.vue';
import { Badge } from '@/components/ui/badge';
import { useLocaleStore } from '@/stores/locale';
import { useTeamChatStore } from '@/stores/teamChat';

defineOptions({ layout: AppLayout });

const locale = useLocaleStore();
const team = useTeamChatStore();
const mode = ref<'customers' | 'team'>('customers');

// Owned here (not inside TeamChatWorkspace) so the unread badge on the tab
// itself stays live even while the customer tab is the one showing --
// TeamChatWorkspace only mounts once the user actually switches tabs.
onMounted(() => team.init());
onBeforeUnmount(() => team.dispose());
</script>

<template>
    <div class="mb-3 flex w-fit items-center gap-1 rounded-lg border border-border p-0.5">
        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition"
            :class="mode === 'customers' ? 'bg-secondary text-secondary-foreground' : 'ui-subtle hover:bg-muted'"
            @click="mode = 'customers'"
        >
            <MessageSquare class="h-4 w-4" />{{ locale.t('inbox.customersTab') }}
        </button>
        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition"
            :class="mode === 'team' ? 'bg-secondary text-secondary-foreground' : 'ui-subtle hover:bg-muted'"
            @click="mode = 'team'"
        >
            <Users class="h-4 w-4" />{{ locale.t('inbox.teamTab') }}
            <Badge v-if="team.totalUnread" tone="green">{{ team.totalUnread }}</Badge>
        </button>
    </div>

    <InboxWorkspace v-show="mode === 'customers'" />
    <TeamChatWorkspace v-if="mode === 'team'" />
</template>
