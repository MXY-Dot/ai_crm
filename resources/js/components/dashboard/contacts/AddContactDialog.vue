<script setup lang="ts">
import { reactive, ref } from 'vue';
import { Plus, UserRound } from '@lucide/vue';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '../../ui/dialog';
import { Input } from '../../ui/input';
import { PhoneInput } from '../../ui/phone-input';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const open = ref(false);
const form = reactive({ name: '', phone: '', email: '' });

async function submit(): Promise<void> {
    if (! form.name.trim()) return;

    await store.createCustomer({
        name: form.name.trim(),
        phone: form.phone.trim() || undefined,
        email: form.email.trim() || undefined,
    });

    Object.assign(form, { name: '', phone: '', email: '' });
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button size="sm" variant="primary" type="button"><Plus class="h-4 w-4" />{{ locale.t('contacts.add') }}</Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><UserRound class="h-4 w-4 text-primary" />{{ locale.t('contacts.add') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('crm.name') }}</span>
                        <Input v-model="form.name" required />
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('crm.phone') }}</span>
                        <PhoneInput v-model="form.phone" />
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('crm.email') }}</span>
                        <Input v-model="form.email" type="email" />
                    </label>
                </div>
                <DialogFooter>
                    <Button type="submit" variant="primary" :disabled="store.busy || !form.name.trim()">{{ locale.t('contacts.add') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
