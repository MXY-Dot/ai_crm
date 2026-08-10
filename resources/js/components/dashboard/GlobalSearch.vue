<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { storeToRefs } from 'pinia';
import { Building2, MessageSquare, Search, Target, X } from '@lucide/vue';
import { pagePaths } from '@/lib/pages';
import { useCrmDashboardStore } from '@/stores/crmDashboard';
import { useLocaleStore } from '@/stores/locale';
import { Input } from '@/components/ui/input';

type Hit = { key: string; icon: typeof Search; type: string; title: string; subtitle: string; go: () => void };

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { customers, leads, conversations } = storeToRefs(store);
const query = ref('');
const open = ref(false);

function matches(value: string | null | undefined, needle: string): boolean {
    return Boolean(value?.toLowerCase().includes(needle));
}

const results = computed<Hit[]>(() => {
    const needle = query.value.trim().toLowerCase();
    if (! needle) return [];

    const hits: Hit[] = [];

    for (const customer of customers.value) {
        if (! matches(customer.name, needle) && ! matches(customer.phone, needle) && ! matches(customer.email, needle)) continue;
        hits.push({
            key: `customer-${customer.id}`,
            icon: Building2,
            type: locale.t('nav.customers'),
            title: customer.name,
            subtitle: customer.email ?? customer.phone ?? '',
            go: () => { store.openCustomer(customer.id); router.visit(pagePaths.customers); },
        });
    }

    for (const lead of leads.value) {
        if (! matches(lead.title, needle) && ! matches(lead.source, needle) && ! matches(lead.status, needle)) continue;
        hits.push({
            key: `lead-${lead.id}`,
            icon: Target,
            type: locale.t('nav.leads'),
            title: lead.title,
            subtitle: locale.t(`leads.statuses.${lead.status}`),
            go: () => { store.openLead(lead.id); router.visit(pagePaths.leads); },
        });
    }

    for (const conversation of conversations.value) {
        if (! matches(conversation.subject, needle) && ! matches(conversation.customer?.name, needle)) continue;
        hits.push({
            key: `conversation-${conversation.id}`,
            icon: MessageSquare,
            type: locale.t('nav.inbox'),
            title: conversation.subject,
            subtitle: conversation.customer?.name ?? '',
            go: () => { store.openConversation(conversation.id); router.visit(pagePaths.inbox); },
        });
    }

    return hits.slice(0, 8);
});

function select(hit: Hit): void {
    hit.go();
    query.value = '';
    open.value = false;
}

function clear(): void {
    query.value = '';
    open.value = false;
}
</script>

<template>
    <div class="relative w-full" @focusout="open = false">
        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 ui-subtle" />
        <Input
            v-model="query"
            class="h-9 pl-9 lg:pl-10 pr-8"
            :placeholder="locale.t('common.searchPlaceholder')"
            @focus="open = true"
            @keydown.esc="clear"
        />
        <button v-if="query" type="button" class="absolute right-2.5 top-1/2 -translate-y-1/2 ui-subtle hover:text-foreground" @click="clear">
            <X class="h-4 w-4" />
        </button>

        <div
            v-if="open && query"
            class="absolute left-0 right-0 top-full z-20 mt-2 max-h-80 overflow-y-auto rounded-lg border shadow-lg border-border bg-popover"

        >
            <button
                v-for="hit in results"
                :key="hit.key"
                type="button"
                class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-muted"
                @mousedown.prevent="select(hit)"
            >
                <component :is="hit.icon" class="h-4 w-4 shrink-0 ui-subtle" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium ui-text">{{ hit.title }}</span>
                    <span class="block truncate text-xs ui-subtle">{{ hit.subtitle }}</span>
                </span>
                <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wide ui-subtle">{{ hit.type }}</span>
            </button>
            <p v-if="! results.length" class="px-3 py-3 text-sm ui-subtle">{{ locale.t('common.noResults') }}</p>
        </div>
    </div>
</template>
