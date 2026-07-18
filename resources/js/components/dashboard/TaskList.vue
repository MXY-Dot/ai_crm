<script setup lang="ts">
import { computed, ref } from 'vue';
import { Clock3 } from '@lucide/vue';
import { useLocaleStore } from '../../stores/locale';
import { useCrmDashboardStore, type Task } from '../../stores/crmDashboard';
import { Badge } from '../ui/badge';
import { Card } from '../ui/card';

const props = defineProps<{ tasks: Task[] }>();
const locale = useLocaleStore();
const store = useCrmDashboardStore();
const priority = ref('all');
const statuses = ['open', 'in_progress', 'done'];
const priorities = ['all', 'low', 'normal', 'high', 'urgent'];

const filteredTasks = computed(() => priority.value === 'all'
    ? props.tasks
    : props.tasks.filter((task) => task.priority === priority.value));

function tasksBy(status: string): Task[] {
    return filteredTasks.value.filter((task) => task.status === status);
}

function tone(priority: string): 'red' | 'amber' | 'neutral' {
    if (priority === 'urgent') return 'red';
    if (priority === 'high') return 'amber';
    return 'neutral';
}
</script>

<template>
    <Card :title="locale.t('tasks.title')" :subtitle="locale.t('tasks.subtitle')">
        <template #actions>
            <select v-model="priority" class="h-9 rounded-md border border-white/10 bg-zinc-900 px-3 text-sm text-zinc-200 outline-none focus:ring-2 focus:ring-emerald-300">
                <option v-for="item in priorities" :key="item" :value="item">{{ item === 'all' ? locale.t('tasks.allPriorities') : locale.t(`tasks.priority.${item}`) }}</option>
            </select>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <section v-for="status in statuses" :key="status" class="rounded-md border border-white/10 bg-white/[0.03] p-3">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <p class="font-medium text-white">{{ locale.t(`tasks.status.${status}`) }}</p>
                    <Badge>{{ tasksBy(status).length }}</Badge>
                </div>
                <div class="space-y-3">
                    <p v-if="tasksBy(status).length === 0" class="rounded-md border border-dashed border-white/10 p-3 text-sm text-zinc-500">{{ locale.t('tasks.emptyColumn') }}</p>
                    <article v-for="task in tasksBy(status)" :key="task.id" class="rounded-md border border-white/10 bg-zinc-950/40 p-3">
                        <div class="flex items-start justify-between gap-3">
                            <p class="font-medium text-white">{{ task.title }}</p>
                            <Badge :tone="tone(task.priority)">{{ locale.t(`tasks.priority.${task.priority}`) }}</Badge>
                        </div>
                        <p class="mt-2 flex items-center gap-2 text-sm text-zinc-400"><Clock3 class="h-4 w-4" />{{ locale.t(`tasks.status.${task.status}`) }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button v-if="task.status !== 'in_progress'" class="rounded-sm border border-white/10 px-2 py-1 text-xs text-zinc-300 hover:bg-white/10" @click="store.updateTaskStatus(task.id, 'in_progress')">{{ locale.t('crm.startTask') }}</button>
                            <button v-if="task.status !== 'done'" class="rounded-sm border border-white/10 px-2 py-1 text-xs text-zinc-300 hover:bg-white/10" @click="store.updateTaskStatus(task.id, 'done')">{{ locale.t('crm.markDone') }}</button>
                        </div>
                    </article>
                </div>
            </section>
        </div>
    </Card>
</template>