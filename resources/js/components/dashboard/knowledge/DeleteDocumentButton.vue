<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { Button } from '../../ui/button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '../../ui/alert-dialog';

const props = defineProps<{ documentId: number; title: string }>();
const store = useCrmDashboardStore();

async function confirmDelete(): Promise<void> {
    await store.deleteKnowledgeDocument(props.documentId);
}
</script>

<template>
    <AlertDialog>
        <AlertDialogTrigger as-child>
            <Button size="icon-sm" variant="ghost" type="button" class="text-destructive hover:bg-destructive/10" aria-label="Удалить документ" @click.stop>
                <Trash2 class="h-4 w-4" />
            </Button>
        </AlertDialogTrigger>
        <AlertDialogContent @click.stop>
            <AlertDialogHeader>
                <AlertDialogTitle>Удалить «{{ title }}»?</AlertDialogTitle>
                <AlertDialogDescription>Документ и все его проиндексированные фрагменты будут удалены без возможности восстановления.</AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Отмена</AlertDialogCancel>
                <AlertDialogAction :disabled="store.busy" @click="confirmDelete">Удалить</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
