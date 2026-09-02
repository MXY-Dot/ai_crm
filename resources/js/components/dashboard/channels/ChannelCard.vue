<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';
import { ExternalLink, Settings2 } from '@lucide/vue';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '../../ui/dialog';

const props = defineProps<{
    icon: Component;
    name: string;
    status?: string;
    lastSyncedAt?: string | null;
    brand?: 'telegram' | 'whatsapp' | 'instagram' | 'facebook' | 'blue' | 'erp';
    /** Optional external link (e.g. the provider's own dashboard) shown as a small button next to "Настроить". */
    externalUrl?: string | null;
    /**
     * When set, "Настроить" navigates here (a real settings page, e.g.
     * /erp-settings) instead of opening the inline dialog + default slot --
     * for integrations whose real configuration UI (a full CRUD panel, not
     * a couple of form fields) is too big to comfortably fit in this card's
     * dialog. Omit for the normal inline-dialog channels.
     */
    settingsHref?: string | null;
    /**
     * ЭТАП 2.6 — real, actively-monitored status (see ActiveHealthProbe::probeTelegramChannels()),
     * distinct from `status` (which is only ever written once, at connect-time).
     * When set, this overrides the badge entirely; omit/null for channels that
     * aren't monitored yet, which keeps the old `status`-only badge.
     */
    healthStatus?: 'up' | 'down' | null;
}>();

const brandClass = computed(() => {
    const map: Record<string, string> = {
        telegram: 'bg-brand-telegram text-white',
        whatsapp: 'bg-brand-whatsapp text-white',
        instagram: 'bg-gradient-to-br from-brand-instagram-from via-brand-instagram-via to-brand-instagram-to text-white',
        facebook: 'bg-[#1877F2] text-white',
        blue: 'bg-brand-website text-white',
        erp: 'bg-slate-600 text-white',
    };

    return (props.brand ? map[props.brand] : null) ?? 'bg-muted text-primary';
});

const tone = computed(() => {
    if (props.healthStatus === 'down') return { label: 'Ошибка соединения', class: 'text-destructive bg-destructive/10 border-destructive/20' };
    if (props.healthStatus === 'up') return { label: 'Подключено', class: 'bg-primary/10 text-primary border-primary/20' };
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
                        <span class="h-1.5 w-1.5 rounded-full" :class="healthStatus === 'down' || status === 'error' ? 'bg-destructive' : healthStatus === 'up' || status === 'connected' ? 'bg-primary' : 'bg-current'" />
                        {{ tone.label }}
                    </span>
                </div>
            </div>
        </div>

        <div v-if="lastSyncedLabel || $slots.stats" class="flex-1 space-y-3">
            <p v-if="lastSyncedLabel" class="text-xs ui-subtle">Синхронизировано: {{ lastSyncedLabel }}</p>
            <slot name="stats" />
        </div>
        <div v-else class="flex-1" />

        <div class="flex gap-2">
            <Button v-if="settingsHref" variant="outline" class="flex-1" as="a" :href="settingsHref" type="button"><Settings2 class="h-4 w-4" />Настроить</Button>
            <Dialog v-else>
                <DialogTrigger as-child>
                    <Button variant="outline" class="flex-1" type="button"><Settings2 class="h-4 w-4" />Настроить</Button>
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
            <Button v-if="externalUrl" variant="outline" size="icon" as="a" :href="externalUrl" target="_blank" rel="noopener noreferrer" title="Открыть сайт провайдера">
                <ExternalLink class="h-4 w-4" />
            </Button>
        </div>
    </article>
</template>
