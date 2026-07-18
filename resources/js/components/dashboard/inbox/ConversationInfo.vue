<script setup lang="ts">
import { Clock, MessageSquare, UserRound } from '@lucide/vue';
import type { Conversation } from '../../../stores/crmDashboard';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';

defineProps<{ conversation: Conversation }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();
</script>

<template>
    <div class="grid gap-3 rounded-md border border-white/10 bg-white/[0.03] p-4 sm:grid-cols-3">
        <div class="text-sm text-zinc-400">
            <UserRound class="mb-1 h-4 w-4 text-emerald-300" />
            <span class="block text-xs uppercase text-zinc-500">{{ locale.t('inbox.customer') }}</span>
            <button v-if="conversation.customer" class="text-left text-zinc-200 hover:text-emerald-200" @click="store.openCustomer(conversation.customer.id)">{{ conversation.customer.name }}</button>
            <span v-else>{{ locale.t('common.unknown') }}</span>
        </div>
        <div class="text-sm text-zinc-400">
            <MessageSquare class="mb-1 h-4 w-4 text-emerald-300" />
            <span class="block text-xs uppercase text-zinc-500">{{ locale.t('inbox.lead') }}</span>
            <button v-if="conversation.lead" class="text-left text-zinc-200 hover:text-emerald-200" @click="store.openLead(conversation.lead.id)">{{ conversation.lead.title }}</button>
            <span v-else>{{ locale.t('crm.noLead') }}</span>
        </div>
        <p class="text-sm text-zinc-400">
            <Clock class="mb-1 h-4 w-4 text-emerald-300" />
            <span class="block text-xs uppercase text-zinc-500">{{ locale.t('inbox.priority') }}</span>
            {{ conversation.priority }}
        </p>
    </div>
</template>