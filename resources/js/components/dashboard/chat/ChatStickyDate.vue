<script setup lang="ts">
import { computed, ref } from 'vue';
import { type DateValue, getLocalTimeZone, today } from '@internationalized/date';
import { CalendarIcon } from '@lucide/vue';
import { useMessageScroller, useMessageScrollerVisibility } from '@/components/ui/message-scroller';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

type ThreadRow =
    | { kind: 'date'; key: string; label: string; iso: string; messageId: string }
    | { kind: 'group'; key: string; messages: { id: number | string }[] };

const props = defineProps<{ rows: ThreadRow[] }>();

/**
 * A sticky-looking floating date pill can't just be `position: sticky` here —
 * MessageScrollerItem sets `content-visibility: auto` on every message group
 * for virtualization, which makes native CSS sticky behave unreliably for
 * anything sharing that scroll flow. Instead this renders as an absolute
 * overlay pinned to the top of MessageScroller's own `relative` wrapper (same
 * pattern MessageScrollerButton already uses for the "scroll to bottom"
 * button) and tracks the real current date via the scroller's own visibility
 * API — the same reactive state MessageScrollerButton reads from.
 */
const visibility = useMessageScrollerVisibility();
const currentAnchorId = computed(() => visibility.value.currentAnchorId);
const { scrollToMessage } = useMessageScroller();

const dateRows = computed(() => props.rows.filter((row): row is Extract<ThreadRow, { kind: 'date' }> => row.kind === 'date'));

const currentDateLabel = computed(() => {
    let label = dateRows.value[0]?.label ?? '';

    for (const row of props.rows) {
        if (row.kind === 'date') {
            label = row.label;
            continue;
        }
        if (currentAnchorId.value && row.messages.some((message) => String(message.id) === currentAnchorId.value)) {
            return label;
        }
    }

    return label;
});

const dayKeysWithMessages = computed(() => new Set(dateRows.value.map((row) => row.iso.slice(0, 10))));

const open = ref(false);
const selected = ref<DateValue>(today(getLocalTimeZone()));

function isDateUnavailable(date: DateValue): boolean {
    return ! dayKeysWithMessages.value.has(date.toString());
}

function onSelect(date: DateValue | undefined): void {
    if (! date) return;
    selected.value = date;

    const match = dateRows.value.find((row) => row.iso.slice(0, 10) === date.toString());
    if (match) scrollToMessage(match.messageId, { align: 'start', behavior: 'smooth' });
    open.value = false;
}
</script>

<template>
    <div v-if="dateRows.length" class="pointer-events-none absolute inset-x-0 top-3 z-10 flex justify-center">
        <Popover v-model:open="open">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="pointer-events-auto flex items-center gap-1.5 rounded-full border border-border bg-card/95 px-3 py-1 text-xs font-medium shadow-sm backdrop-blur transition hover:border-primary/40 hover:text-primary ui-text"
                >
                    <CalendarIcon class="h-3.5 w-3.5" />
                    {{ currentDateLabel }}
                </button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0" align="center">
                <Calendar
                    :model-value="selected"
                    :is-date-unavailable="isDateUnavailable"
                    @update:model-value="onSelect"
                />
            </PopoverContent>
        </Popover>
    </div>
</template>
