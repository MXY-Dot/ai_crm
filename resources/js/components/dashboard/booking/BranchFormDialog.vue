<script setup lang="ts">
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input } from '../../ui/input';

export type BranchRow = { id: number; name: string; address: string | null; phone: string | null; is_active: boolean };

const props = defineProps<{ open: boolean; branch: BranchRow | null; companyId: number; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; saved: [] }>();
const locale = useLocaleStore();

const form = ref({ name: '', address: '', phone: '' });
const saving = ref(false);

watch(() => props.open, (open) => {
    if (! open) return;
    form.value = props.branch
        ? { name: props.branch.name, address: props.branch.address ?? '', phone: props.branch.phone ?? '' }
        : { name: '', address: '', phone: '' };
});

async function submit(): Promise<void> {
    saving.value = true;
    try {
        const payload = { ...form.value, company_id: props.companyId };
        if (props.branch) {
            await apiRequest(`/api/branches/${props.branch.id}`, { method: 'PATCH', body: payload, tenant: props.tenantSlug });
        } else {
            await apiRequest('/api/branches', { method: 'POST', body: payload, tenant: props.tenantSlug });
        }
        toast.success(locale.t('booking.saved'));
        emit('update:open', false);
        emit('saved');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-sm">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ branch ? locale.t('booking.editBranch') : locale.t('booking.addBranch') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <Input v-model="form.name" :placeholder="locale.t('booking.branchName')" required />
                    <Input v-model="form.address" :placeholder="locale.t('booking.branchAddress')" />
                    <Input v-model="form.phone" :placeholder="locale.t('booking.branchPhone')" />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving">{{ locale.t('booking.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
