<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import DataTable from '../DataTable.vue';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import BranchFormDialog, { type BranchRow } from './BranchFormDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();

const branches = ref<BranchRow[]>([]);
const loading = ref(true);
const dialogOpen = ref(false);
const editing = ref<BranchRow | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const res = await apiRequest<{ data: BranchRow[] }>('/api/branches', { tenant: props.tenantSlug });
        branches.value = res.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function openCreate(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function openEdit(branch: BranchRow): void {
    editing.value = branch;
    dialogOpen.value = true;
}

async function remove(branch: BranchRow): Promise<void> {
    if (! confirm(branch.name + '?')) return;
    try {
        await apiRequest(`/api/branches/${branch.id}`, { method: 'DELETE', tenant: props.tenantSlug });
        branches.value = branches.value.filter((b) => b.id !== branch.id);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}
</script>

<template>
    <Card :title="locale.t('booking.tabBranches')">
        <template #actions>
            <Button size="sm" @click="openCreate"><Plus class="h-4 w-4" />{{ locale.t('booking.addBranch') }}</Button>
        </template>

        <DataTable
            embedded
            :loading="loading"
            :row-count="branches.length"
            :column-count="2"
            :empty-message="locale.t('booking.noBranches')"
            min-width="min-w-full"
        >
            <template #thead>
                <th class="p-3">{{ locale.t('booking.tabBranches') }}</th>
                <th class="p-3 text-right">{{ locale.t('common.actions') }}</th>
            </template>

            <tr v-for="branch in branches" :key="branch.id">
                <td class="p-3">
                    <p class="text-sm font-medium ui-text">{{ branch.name }}</p>
                    <p class="text-xs ui-subtle">{{ branch.address || '—' }}<span v-if="branch.phone"> · {{ branch.phone }}</span></p>
                </td>
                <td class="p-3 text-right">
                    <div class="flex items-center justify-end gap-2">
                        <Button variant="ghost" size="icon" @click="openEdit(branch)"><Pencil class="h-4 w-4" /></Button>
                        <Button variant="ghost" size="icon" @click="remove(branch)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                    </div>
                </td>
            </tr>
        </DataTable>

        <BranchFormDialog v-model:open="dialogOpen" :branch="editing" :company-id="companyId" :tenant-slug="tenantSlug" @saved="load" />
    </Card>
</template>
