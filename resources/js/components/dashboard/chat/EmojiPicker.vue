<script setup lang="ts">
import { SmileIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { ScrollArea } from '@/components/ui/scroll-area';

defineEmits<{ pick: [emoji: string] }>();

const EMOJI = [
    '😀', '😂', '🥲', '😊', '😉', '😍', '🤔', '😅', '😢', '😭',
    '😡', '😱', '👍', '👎', '👏', '🙏', '🤝', '💪', '🔥', '✨',
    '🎉', '❤️', '💔', '⭐', '✅', '❌', '❓', '❗', '⏳', '🕐',
    '📌', '📎', '📷', '🎤', '💬', '👋', '🙌', '🤗', '😴', '🤝',
    '☕', '🎂', '🚀', '👀', '💯', '🙄', '😎', '🤷',
];
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <Button variant="ghost" size="icon" type="button" aria-label="Эмодзи" title="Эмодзи">
                <SmileIcon class="h-4 w-4" />
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-64 p-2" align="start">
            <ScrollArea class="h-48">
                <div class="grid grid-cols-8 gap-0.5 pr-2">
                    <button
                        v-for="emoji in EMOJI"
                        :key="emoji"
                        type="button"
                        class="grid size-7 place-items-center rounded-md text-lg transition hover:bg-muted"
                        @click="$emit('pick', emoji)"
                    >
                        {{ emoji }}
                    </button>
                </div>
            </ScrollArea>
        </PopoverContent>
    </Popover>
</template>
