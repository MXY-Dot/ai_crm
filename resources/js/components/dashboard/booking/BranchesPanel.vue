<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Skeleton } from '../../ui/skeleton';
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

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-12 rounded-lg" />
        </div>
        <p v-else-if="! branches.length" class="pb-4 text-sm ui-subtle">{{ locale.t('booking.noBranches') }}</p>
        <div v-else class="divide-y divide-border pb-2">
            <div v-for="branch in branches" :key="branch.id" class="flex items-center justify-between gap-3 py-3">
                <div>
                    <p class="text-sm font-medium ui-text">{{ branch.name }}</p>
                    <p class="text-xs ui-subtle">{{ branch.address || '—' }}<span v-if="branch.phone"> · {{ branch.phone }}</span></p>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="ghost" size="icon" @click="openEdit(branch)"><Pencil class="h-4 w-4" /></Button>
                    <Button variant="ghost" size="icon" @click="remove(branch)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                </div>
            </div>
        </div>

        <BranchFormDialog v-model:open="dialogOpen" :branch="editing" :company-id="companyId" :tenant-slug="tenantSlug" @saved="load" />
    </Card>
</template>
