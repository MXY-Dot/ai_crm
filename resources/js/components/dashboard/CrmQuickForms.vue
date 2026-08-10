<script setup lang="ts">
import { reactive } from 'vue';
import { Plus } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Alert, AlertDescription } from '../ui/alert';
import { Button } from '../ui/button';
import { Card } from '../ui/card';
import { Input } from '../ui/input';
import { PhoneInput } from '../ui/phone-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select';

const props = withDefaults(defineProps<{ leadOnly?: boolean }>(), {
    leadOnly: false,
});
const emit = defineEmits<{ leadCreated: [] }>();

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { customers, leads, busy, error } = storeToRefs(store);

const customer = reactive({ name: '', phone: '', email: '', source: 'manual' });
const lead = reactive({ title: '', source: 'manual', score: 50, customer_id: 'none' });
const task = reactive({ title: '', priority: 'normal', lead_id: 'none' });

async function submitCustomer(): Promise<void> {
    await store.createCustomer(customer);
    Object.assign(customer, { name: '', phone: '', email: '', source: 'manual' });
}

async function submitLead(): Promise<void> {
    await store.createLead({
        title: lead.title,
        source: lead.source,
        score: Number(lead.score),
        customer_id: lead.customer_id !== 'none' ? Number(lead.customer_id) : null,
    });
    Object.assign(lead, { title: '', source: 'manual', score: 50, customer_id: 'none' });
    emit('leadCreated');
}

async function submitTask(): Promise<void> {
    await store.createTask({
        title: task.title,
        priority: task.priority,
        lead_id: task.lead_id !== 'none' ? Number(task.lead_id) : null,
    });
    Object.assign(task, { title: '', priority: 'normal', lead_id: 'none' });
}
</script>

<template>
    <Card :title="props.leadOnly ? locale.t('crm.newLead') : locale.t('crm.quickCreate')" :subtitle="props.leadOnly ? '' : locale.t('crm.quickCreateSubtitle')">
        <Alert v-if="error" variant="destructive" class="mb-4"><AlertDescription>{{ error }}</AlertDescription></Alert>
        <div :class="props.leadOnly ? 'max-w-md' : 'grid gap-4 xl:grid-cols-3'">
            <form v-if="!props.leadOnly" class="space-y-3" @submit.prevent="submitCustomer">
                <h3 class="font-semibold ui-text">{{ locale.t('crm.newCustomer') }}</h3>
                <Input v-model="customer.name" :placeholder="locale.t('crm.name')" required />
                <PhoneInput v-model="customer.phone" :placeholder="locale.t('crm.phone')" />
                <Input v-model="customer.email" :placeholder="locale.t('crm.email')" type="email" />
                <Button class="w-full" variant="primary" type="submit" :disabled="busy"><Plus class="h-4 w-4" />{{ locale.t('crm.createCustomer') }}</Button>
            </form>

            <form class="space-y-3" @submit.prevent="submitLead">
                <h3 class="font-semibold ui-text">{{ locale.t('crm.newLead') }}</h3>
                <Input v-model="lead.title" :placeholder="locale.t('crm.leadTitle')" required />
                <Select v-model="lead.customer_id">
                    <SelectTrigger class="w-full"><SelectValue :placeholder="locale.t('crm.noCustomer')" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">{{ locale.t('crm.noCustomer') }}</SelectItem>
                        <SelectItem v-for="item in customers" :key="item.id" :value="String(item.id)">{{ item.name }}</SelectItem>
                    </SelectContent>
                </Select>
                <Input v-model="lead.score" min="0" max="100" type="number" />
                <Button class="w-full" variant="primary" type="submit" :disabled="busy"><Plus class="h-4 w-4" />{{ locale.t('crm.createLead') }}</Button>
            </form>

            <form v-if="!props.leadOnly" class="space-y-3" @submit.prevent="submitTask">
                <h3 class="font-semibold ui-text">{{ locale.t('crm.newTask') }}</h3>
                <Input v-model="task.title" :placeholder="locale.t('crm.taskTitle')" required />
                <Select v-model="task.lead_id">
                    <SelectTrigger class="w-full"><SelectValue :placeholder="locale.t('crm.noLead')" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">{{ locale.t('crm.noLead') }}</SelectItem>
                        <SelectItem v-for="item in leads" :key="item.id" :value="String(item.id)">{{ item.title }}</SelectItem>
                    </SelectContent>
                </Select>
                <Select v-model="task.priority">
                    <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="low">{{ locale.t('tasks.priority.low') }}</SelectItem>
                        <SelectItem value="normal">{{ locale.t('tasks.priority.normal') }}</SelectItem>
                        <SelectItem value="high">{{ locale.t('tasks.priority.high') }}</SelectItem>
                        <SelectItem value="urgent">{{ locale.t('tasks.priority.urgent') }}</SelectItem>
                    </SelectContent>
                </Select>
                <Button class="w-full" variant="primary" type="submit" :disabled="busy"><Plus class="h-4 w-4" />{{ locale.t('crm.createTask') }}</Button>
            </form>
        </div>
    </Card>
</template>
