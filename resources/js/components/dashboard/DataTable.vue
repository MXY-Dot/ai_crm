<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

type Meta = { current_page: number; last_page: number; per_page: number; total: number };

const props = withDefaults(defineProps<{
    loading?: boolean;
    rowCount?: number;
    columnCount: number;
    emptyMessage?: string;
    skeletonRows?: number;
    meta?: Meta | null;
    itemLabel?: string;
    minWidth?: string;
    /** For a table that already lives inside a `<Card>` (e.g. a settings panel) -- drops the redundant outer border/background so it doesn't nest a card inside a card. */
    embedded?: boolean;
}>(), {
    loading: false,
    rowCount: 0,
    emptyMessage: 'Ничего не найдено',
    skeletonRows: 6,
    meta: null,
    itemLabel: 'записей',
    minWidth: 'min-w-[64rem]',
    embedded: false,
});

const emit = defineEmits<{ (e: 'update:page', value: number): void }>();
</script>

<template>
    <div :class="props.embedded ? '' : 'overflow-hidden rounded-xl border border-border bg-card'">
        <div v-if="$slots.toolbar" class="flex flex-wrap items-center justify-between gap-3 border-b p-4 border-border">
            <slot name="toolbar" />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm" :class="minWidth">
                <thead class="border-b border-border">
                    <tr class="text-xs font-semibold uppercase ui-subtle">
                        <slot name="thead" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <template v-if="loading">
                        <tr v-for="i in skeletonRows" :key="`skeleton-${i}`">
                            <td class="p-4" :colspan="columnCount"><Skeleton class="h-10 w-full" /></td>
                        </tr>
                    </template>
                    <tr v-else-if="! rowCount">
                        <td class="p-8 text-center text-sm ui-subtle" :colspan="columnCount">{{ emptyMessage }}</td>
                    </tr>
                    <slot v-else />
                </tbody>
            </table>
        </div>

        <div v-if="meta" class="flex flex-wrap items-center justify-between gap-3 border-t p-4 border-border">
            <span class="text-sm ui-subtle">
                Показано {{ rowCount ? (meta.current_page - 1) * meta.per_page + 1 : 0 }}–{{ (meta.current_page - 1) * meta.per_page + rowCount }} из {{ meta.total }} {{ itemLabel }}
            </span>
            <div class="flex items-center gap-1">
                <Button size="icon-sm" variant="outline" :disabled="meta.current_page <= 1" @click="emit('update:page', meta.current_page - 1)"><ChevronLeft class="h-4 w-4" /></Button>
                <span class="px-2 font-mono text-sm ui-text">{{ meta.current_page }} / {{ meta.last_page }}</span>
                <Button size="icon-sm" variant="outline" :disabled="meta.current_page >= meta.last_page" @click="emit('update:page', meta.current_page + 1)"><ChevronRight class="h-4 w-4" /></Button>
            </div>
        </div>
    </div>
</template>
