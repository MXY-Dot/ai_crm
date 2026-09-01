<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { Globe2, Plus, Trash2 } from '@lucide/vue';
import { useCrmDashboardStore } from '@/stores/crmDashboard';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { CodeBlock } from '@/components/ui/code-block';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';

const store = useCrmDashboardStore();
const { widgetTokens, busy } = storeToRefs(store);

const loading = ref(true);
const newLabel = ref('');
const creating = ref(false);
const deleteOpen = ref(false);
const deleteTarget = ref<{ id: number; label: string } | null>(null);

onMounted(async () => {
    loading.value = true;
    try {
        await store.loadWidgetTokens();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить токены');
    } finally {
        loading.value = false;
    }
});

async function createToken(): Promise<void> {
    const label = newLabel.value.trim();
    if (! label) return;

    creating.value = true;
    try {
        await store.createWidgetToken(label);
        newLabel.value = '';
        toast.success('Токен создан');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось создать токен');
    } finally {
        creating.value = false;
    }
}

function askDelete(id: number, label: string): void {
    deleteTarget.value = { id, label };
    deleteOpen.value = true;
}

async function confirmDelete(): Promise<void> {
    if (! deleteTarget.value) return;

    try {
        await store.deleteWidgetToken(deleteTarget.value.id);
        toast.success('Токен удалён');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось удалить токен');
    } finally {
        deleteOpen.value = false;
        deleteTarget.value = null;
    }
}

function formatDate(value: string | null): string {
    return value ? new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value)) : 'ещё не использовался';
}
</script>

<template>
    <Card title="Токены виджета" subtitle="Создайте отдельный токен для каждого сайта или страницы — все они ведут в один и тот же чат компании.">
        <div class="space-y-4">
            <form class="flex gap-2" @submit.prevent="createToken">
                <Input v-model="newLabel" placeholder="Например: Основной сайт" maxlength="120" />
                <Button size="sm" variant="primary" type="submit" :disabled="creating || ! newLabel.trim()">
                    <Plus class="h-4 w-4" />Создать
                </Button>
            </form>

            <div v-if="loading" class="grid gap-2">
                <Skeleton v-for="i in 2" :key="i" class="h-20 rounded-lg" />
            </div>
            <p v-else-if="! widgetTokens.length" class="rounded-lg border border-dashed border-border p-4 text-center text-sm ui-subtle">
                <Globe2 class="mx-auto mb-2 h-5 w-5 ui-subtle" />
                Токенов пока нет — создайте первый, чтобы получить код для вставки на сайт.
            </p>
            <div v-else class="space-y-3">
                <article v-for="token in widgetTokens" :key="token.id" class="rounded-lg border p-3 border-border bg-card">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <span class="font-medium ui-text">{{ token.label }}</span>
                        <Button size="icon" variant="ghost" :disabled="busy" @click="askDelete(token.id, token.label)">
                            <Trash2 class="h-4 w-4 text-destructive" />
                        </Button>
                    </div>
                    <CodeBlock :code="token.embed_snippet" label="Код для вставки" wrap />
                    <p class="mt-2 text-right text-xs ui-subtle">Использован: {{ formatDate(token.last_used_at) }}</p>
                </article>
            </div>
        </div>
    </Card>

    <AlertDialog v-model:open="deleteOpen">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Удалить токен «{{ deleteTarget?.label }}»?</AlertDialogTitle>
                <AlertDialogDescription>
                    Виджет на сайте, использующем этот код, перестанет работать. Действие необратимо.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Отмена</AlertDialogCancel>
                <AlertDialogAction variant="destructive" @click="confirmDelete">Удалить</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
