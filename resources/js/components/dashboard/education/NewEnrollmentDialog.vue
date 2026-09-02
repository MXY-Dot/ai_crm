<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input } from '../../ui/input';

const props = defineProps<{ open: boolean; courseGroupId: number | null; companyId: number; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; enrolled: [] }>();
const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { customers } = storeToRefs(store);

const customerMode = ref<'existing' | 'new'>('existing');
const customerId = ref<number | null>(null);
const customerName = ref('');
const customerPhone = ref('');
const saving = ref(false);

watch(() => props.open, (open) => {
    if (! open) return;
    customerMode.value = 'existing';
    customerId.value = null;
    customerName.value = '';
    customerPhone.value = '';
});

const filteredCustomers = computed(() => {
    const q = customerPhone.value.trim();
    if (! q) return [];
    return (customers.value as unknown as Array<{ id: number; name: string; phone: string | null }>).filter((c) => c.phone?.includes(q)).slice(0, 5);
});

const canSubmit = computed(() => !! props.courseGroupId && (customerMode.value === 'existing' ? !! customerId.value : !! customerName.value && !! customerPhone.value));

async function submit(): Promise<void> {
    if (! canSubmit.value) return;
    saving.value = true;
    try {
        await apiRequest('/api/enrollments', {
            method: 'POST',
            body: {
                company_id: props.companyId,
                course_group_id: props.courseGroupId,
                ...(customerMode.value === 'existing'
                    ? { customer_id: customerId.value }
                    : { customer_name: customerName.value, customer_phone: customerPhone.value }),
            },
            tenant: props.tenantSlug,
        });
        toast.success(locale.t('education.saved'));
        emit('update:open', false);
        emit('enrolled');
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
                    <DialogTitle>{{ locale.t('education.enrollStudent') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <div class="flex gap-2 text-xs">
                        <button type="button" class="rounded-md border px-2 py-1" :class="customerMode === 'existing' ? 'border-primary text-primary' : 'border-border ui-subtle'" @click="customerMode = 'existing'">{{ locale.t('booking.existingCustomer') }}</button>
                        <button type="button" class="rounded-md border px-2 py-1" :class="customerMode === 'new' ? 'border-primary text-primary' : 'border-border ui-subtle'" @click="customerMode = 'new'">{{ locale.t('booking.customerName') }}</button>
                    </div>

                    <template v-if="customerMode === 'existing'">
                        <Input v-model="customerPhone" :placeholder="locale.t('booking.customerPhone')" />
                        <div v-if="filteredCustomers.length" class="grid gap-1">
                            <button
                                v-for="c in filteredCustomers" :key="c.id" type="button"
                                class="rounded-md border px-2 py-1 text-left text-xs"
                                :class="customerId === c.id ? 'border-primary bg-primary/10' : 'border-border'"
                                @click="customerId = c.id"
                            >{{ c.name }} · {{ c.phone }}</button>
                        </div>
                    </template>
                    <template v-else>
                        <Input v-model="customerName" :placeholder="locale.t('booking.customerName')" />
                        <Input v-model="customerPhone" :placeholder="locale.t('booking.customerPhone')" />
                    </template>
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="saving || ! canSubmit">{{ locale.t('education.enrollStudent') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
