<script setup lang="ts">
import { computed, watch } from 'vue';
import { storeToRefs } from 'pinia';
import CrmQuickForms from '../components/dashboard/CrmQuickForms.vue';
import CustomerList from '../components/dashboard/CustomerList.vue';
import CustomerProfilePanel from '../components/dashboard/CustomerProfilePanel.vue';
import LeadDetailPanel from '../components/dashboard/LeadDetailPanel.vue';
import LeadPipeline from '../components/dashboard/LeadPipeline.vue';
import TaskList from '../components/dashboard/TaskList.vue';
import type { Lead } from '../stores/crmDashboard';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const store = useCrmDashboardStore();
const { customers, conversations, leads, tasks, selectedCustomerId, selectedLeadId, selectedCustomer, selectedLead } = storeToRefs(store);

watch(customers, (items) => { if (! selectedCustomerId.value && items[0]) selectedCustomerId.value = items[0].id; });
watch(leads, (items) => { if (! selectedLeadId.value && items[0]) selectedLeadId.value = items[0].id; });

const customerLeads = computed(() => leads.value.filter((lead) => lead.customer_id === selectedCustomerId.value));
const customerConversations = computed(() => conversations.value.filter((conversation) => conversation.customer?.id === selectedCustomerId.value));
const leadTasks = computed(() => tasks.value.filter((task) => task.lead_id === selectedLeadId.value));
const leadConversations = computed(() => conversations.value.filter((conversation) => conversation.lead?.id === selectedLeadId.value));
const leadCustomer = computed(() => customers.value.find((customer) => customer.id === selectedLead.value?.customer_id) ?? null);

function selectLead(lead: Lead): void {
    selectedLeadId.value = lead.id;
    if (lead.customer_id) selectedCustomerId.value = lead.customer_id;
}
</script>

<template>
    <section class="space-y-6">
        <CrmQuickForms />
        <div class="grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <CustomerList :customers="customers" :selected-id="selectedCustomerId" @select="selectedCustomerId = $event" />
            <CustomerProfilePanel :customer="selectedCustomer" :leads="customerLeads" :conversations="customerConversations" />
        </div>
        <div class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
            <LeadPipeline :selected-id="selectedLeadId" @select="selectLead" />
            <LeadDetailPanel :lead="selectedLead" :customer="leadCustomer" :tasks="leadTasks" :conversations="leadConversations" />
        </div>
        <TaskList :tasks="tasks" />
    </section>
</template>