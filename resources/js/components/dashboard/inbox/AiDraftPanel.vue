<script setup lang="ts">
import { Bot, RefreshCw, Send } from '@lucide/vue';
import type { Message } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';

defineProps<{ draft: Message | null; summary: string | null; busy: boolean; canSend: boolean; canGenerate: boolean }>();
defineEmits<{ generateDraft: []; useDraft: [body: string]; sendDraft: [body: string] }>();
const locale = useLocaleStore();
</script>

<template>
    <div class="rounded-md border border-amber-300/30 bg-amber-300/10 p-4 text-sm leading-6 text-amber-50">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <p class="flex items-center gap-2 font-semibold">
                <Bot class="h-4 w-4" /> {{ locale.t('inbox.aiDraftTitle') }}
            </p>
            <button
                class="inline-flex h-8 items-center gap-2 rounded-md border border-amber-200/30 px-3 text-xs font-semibold disabled:opacity-60"
                type="button"
                :disabled="busy || !canGenerate"
                @click="$emit('generateDraft')"
            >
                <RefreshCw class="h-3 w-3" /> {{ locale.t('inbox.generateDraft') }}
            </button>
        </div>

        <template v-if="draft">
            <p class="whitespace-pre-wrap">{{ draft.body }}</p>
            <div v-if="canSend" class="mt-4 flex flex-wrap gap-2">
                <button class="h-9 rounded-md border border-amber-200/30 px-3 text-xs font-semibold" type="button" @click="$emit('useDraft', draft.body)">
                    {{ locale.t('inbox.useDraft') }}
                </button>
                <button class="inline-flex h-9 items-center gap-2 rounded-md bg-amber-200 px-3 text-xs font-semibold text-zinc-950 disabled:opacity-60" type="button" :disabled="busy" @click="$emit('sendDraft', draft.body)">
                    <Send class="h-3 w-3" /> {{ locale.t('inbox.sendDraft') }}
                </button>
            </div>
        </template>

        <p v-else class="text-amber-100/80">{{ summary || locale.t('inbox.noAiDraft') }}</p>
    </div>
</template>