<script setup lang="ts">
import { Send } from '@lucide/vue';
import { useLocaleStore } from '../../../stores/locale';

defineProps<{ body: string; busy: boolean; canReply: boolean }>();
defineEmits<{ 'update:body': [value: string]; send: [] }>();
const locale = useLocaleStore();
</script>

<template>
    <form v-if="canReply" class="border-t border-white/10 bg-zinc-950/80 p-3" @submit.prevent="$emit('send')">
        <div class="flex items-end gap-3 rounded-2xl border border-white/10 bg-white/[0.04] p-2 focus-within:border-emerald-300/60">
            <textarea
                :value="body"
                class="max-h-32 min-h-11 flex-1 resize-none bg-transparent px-3 py-2 text-sm text-white outline-none placeholder:text-zinc-600"
                :placeholder="locale.t('inbox.replyPlaceholder')"
                maxlength="4000"
                rows="1"
                @input="$emit('update:body', ($event.target as HTMLTextAreaElement).value)"
            />
            <button class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-300 text-zinc-950 transition hover:bg-emerald-200 disabled:cursor-not-allowed disabled:opacity-60" type="submit" :disabled="busy || !body.trim()" :title="locale.t('inbox.sendReply')">
                <Send class="h-4 w-4" />
            </button>
        </div>
    </form>

    <div v-else class="border-t border-white/10 bg-zinc-950/80 p-4 text-sm leading-6 text-zinc-400">
        {{ locale.t('inbox.unlinkedConversation') }}
    </div>
</template>