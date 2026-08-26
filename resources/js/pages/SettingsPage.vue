<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Bell, Blocks, Building2, Globe2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import CompanyProfilePanel from '../components/dashboard/CompanyProfilePanel.vue';
import NotificationPreferencesPanel from '../components/dashboard/NotificationPreferencesPanel.vue';
import WidgetTokensPanel from '../components/dashboard/WidgetTokensPanel.vue';
import CompanyModulesPanel from '../components/dashboard/CompanyModulesPanel.vue';

defineOptions({ layout: AppLayout });

const activeTab = ref('company');

/**
 * The tabs bar sticks below AppLayout's own sticky header, whose height
 * varies by breakpoint (single-row on mobile/xl, two-row on lg) — so the
 * offset is measured from the real header element instead of hardcoded.
 */
const stickyTop = ref(0);
let headerObserver: ResizeObserver | null = null;

onMounted(() => {
    const header = document.querySelector('main > header');
    if (! header) return;

    headerObserver = new ResizeObserver(([entry]) => {
        stickyTop.value = entry.contentRect.height;
    });
    headerObserver.observe(header);
});

onBeforeUnmount(() => headerObserver?.disconnect());
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">Настройки компании</h2>
            <p class="mt-1 text-sm ui-subtle">Управляйте общими параметрами вашего рабочего пространства.</p>
        </div>

        <Tabs v-model="activeTab">
            <div class="sticky z-[5] -mt-2 border-b border-border bg-background pt-2 pb-3" :style="{ top: `${stickyTop}px` }">
                <TabsList class="flex-wrap">
                    <TabsTrigger value="company"><Building2 class="h-4 w-4" />Компания</TabsTrigger>
                    <TabsTrigger value="notifications"><Bell class="h-4 w-4" />Уведомления</TabsTrigger>
                    <TabsTrigger value="widget"><Globe2 class="h-4 w-4" />Токены виджета</TabsTrigger>
                    <TabsTrigger value="modules"><Blocks class="h-4 w-4" />Модули</TabsTrigger>
                </TabsList>
            </div>

            <TabsContent value="company" class="mt-4">
                <CompanyProfilePanel data-tour="settings-company" />
            </TabsContent>

            <TabsContent value="notifications" class="mt-4">
                <NotificationPreferencesPanel data-tour="settings-notify" />
            </TabsContent>

            <TabsContent value="widget" class="mt-4">
                <WidgetTokensPanel data-tour="settings-widget-tokens" />
            </TabsContent>

            <TabsContent value="modules" class="mt-4">
                <CompanyModulesPanel />
            </TabsContent>
        </Tabs>
    </section>
</template>
