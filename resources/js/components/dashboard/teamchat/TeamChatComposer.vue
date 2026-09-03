<script setup lang="ts">
import { ref } from 'vue';
import { SendIcon } from '@lucide/vue';
import { useTeamChatStore } from '@/stores/teamChat';
import { useLocaleStore } from '@/stores/locale';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

const team = useTeamChatStore();
const locale = useLocaleStore();
const body = ref('');

async function submit(): Promise<void> {
    const text = body.value.trim();
    if (! text || team.sending) return;
    body.value = '';
    await team.send(text);
}

function onEnterKey(event: KeyboardEvent): void {
    if (event.shiftKey || event.isComposing) return;
    event.preventDefault();
    submit();
}
</script>

<template>
    <form class="relative shrink-0 border-t p-3 border-border bg-card" @submit.prevent="submit">
        <div class="flex items-end gap-1 rounded-full border p-1 transition focus-within:border-primary border-border">
            <Textarea
                v-model="body"
                class="max-h-40 min-h-9 flex-1 resize-none border-none bg-transparent py-2 pl-3 shadow-none focus-visible:ring-0"
                :placeholder="locale.t('teamChat.placeholder')"
                maxlength="4000"
                rows="1"
                @keydown.enter="onEnterKey"
            />
            <Button class="mb-1 shrink-0 rounded-full" variant="primary" size="icon" type="submit" :disabled="! body.trim() || team.sending" :title="locale.t('teamChat.send')">
                <SendIcon class="h-4 w-4" />
            </Button>
        </div>
    </form>
</template>
