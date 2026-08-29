<script setup lang="ts">
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import { Mail, Phone, Sparkles, SquareArrowOutUpRight } from '@lucide/vue';
import type { Conversation } from '../../../stores/crmDashboard';
import { Avatar, AvatarFallback } from '../../ui/avatar';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import ConversationAnalysisPanel from './ConversationAnalysisPanel.vue';

const props = defineProps<{ conversation: Conversation }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { customers } = storeToRefs(store);

const fullCustomer = computed(() => customers.value.find((item) => item.id === props.conversation.customer?.id) ?? null);

function initials(name: string): string {
    return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}
</script>

<template>
    <div class="flex h-full flex-col overflow-y-auto">
        <div class="flex flex-col items-center border-b p-5 text-center border-border">
            <Avatar class="mb-3 size-16"><AvatarFallback class="text-xl">{{ initials(conversation.customer?.name ?? '?') }}</AvatarFallback></Avatar>
            <h2 class="font-display text-base font-semibold ui-text">{{ conversation.customer?.name ?? locale.t('common.unknown') }}</h2>
            <p class="mt-1 text-xs ui-subtle">{{ conversation.channel?.name }}</p>
        </div>

        <div v-if="conversation.ai_summary" class="relative m-4 overflow-hidden rounded-xl border p-4 border-primary bg-card">
            <div class="mb-2 flex items-center gap-2">
                <Sparkles class="h-4 w-4 text-primary" />
                <h3 class="text-xs font-bold uppercase tracking-wide ui-text">AI Резюме</h3>
            </div>
            <p class="text-[13px] leading-relaxed ui-subtle">{{ conversation.ai_summary }}</p>
        </div>

        <ConversationAnalysisPanel :conversation-id="conversation.id" />

        <div class="space-y-4 px-4 py-2">
            <div>
                <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider ui-subtle">Контакты</h4>
                <div class="space-y-2 text-sm">
                    <p class="flex items-center gap-2 ui-text"><Phone class="h-4 w-4 ui-subtle" />{{ fullCustomer?.phone ?? locale.t('common.unknown') }}</p>
                    <p class="flex items-center gap-2 ui-text"><Mail class="h-4 w-4 ui-subtle" />{{ fullCustomer?.email ?? locale.t('common.unknown') }}</p>
                </div>
            </div>
            <div class="h-px bg-border" />
            <div>
                <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider ui-subtle">CRM данные</h4>
                <div class="space-y-2 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="ui-subtle">Лид</span>
                        <button v-if="conversation.lead" class="font-medium text-primary hover:underline" @click="store.openLead(conversation.lead.id)">{{ conversation.lead.title }}</button>
                        <span v-else class="ui-text">{{ locale.t('crm.noLead') }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="ui-subtle">Приоритет</span>
                        <span class="font-medium ui-text">{{ conversation.priority }}</span>
                    </div>
                </div>
            </div>
        </div>

        <button
            v-if="conversation.customer"
            class="mx-4 mb-4 mt-auto flex items-center justify-center gap-2 rounded-lg border py-2 text-sm font-medium ui-text transition hover:bg-muted border-border"

            @click="store.openCustomer(conversation.customer.id)"
        >
            <SquareArrowOutUpRight class="h-4 w-4" /> Открыть в CRM
        </button>
    </div>
</template>
