<script setup lang="ts">
import { ref } from 'vue';
import { XCircle } from '@lucide/vue';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '../../ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Textarea } from '../../ui/textarea';

const props = defineProps<{ leadId: number }>();
const store = useCrmDashboardStore();

const open = ref(false);
const reasonPreset = ref('price');
const reasonNote = ref('');

/** ЭТАП 9.6/19.6 — same objection categories the AI's own Objection Engine instruction already handles (price/hesitation/competitor), extended with the outcomes those objections lead to when the deal is actually lost. */
const PRESETS: Record<string, string> = {
    price: 'Дорого',
    no_response: 'Перестал отвечать',
    competitor: 'Выбрал конкурента',
    changed_mind: 'Передумал',
    other: 'Другое',
};

async function submit(): Promise<void> {
    const reason = reasonPreset.value === 'other' && reasonNote.value.trim()
        ? reasonNote.value.trim()
        : PRESETS[reasonPreset.value];

    await store.updateLeadStatus(props.leadId, 'lost', reason);
    open.value = false;
    reasonNote.value = '';
    reasonPreset.value = 'price';
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <button
                type="button"
                class="rounded-md border px-2 py-1 text-[11px] font-medium text-destructive hover:bg-destructive/10 disabled:opacity-50 border-border"
                :disabled="store.busy"
                @click.stop
            >
                Проиграна
            </button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-sm" @click.stop>
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><XCircle class="h-4 w-4 text-destructive" />Почему сделка не состоялась?</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <Select v-model="reasonPreset">
                        <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="(label, key) in PRESETS" :key="key" :value="key">{{ label }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <Textarea v-if="reasonPreset === 'other'" v-model="reasonNote" placeholder="Опишите причину" class="min-h-20" />
                </div>
                <DialogFooter>
                    <Button type="submit" variant="destructive" :disabled="store.busy">Отметить проигранной</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
