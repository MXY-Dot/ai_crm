<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { KeyRound, Plus, Trash2 } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Badge } from '../../ui/badge';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { EmptyState } from '../../ui/empty-state';
import { Skeleton } from '../../ui/skeleton';
import GenerateApiKeyDialog from './GenerateApiKeyDialog.vue';

const props = defineProps<{ companyId: number; tenantSlug: string }>();
const locale = useLocaleStore();

type ApiKeyRow = { id: number; name: string; is_active: boolean; last_used_at: string | null; created_at: string; created_by: { name: string } | null };

const keys = ref<ApiKeyRow[]>([]);
const loading = ref(true);
const newOpen = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    try {
        keys.value = await apiRequest<ApiKeyRow[]>('/api/integration-api-keys', { tenant: props.tenantSlug });
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

async function revoke(key: ApiKeyRow): Promise<void> {
    if (! confirm(locale.t('erp.revokeConfirm'))) return;
    try {
        await apiRequest(`/api/integration-api-keys/${key.id}`, { method: 'DELETE', tenant: props.tenantSlug });
        toast.success(locale.t('erp.revoked'));
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    }
}

function formatDate(iso: string | null): string {
    if (! iso) return locale.t('erp.neverUsed');
    return new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Card :title="locale.t('erp.tabKeys')">
        <template #actions>
            <Button size="sm" @click="newOpen = true"><Plus class="h-4 w-4" />{{ locale.t('erp.generateKey') }}</Button>
        </template>

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 2" :key="i" class="h-12 rounded-lg" />
        </div>
        <EmptyState v-else-if="! keys.length" :icon="KeyRound" :title="locale.t('erp.noKeys')" />
        <div v-else class="divide-y divide-border pb-2">
            <div v-for="key in keys" :key="key.id" class="flex items-center justify-between gap-3 py-3">
                <div>
                    <p class="text-sm font-medium ui-text">{{ key.name }}</p>
                    <p class="text-xs ui-subtle">{{ locale.t('erp.lastUsed') }}: {{ formatDate(key.last_used_at) }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <Badge :tone="key.is_active ? 'green' : 'neutral'">{{ key.is_active ? locale.t('erp.active') : locale.t('erp.revokedStatus') }}</Badge>
                    <Button v-if="key.is_active" variant="ghost" size="icon" @click="revoke(key)"><Trash2 class="h-4 w-4 text-destructive" /></Button>
                </div>
            </div>
        </div>

        <GenerateApiKeyDialog v-model:open="newOpen" :company-id="companyId" :tenant-slug="tenantSlug" @created="load" />
    </Card>
</template>
