<script setup lang="ts">
import { ref } from 'vue';
import { Building2, Upload } from '@lucide/vue';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Button } from '../ui/button';

const props = defineProps<{ companyId: number; logoUrl: string | null }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();
const fileInput = ref<HTMLInputElement | null>(null);

async function onFileSelected(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (! file) return;

    await store.uploadCompanyLogo(props.companyId, file);
    input.value = '';
}
</script>

<template>
    <div class="flex items-center gap-4">
        <span class="grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-lg border border-border bg-muted">
            <img v-if="logoUrl" :src="logoUrl" alt="" class="h-full w-full object-cover">
            <Building2 v-else class="h-7 w-7 ui-subtle" />
        </span>
        <div>
            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('company.logo') }}</span>
            <Button type="button" size="sm" variant="outline" :disabled="store.busy" @click="fileInput?.click()">
                <Upload class="h-4 w-4" />{{ locale.t('company.uploadLogo') }}
            </Button>
            <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFileSelected">
        </div>
    </div>
</template>
