<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { CodeBlock } from '../../ui/code-block';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input } from '../../ui/input';

const props = defineProps<{ open: boolean; companyId: number; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; created: [] }>();
const locale = useLocaleStore();

const name = ref('');
const saving = ref(false);
const generatedToken = ref<string | null>(null);

watch(() => props.open, (open) => {
    if (! open) return;
    name.value = '';
    generatedToken.value = null;
});

const canSubmit = computed(() => !! name.value.trim());

async function submit(): Promise<void> {
    if (! canSubmit.value) return;
    saving.value = true;
    try {
        const data = await apiRequest<{ token: string }>('/api/integration-api-keys', {
            method: 'POST',
            body: { company_id: props.companyId, name: name.value },
            tenant: props.tenantSlug,
        });
        generatedToken.value = data.token;
        emit('created');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        saving.value = false;
    }
}

function close(): void {
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ locale.t('erp.generateKey') }}</DialogTitle>
            </DialogHeader>

            <template v-if="! generatedToken">
                <form @submit.prevent="submit">
                    <div class="grid gap-3 py-4">
                        <Input v-model="name" :placeholder="locale.t('erp.keyName')" required />
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="saving || ! canSubmit">{{ locale.t('erp.generateKey') }}</Button>
                    </DialogFooter>
                </form>
            </template>
            <template v-else>
                <div class="grid gap-3 py-4 text-sm">
                    <p class="font-medium text-destructive">{{ locale.t('erp.tokenWarning') }}</p>
                    <CodeBlock :code="generatedToken" :label="locale.t('erp.tokenLabel')" wrap />
                </div>
                <DialogFooter>
                    <Button @click="close">{{ locale.t('erp.done') }}</Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>
