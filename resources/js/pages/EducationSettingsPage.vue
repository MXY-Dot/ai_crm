<script setup lang="ts">
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Presentation, UsersRound } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import CoursesPanel from '../components/dashboard/education/CoursesPanel.vue';
import CourseGroupsPanel from '../components/dashboard/education/CourseGroupsPanel.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useLocaleStore } from '../stores/locale';

defineOptions({ layout: AppLayout });

const locale = useLocaleStore();
const store = useCrmDashboardStore();
const { company, tenant } = storeToRefs(store);
const activeTab = ref('courses');

const companyId = computed(() => company.value?.id ?? null);
const ready = computed(() => !! companyId.value);
const tenantSlug = computed(() => tenant.value?.slug ?? '');
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">{{ locale.t('education.settingsTitle') }}</h2>
            <p class="mt-1 text-sm ui-subtle">{{ locale.t('education.settingsSubtitle') }}</p>
        </div>

        <Tabs v-if="ready" v-model="activeTab">
            <TooltipProvider :delay-duration="200">
                <TabsList class="flex-wrap">
                    <Tooltip v-for="t in [
                        { value: 'courses', icon: Presentation, label: locale.t('education.tabCourses') },
                        { value: 'groups', icon: UsersRound, label: locale.t('education.tabGroups') },
                    ]" :key="t.value"
                    >
                        <TooltipTrigger as-child>
                            <TabsTrigger :value="t.value" :aria-label="t.label">
                                <component :is="t.icon" class="h-4 w-4" />
                            </TabsTrigger>
                        </TooltipTrigger>
                        <TooltipContent>{{ t.label }}</TooltipContent>
                    </Tooltip>
                </TabsList>
            </TooltipProvider>

            <TabsContent value="courses" class="mt-4">
                <CoursesPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
            </TabsContent>
            <TabsContent value="groups" class="mt-4">
                <CourseGroupsPanel :company-id="companyId as number" :tenant-slug="tenantSlug" />
            </TabsContent>
        </Tabs>
    </section>
</template>
