<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { Merge, Users } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';

type DuplicateCustomer = { id: number; name: string; phone: string | null; email: string | null; source: string | null; created_at: string | null };

const dashboard = useCrmDashboardStore();
const locale = useLocaleStore();
const { tenant } = storeToRefs(dashboard);

const groups = ref<DuplicateCustomer[][]>([]);
const loading = ref(true);
const mergingPhone = ref<string | null>(null);

async function load(): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    loading.value = true;
    try {
        const response = await apiRequest<{ data: DuplicateCustomer[][] }>('/api/customers/duplicates', { tenant: slug });
        groups.value = response.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось проверить дубли клиентов');
    } finally {
        loading.value = false;
    }
}

async function merge(group: DuplicateCustomer[]): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug || group.length < 2) return;

    const [winner, ...losers] = group;
    mergingPhone.value = winner.phone;
    try {
        for (const loser of losers) {
            await apiRequest('/api/customers/merge', {
                method: 'POST',
                tenant: slug,
                body: { winner_id: winner.id, loser_id: loser.id },
            });
        }
        toast.success(locale.t('contacts.duplicates.merged'));
        await Promise.all([load(), dashboard.refreshDashboard()]);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось объединить клиентов');
    } finally {
        mergingPhone.value = null;
    }
}

onMounted(load);
</script>

<template>
    <Card v-if="! loading && groups.length" :title="locale.t('contacts.duplicates.title')" :subtitle="locale.t('contacts.duplicates.subtitle')">
        <div class="space-y-3">
            <article v-for="group in groups" :key="group[0].phone" class="rounded-lg border p-3 border-border">
                <div class="mb-2 flex items-center justify-between gap-3">
                    <span class="flex items-center gap-2 text-sm font-medium ui-text"><Users class="h-4 w-4 text-primary" />{{ group[0].phone }}</span>
                    <Button size="sm" variant="outline" :disabled="mergingPhone === group[0].phone" @click="merge(group)">
                        <Merge class="h-4 w-4" />{{ locale.t('contacts.duplicates.merge') }}
                    </Button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span v-for="customer in group" :key="customer.id" class="rounded bg-muted px-2 py-1 text-xs ui-subtle">{{ customer.name }}</span>
                </div>
            </article>
        </div>
    </Card>
</template>
