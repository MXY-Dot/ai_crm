<script setup lang="ts">
import { Send } from '@lucide/vue';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Textarea } from '../../ui/textarea';

defineProps<{ body: string; busy: boolean; canReply: boolean }>();
defineEmits<{ 'update:body': [value: string]; send: [] }>();
const locale = useLocaleStore();
</script>

<template>
    <form v-if="canReply" class="shrink-0 border-t p-3" style="border-color: var(--border); background: var(--card)" @submit.prevent="$emit('send')">
        <div class="flex items-end gap-2 rounded-xl border p-1 transition focus-within:border-primary" style="border-color: var(--border)">
            <Textarea
                :model-value="body"
                class="max-h-32 min-h-11 flex-1 resize-none border-none bg-transparent shadow-none focus-visible:ring-0"
                :placeholder="locale.t('inbox.replyPlaceholder')"
                maxlength="4000"
                rows="1"
                @update:model-value="$emit('update:body', String($event))"
            />
            <Button
                class="mb-1 h-9 w-9 shrink-0"
                variant="primary"
                size="icon"
                type="submit"
                :disabled="busy || !body.trim()"
                :title="locale.t('inbox.sendReply')"
            >
                <Send class="h-4 w-4" />
            </Button>
        </div>
    </form>

    <div v-else class="border-t p-4 text-sm leading-6 ui-subtle" style="border-color: var(--border); background: var(--card)">
        {{ locale.t('inbox.unlinkedConversation') }}
    </div>
</template>
