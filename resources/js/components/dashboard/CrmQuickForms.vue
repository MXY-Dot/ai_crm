<script setup lang="ts">
import { reactive } from 'vue';
import { Plus } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Button } from '../ui/button';
import { Card } from '../ui/card';

const props = withDefaults(defineProps<{ leadOnly?: boolean }>(), {
    leadOnly: false,
});
const emit = defineEmits<{ leadCreated: [] }>();

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { customers, leads, busy, error } = storeToRefs(store);

const customer = reactive({ name: '', phone: '', email: '', source: 'manual' });
const lead = reactive({ title: '', source: 'manual', score: 50, customer_id: '' });
const task = reactive({ title: '', priority: 'normal', lead_id: '' });

async function submitCustomer(): Promise<void> {
    await store.createCustomer(customer);
    Object.assign(customer, { name: '', phone: '', email: '', source: 'manual' });
}

async function submitLead(): Promise<void> {
    await store.createLead({
        title: lead.title,
        source: lead.source,
        score: Number(lead.score),
        customer_id: lead.customer_id ? Number(lead.customer_id) : null,
    });
    Object.assign(lead, { title: '', source: 'manual', score: 50, customer_id: '' });
    emit('leadCreated');
}

async function submitTask(): Promise<void> {
    await store.createTask({
        title: task.title,
        priority: task.priority,
        lead_id: task.lead_id ? Number(task.lead_id) : null,
    });
    Object.assign(task, { title: '', priority: 'normal', lead_id: '' });
}
</script>

<template>
    <Card :title="props.leadOnly ? locale.t('crm.newLead') : locale.t('crm.quickCreate')" :subtitle="props.leadOnly ? '' : locale.t('crm.quickCreateSubtitle')">
        <p v-if="error" class="mb-4 rounded-md border border-red-300/30 bg-red-300/10 p-3 text-sm text-red-100">{{ error }}</p>
        <div :class="props.leadOnly ? 'max-w-md' : 'grid gap-4 xl:grid-cols-3'">
            <form v-if="!props.leadOnly" class="space-y-3" @submit.prevent="submitCustomer">
                <h3 class="font-semibold text-white">{{ locale.t('crm.newCustomer') }}</h3>
                <input v-model="customer.name" class="h-10 w-full rounded-md border border-white/10 bg-white/5 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300" :placeholder="locale.t('crm.name')" required>
                <input v-model="customer.phone" class="h-10 w-full rounded-md border border-white/10 bg-white/5 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300" :placeholder="locale.t('crm.phone')">
                <input v-model="customer.email" class="h-10 w-full rounded-md border border-white/10 bg-white/5 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300" :placeholder="locale.t('crm.email')" type="email">
                <Button class="w-full" variant="primary" type="submit" :disabled="busy"><Plus class="h-4 w-4" />{{ locale.t('crm.createCustomer') }}</Button>
            </form>

            <form class="space-y-3" @submit.prevent="submitLead">
                <h3 class="font-semibold text-white">{{ locale.t('crm.newLead') }}</h3>
                <input v-model="lead.title" class="h-10 w-full rounded-md border border-white/10 bg-white/5 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300" :placeholder="locale.t('crm.leadTitle')" required>
                <select v-model="lead.customer_id" class="h-10 w-full rounded-md border border-white/10 bg-zinc-900 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300">
                    <option value="">{{ locale.t('crm.noCustomer') }}</option>
                    <option v-for="item in customers" :key="item.id" :value="item.id">{{ item.name }}</option>
                </select>
                <input v-model="lead.score" class="h-10 w-full rounded-md border border-white/10 bg-white/5 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300" min="0" max="100" type="number">
                <Button class="w-full" variant="primary" type="submit" :disabled="busy"><Plus class="h-4 w-4" />{{ locale.t('crm.createLead') }}</Button>
            </form>

            <form v-if="!props.leadOnly" class="space-y-3" @submit.prevent="submitTask">
                <h3 class="font-semibold text-white">{{ locale.t('crm.newTask') }}</h3>
                <input v-model="task.title" class="h-10 w-full rounded-md border border-white/10 bg-white/5 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300" :placeholder="locale.t('crm.taskTitle')" required>
                <select v-model="task.lead_id" class="h-10 w-full rounded-md border border-white/10 bg-zinc-900 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300">
                    <option value="">{{ locale.t('crm.noLead') }}</option>
                    <option v-for="item in leads" :key="item.id" :value="item.id">{{ item.title }}</option>
                </select>
                <select v-model="task.priority" class="h-10 w-full rounded-md border border-white/10 bg-zinc-900 px-3 text-sm outline-none focus:ring-2 focus:ring-emerald-300">
                    <option value="low">{{ locale.t('tasks.priority.low') }}</option>
                    <option value="normal">{{ locale.t('tasks.priority.normal') }}</option>
                    <option value="high">{{ locale.t('tasks.priority.high') }}</option>
                    <option value="urgent">{{ locale.t('tasks.priority.urgent') }}</option>
                </select>
                <Button class="w-full" variant="primary" type="submit" :disabled="busy"><Plus class="h-4 w-4" />{{ locale.t('crm.createTask') }}</Button>
            </form>
        </div>
    </Card>
</template>