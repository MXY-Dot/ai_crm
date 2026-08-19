<script setup lang="ts">
import { ref } from 'vue';
import { Bell, Building2, Globe2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import CompanyProfilePanel from '../components/dashboard/CompanyProfilePanel.vue';
import NotificationPreferencesPanel from '../components/dashboard/NotificationPreferencesPanel.vue';
import WidgetTokensPanel from '../components/dashboard/WidgetTokensPanel.vue';

defineOptions({ layout: AppLayout });

const activeTab = ref('company');
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">Настройки компании</h2>
            <p class="mt-1 text-sm ui-subtle">Управляйте общими параметрами вашего рабочего пространства.</p>
        </div>

        <Tabs v-model="activeTab">
            <TabsList class="flex-wrap">
                <TabsTrigger value="company"><Building2 class="h-4 w-4" />Компания</TabsTrigger>
                <TabsTrigger value="notifications"><Bell class="h-4 w-4" />Уведомления</TabsTrigger>
                <TabsTrigger value="widget"><Globe2 class="h-4 w-4" />Токены виджета</TabsTrigger>
            </TabsList>

            <TabsContent value="company" class="mt-4">
                <CompanyProfilePanel data-tour="settings-company" />
            </TabsContent>

            <TabsContent value="notifications" class="mt-4">
                <NotificationPreferencesPanel data-tour="settings-notify" />
            </TabsContent>

            <TabsContent value="widget" class="mt-4">
                <WidgetTokensPanel data-tour="settings-widget-tokens" />
            </TabsContent>
        </Tabs>
    </section>
</template>
