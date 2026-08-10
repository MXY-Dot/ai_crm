<script setup lang="ts">
import { reactive, ref } from 'vue';
import { Bot, Plus } from '@lucide/vue';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '../../ui/dialog';
import { Input } from '../../ui/input';
import { Textarea } from '../../ui/textarea';

const store = useCrmDashboardStore();
const open = ref(false);
const form = reactive({ name: '', instructions: '', handoff_threshold: 70 });

async function submit(): Promise<void> {
    if (! form.name.trim()) return;

    await store.createAiAgent({
        name: form.name.trim(),
        instructions: form.instructions.trim() || undefined,
        handoff_threshold: Number(form.handoff_threshold),
    });

    Object.assign(form, { name: '', instructions: '', handoff_threshold: 70 });
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button size="icon-sm" variant="outline" type="button" aria-label="Создать ассистента">
                <Plus class="h-4 w-4" />
            </Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><Bot class="h-4 w-4 text-primary" />Новый AI-ассистент</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 py-4">
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Название</span>
                        <Input v-model="form.name" placeholder="Например, Sales Assistant" required />
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Инструкции</span>
                        <Textarea v-model="form.instructions" class="min-h-28" placeholder="Как ассистент должен отвечать клиентам" />
                    </label>
                    <label class="max-w-40">
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Порог handoff</span>
                        <Input v-model="form.handoff_threshold" type="number" min="1" max="100" />
                    </label>
                </div>
                <DialogFooter>
                    <Button type="submit" variant="primary" :disabled="store.busy || !form.name.trim()">Создать</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
