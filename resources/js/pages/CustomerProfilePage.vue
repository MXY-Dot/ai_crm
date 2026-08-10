<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import { Mail, Phone, Star } from '@lucide/vue';
import { Badge } from '../components/ui/badge';
import { Avatar, AvatarFallback } from '../components/ui/avatar';
import { Card } from '../components/ui/card';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const store = useCrmDashboardStore();
const { customers, conversations, leads, selectedCustomerId } = storeToRefs(store);
const customer = computed(() => customers.value.find((item) => item.id === selectedCustomerId.value) ?? customers.value[0] ?? null);
const customerLeads = computed(() => leads.value.filter((lead) => lead.customer_id === customer.value?.id));
const customerConversations = computed(() => conversations.value.filter((conversation) => conversation.customer?.id === customer.value?.id));

defineOptions({ layout: AppLayout });
</script>

<template>
    <Card title="Профиль клиента" subtitle="Полный контекст клиента для операторов и AI.">
        <div v-if="customer" class="grid gap-5 xl:grid-cols-[0.65fr_1fr]">
            <section class="rounded-xl border p-5" style="border-color: var(--border); background: var(--muted)">
                <div class="flex items-center gap-4">
                    <Avatar class="size-20"><AvatarFallback class="text-2xl font-semibold" style="background: var(--primary); color: var(--primary-foreground)">{{ customer.name[0] }}</AvatarFallback></Avatar>
                    <div>
                        <h2 class="font-display text-xl font-semibold ui-text">{{ customer.name }}</h2>
                        <Badge tone="green">Активный клиент</Badge>
                    </div>
                </div>
                <div class="mt-5 space-y-3 text-sm ui-subtle">
                    <p class="flex items-center gap-2"><Phone class="h-4 w-4" />{{ customer.phone ?? 'Нет телефона' }}</p>
                    <p class="flex items-center gap-2"><Mail class="h-4 w-4" />{{ customer.email ?? 'Нет email' }}</p>
                    <p class="flex items-center gap-2"><Star class="h-4 w-4" />{{ customerLeads.length }} связанных лидов</p>
                </div>
            </section>
            <section class="rounded-xl border p-5" style="border-color: var(--border); background: var(--muted)">
                <h3 class="font-display font-semibold ui-text">Активность</h3>
                <div class="mt-4 divide-y" style="border-color: var(--border)">
                    <p v-for="item in [...customerConversations, ...customerLeads].slice(0, 5)" :key="`${'subject' in item ? 'c' : 'l'}-${item.id}`" class="py-3 text-sm ui-subtle">{{ 'subject' in item ? item.subject : item.title }}</p>
                    <p v-if="! customerConversations.length && ! customerLeads.length" class="py-3 text-sm ui-subtle">Активности пока нет</p>
                </div>
            </section>
        </div>
        <p v-else class="text-sm ui-subtle">Клиент не найден</p>
    </Card>
</template>
