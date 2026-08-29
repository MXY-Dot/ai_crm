<script setup lang="ts">
import { ref } from 'vue';
import { FlaskConical, Pencil, Trash2, X } from '@lucide/vue';
import CreateAgentDialog from './CreateAgentDialog.vue';
import AiAgentActivityPanel from './AiAgentActivityPanel.vue';
import type { AiAgent, AiRun } from '../../../stores/crmDashboard';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { sourceLabels } from '../../../lib/statusLabels';
import { Button } from '../../ui/button';
import { Drawer, DrawerClose, DrawerContent, DrawerHeader, DrawerTitle } from '../../ui/drawer';

const props = defineProps<{ agents: AiAgent[]; aiRuns: AiRun[]; selectedId: number | null }>();
const emit = defineEmits<{ select: [id: number] }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();

function stats(agent: AiAgent) {
    const runs = props.aiRuns.filter((run) => run.agent?.id === agent.id);
    const avgConfidence = runs.length ? Math.round(runs.reduce((sum, run) => sum + run.confidence, 0) / runs.length) : null;

    return { count: runs.length, avgConfidence };
}

const statusTone: Record<string, string> = {
    active: 'text-primary',
    paused: 'ui-subtle',
    disabled: 'text-destructive',
};

const CHANNEL_STYLES: Record<string, string> = {
    telegram: 'bg-brand-telegram/15 text-brand-telegram',
    whatsapp: 'bg-brand-whatsapp/15 text-brand-whatsapp',
    instagram: 'bg-brand-instagram-to/15 text-brand-instagram-to',
    website: 'bg-sky-600/15 text-sky-700 dark:text-sky-300',
    chatwoot: 'bg-emerald-600/15 text-emerald-700 dark:text-emerald-300',
};

// "Последние запуски" is shown per agent via a slide-out Drawer instead of a
// permanently-docked third column — one shared Drawer instance driven by
// activityAgent, rather than one per table row.
const activityAgent = ref<AiAgent | null>(null);
const activityOpen = ref(false);

function openActivity(agent: AiAgent): void {
    activityAgent.value = agent;
    activityOpen.value = true;
}

async function remove(agent: AiAgent): Promise<void> {
    if (! confirm(`Удалить ассистента «${agent.name}»? История его запусков тоже будет удалена.`)) return;
    await store.deleteAiAgent(agent.id);
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between">
            <h2 class="font-display text-base font-semibold ui-text">Ассистенты</h2>
            <CreateAgentDialog />
        </div>

        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs uppercase tracking-wide ui-subtle">
                            <th class="py-2.5 pl-4 pr-3 font-semibold">Агент</th>
                            <th class="py-2.5 pr-3 font-semibold">Каналы</th>
                            <th class="py-2.5 pr-3 font-semibold">Статус</th>
                            <th class="py-2.5 pr-3 font-semibold">Запуски</th>
                            <th class="py-2.5 pr-4 text-right font-semibold">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="agent in agents"
                            :key="agent.id"
                            class="cursor-pointer border-l-4 transition"
                            :class="selectedId === agent.id ? 'border-primary bg-muted' : 'border-transparent hover:bg-muted'"
                            @click="emit('select', agent.id)"
                        >
                            <td class="py-2.5 pl-4 pr-3">
                                <p class="font-semibold ui-text">{{ agent.name }}</p>
                                <p class="text-xs ui-subtle">{{ agent.provider }}<span v-if="agent.model"> · {{ agent.model }}</span></p>
                            </td>
                            <td class="py-2.5 pr-3">
                                <div v-if="agent.channels?.length" class="flex flex-wrap gap-1">
                                    <span v-for="channel in agent.channels" :key="channel" class="rounded px-1.5 py-0.5 text-[10px] font-semibold" :class="CHANNEL_STYLES[channel] ?? 'bg-muted ui-subtle'">{{ sourceLabels[channel] ?? channel }}</span>
                                </div>
                                <span v-else class="text-xs ui-subtle">Запасной</span>
                            </td>
                            <td class="py-2.5 pr-3">
                                <span class="rounded px-2 py-0.5 text-[10px] font-semibold bg-muted" :class="statusTone[agent.status] ?? 'ui-subtle'">{{ locale.t('ai.status.' + agent.status) }}</span>
                            </td>
                            <td class="py-2.5 pr-3 font-mono text-xs ui-subtle">
                                {{ stats(agent).count }}<span v-if="stats(agent).avgConfidence !== null" class="ml-1.5 text-primary">{{ stats(agent).avgConfidence }}%</span>
                            </td>
                            <td class="py-2.5 pr-4" @click.stop>
                                <div class="flex items-center justify-end gap-1">
                                    <Button variant="ghost" size="icon-xs" title="Последние запуски" aria-label="Последние запуски" @click="openActivity(agent)">
                                        <FlaskConical class="h-4 w-4" />
                                    </Button>
                                    <Button variant="ghost" size="icon-xs" title="Открыть настройки" aria-label="Открыть настройки" @click="emit('select', agent.id)">
                                        <Pencil class="h-4 w-4" />
                                    </Button>
                                    <Button variant="destructive" size="icon-xs" title="Удалить" aria-label="Удалить" @click="remove(agent)">
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="! agents.length" class="p-6 text-center text-sm ui-subtle">Нет ассистентов</p>
        </div>

        <Drawer v-model:open="activityOpen" direction="right">
            <DrawerContent class="sm:max-w-md">
                <DrawerHeader class="flex-row items-center justify-between">
                    <DrawerTitle>{{ activityAgent?.name }}</DrawerTitle>
                    <DrawerClose as-child>
                        <Button variant="ghost" size="icon-xs" aria-label="Закрыть"><X class="h-4 w-4" /></Button>
                    </DrawerClose>
                </DrawerHeader>
                <div class="flex-1 overflow-y-auto p-4">
                    <AiAgentActivityPanel :agent="activityAgent" :ai-runs="aiRuns" />
                </div>
            </DrawerContent>
        </Drawer>
    </div>
</template>
