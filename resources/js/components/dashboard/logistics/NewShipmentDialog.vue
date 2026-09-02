<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input, InputGroup } from '../../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Textarea } from '../../ui/textarea';

const props = defineProps<{ open: boolean; companyId: number; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; created: [] }>();
const locale = useLocaleStore();

const senderName = ref('');
const senderPhone = ref('');
const recipientName = ref('');
const recipientPhone = ref('');
const originAddress = ref('');
const destinationAddress = ref('');
const serviceType = ref('standard');
const weightKg = ref<number | null>(null);
const price = ref<number | null>(null);
const notes = ref('');
const saving = ref(false);

watch(() => props.open, (open) => {
    if (! open) return;
    senderName.value = '';
    senderPhone.value = '';
    recipientName.value = '';
    recipientPhone.value = '';
    originAddress.value = '';
    destinationAddress.value = '';
    serviceType.value = 'standard';
    weightKg.value = null;
    price.value = null;
    notes.value = '';
});

const canSubmit = computed(() => !! senderName.value && !! senderPhone.value && !! recipientName.value && !! recipientPhone.value);

async function submit(): Promise<void> {
    if (! canSubmit.value) return;
    saving.value = true;
    try {
        await apiRequest('/api/shipments', {
            method: 'POST',
            body: {
                company_id: props.companyId,
                sender_name: senderName.value,
                sender_phone: senderPhone.value,
                recipient_name: recipientName.value,
                recipient_phone: recipientPhone.value,
                origin_address: originAddress.value || null,
                destination_address: destinationAddress.value || null,
                service_type: serviceType.value,
                weight_kg: weightKg.value,
                price: price.value,
                notes: notes.value || null,
            },
            tenant: props.tenantSlug,
        });
        toast.success(locale.t('logistics.saved'));
        emit('update:open', false);
        emit('created');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-[36.8rem]">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ locale.t('logistics.newShipment') }}</DialogTitle>
                </DialogHeader>
                <div class="grid max-h-[70vh] gap-3 overflow-y-auto py-4">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('logistics.sender') }}</p>
                    <div class="grid grid-cols-2 gap-3">
                        <Input v-model="senderName" :placeholder="locale.t('booking.customerName')" required />
                        <Input v-model="senderPhone" :placeholder="locale.t('booking.customerPhone')" required />
                    </div>
                    <Input v-model="originAddress" :placeholder="locale.t('logistics.originAddress')" />

                    <p class="mt-2 text-xs font-medium ui-subtle">{{ locale.t('logistics.recipient') }}</p>
                    <div class="grid grid-cols-2 gap-3">
                        <Input v-model="recipientName" :placeholder="locale.t('booking.customerName')" required />
                        <Input v-model="recipientPhone" :placeholder="locale.t('booking.customerPhone')" required />
                    </div>
                    <Input v-model="destinationAddress" :placeholder="locale.t('logistics.destinationAddress')" />

                    <div class="mt-2 grid grid-cols-3 gap-3">
                        <Select v-model="serviceType">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="standard">{{ locale.t('logistics.serviceTypes.standard') }}</SelectItem>
                                <SelectItem value="express">{{ locale.t('logistics.serviceTypes.express') }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputGroup v-model.number="weightKg" type="number" min="0" step="0.1" :placeholder="locale.t('logistics.weight')">
                            <template #suffix>{{ locale.t('logistics.weightUnit') }}</template>
                        </InputGroup>
                        <InputGroup v-model.number="price" type="number" min="0" step="0.01" :placeholder="locale.t('logistics.price')">
                            <template #suffix>{{ locale.t('commerce.currency') }}</template>
                        </InputGroup>
                    </div>

                    <Textarea v-model="notes" :placeholder="locale.t('booking.notes')" class="min-h-16" />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving || ! canSubmit">{{ locale.t('booking.create') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
