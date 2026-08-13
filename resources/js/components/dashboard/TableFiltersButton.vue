<script setup lang="ts">
import { ListFilter, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';

defineProps<{ activeCount?: number }>();
defineEmits<{ reset: [] }>();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="outline" size="sm" class="relative">
                <ListFilter class="h-4 w-4" />Фильтры
                <span
                    v-if="activeCount"
                    class="grid size-4 place-items-center rounded-full text-[10px] font-bold bg-primary text-primary-foreground"
                >{{ activeCount }}</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="w-[calc(100vw-2rem)] max-w-72 space-y-3 p-3">
            <slot />
            <button
                v-if="activeCount"
                type="button"
                class="flex w-full items-center justify-center gap-1.5 rounded-lg border py-1.5 text-xs font-medium transition hover:bg-muted border-border ui-subtle"
                @click="$emit('reset')"
            >
                <X class="h-3 w-3" />Сбросить фильтры
            </button>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
