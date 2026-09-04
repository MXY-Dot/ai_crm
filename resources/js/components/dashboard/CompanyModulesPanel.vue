<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { Blocks } from '@lucide/vue';
import { apiRequest } from '../../lib/apiClient';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import DataTable from './DataTable.vue';
import { Card } from '../ui/card';
import { Switch } from '../ui/switch';

type ModuleRow = { key: string; label: string; enabled: boolean };

const store = useCrmDashboardStore();
const { tenant } = storeToRefs(store);
const tenantSlug = computed(() => tenant.value?.slug ?? '');

const modules = ref<ModuleRow[]>([]);
const businessTypeName = ref<string | null>(null);
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const data = await apiRequest<{ modules: ModuleRow[]; business_type: { name: string } | null }>('/api/company-modules', { tenant: tenantSlug.value });
        modules.value = data.modules;
        businessTypeName.value = data.business_type?.name ?? null;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить модули');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

async function toggle(row: ModuleRow): Promise<void> {
    const next = ! row.enabled;
    row.enabled = next;
    try {
        await apiRequest('/api/company-modules/toggle', { method: 'POST', body: { module_key: row.key, enabled: next }, tenant: tenantSlug.value });
        toast.success(next ? `Модуль «${row.label}» включён` : `Модуль «${row.label}» выключен`);
    } catch (error) {
        row.enabled = ! next;
        toast.error(error instanceof Error ? error.message : 'Не удалось изменить модуль');
    }
}
</script>

<template>
    <Card title="Модули" subtitle="Дополнительные функции WERO, доступные вашей сфере бизнеса.">
        <p v-if="businessTypeName" class="mb-4 flex items-center gap-2 text-sm ui-subtle">
            <Blocks class="h-4 w-4 text-primary" />Сфера бизнеса: <span class="font-medium ui-text">{{ businessTypeName }}</span>
        </p>

        <DataTable
            embedded
            :loading="loading"
            :row-count="modules.length"
            :column-count="2"
            empty-message="Список модулей пока пуст."
            min-width="min-w-full"
        >
            <template #thead>
                <th class="p-3">Модуль</th>
                <th class="p-3 text-right">Статус</th>
            </template>

            <tr v-for="row in modules" :key="row.key">
                <td class="p-3 text-sm ui-text">{{ row.label }}</td>
                <td class="p-3 text-right"><Switch :model-value="row.enabled" @update:model-value="toggle(row)" /></td>
            </tr>
        </DataTable>
    </Card>
</template>
