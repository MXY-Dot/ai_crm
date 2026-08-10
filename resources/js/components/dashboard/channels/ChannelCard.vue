<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';
import { Settings2 } from '@lucide/vue';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '../../ui/dialog';

const props = defineProps<{
    icon: Component;
    name: string;
    status?: string;
    lastSyncedAt?: string | null;
    brand?: 'telegram' | 'whatsapp' | 'instagram' | 'blue';
}>();

const brandClass = computed(() => {
    const map: Record<string, string> = {
        telegram: 'bg-brand-telegram text-white',
        whatsapp: 'bg-brand-whatsapp text-white',
        instagram: 'bg-gradient-to-br from-brand-instagram-from via-brand-instagram-via to-brand-instagram-to text-white',
        blue: 'bg-brand-website text-white',
    };

    return (props.brand ? map[props.brand] : null) ?? 'bg-muted text-primary';
});

const tone = computed(() => {
    if (props.status === 'connected') return { label: 'Подключено', class: 'bg-primary/10 text-primary border-primary/20' };
    if (props.status === 'pending') return { label: 'Требуется настройка', class: 'bg-amber-500/10 text-amber-600 dark:text-amber-300 border-amber-500/20' };
    if (props.status === 'error') return { label: 'Ошибка', class: 'text-destructive bg-destructive/10 border-destructive/20' };

    return { label: 'Не подключено', class: 'ui-subtle border-transparent' };
});

const lastSyncedLabel = computed(() => (props.lastSyncedAt
    ? new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(props.lastSyncedAt))
    : null));
</script>

<template>
    <article class="flex h-full flex-col gap-5 rounded-xl border p-6 transition hover:border-primary/40 border-border bg-card">
        <div class="flex items-start justify-between gap-3">
            <div class="flex items-center gap-4">
                <span class="grid h-12 w-12 place-items-center rounded-lg" :class="brandClass">
                    <component :is="icon" class="h-6 w-6" />
                </span>
                <div>
                    <h3 class="font-display text-base font-semibold ui-text">{{ name }}</h3>
                    <span class="mt-1 inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide" :class="tone.class">
                        <span class="h-1.5 w-1.5 rounded-full" :class="status === 'connected' ? 'bg-primary' : status === 'error' ? 'bg-destructive' : 'bg-current'" />
                        {{ tone.label }}
                    </span>
                </div>
            </div>
        </div>

        <p v-if="lastSyncedLabel" class="flex-1 text-xs ui-subtle">Синхронизировано: {{ lastSyncedLabel }}</p>
        <div v-else class="flex-1" />

        <Dialog>
            <DialogTrigger as-child>
                <Button variant="outline" class="w-full" type="button"><Settings2 class="h-4 w-4" />Настроить</Button>
            </DialogTrigger>
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><component :is="icon" class="h-4 w-4 text-primary" />{{ name }}</DialogTitle>
                </DialogHeader>
                <div class="space-y-4 py-2">
                    <slot />
                </div>
            </DialogContent>
        </Dialog>
    </article>
</template>
