<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { ChevronLeft, ChevronRight, Search } from '@lucide/vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';
import { Button } from '../components/ui/button';
import { Input } from '../components/ui/input';
import AddContactDialog from '../components/dashboard/contacts/AddContactDialog.vue';
import ContactsTable from '../components/dashboard/contacts/ContactsTable.vue';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { customers, leads, conversations } = storeToRefs(store);
const query = ref('');
const page = ref(1);
const perPage = 15;

const filtered = computed(() => {
    const normalizedQuery = query.value.trim().toLowerCase();
    if (! normalizedQuery) return customers.value;

    return customers.value.filter((customer) => [customer.name, customer.email, customer.phone]
        .some((value) => value?.toLowerCase().includes(normalizedQuery)));
});

const pageCount = computed(() => Math.max(1, Math.ceil(filtered.value.length / perPage)));
const paged = computed(() => filtered.value.slice((page.value - 1) * perPage, page.value * perPage));

watch([query, () => customers.value.length], () => { page.value = 1; });

function selectCustomer(customer: { id: number }): void {
    store.openCustomer(customer.id);
}

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-display text-xl font-bold ui-text">{{ locale.t('contacts.title') }}</h2>
                <p class="text-sm ui-subtle">{{ locale.t('contacts.subtitle') }}</p>
            </div>
            <div class="flex gap-2">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 ui-subtle" />
                    <Input v-model="query" class="h-9 pl-9 lg:pl-10" :placeholder="locale.t('contacts.searchPlaceholder')" />
                </div>
                <AddContactDialog />
            </div>
        </div>

        <ContactsTable :customers="paged" :leads="leads" :conversations="conversations" @select="selectCustomer" />

        <div v-if="filtered.length" class="flex flex-wrap items-center justify-between gap-3 text-sm ui-subtle">
            <p>{{ locale.t('contacts.showingPrefix') }} {{ (page - 1) * perPage + 1 }}&ndash;{{ Math.min(page * perPage, filtered.length) }} {{ locale.t('contacts.showingOf') }} {{ filtered.length }}</p>
            <div class="flex items-center gap-2">
                <Button size="sm" variant="outline" :disabled="page <= 1" @click="page -= 1"><ChevronLeft class="h-4 w-4" /></Button>
                <span class="ui-text">{{ page }} / {{ pageCount }}</span>
                <Button size="sm" variant="outline" :disabled="page >= pageCount" @click="page += 1"><ChevronRight class="h-4 w-4" /></Button>
            </div>
        </div>
    </section>
</template>
