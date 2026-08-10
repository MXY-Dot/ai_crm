<script setup lang="ts">
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { FileImage, FileSpreadsheet } from '@lucide/vue';
import type { Conversation } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';

const props = defineProps<{ target: HTMLElement | null; conversations: Conversation[] }>();
const locale = useLocaleStore();
const exporting = ref(false);

function downloadBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}

async function exportImage(): Promise<void> {
    if (! props.target || exporting.value) return;

    exporting.value = true;
    try {
        const { default: html2canvas } = await import('html2canvas-pro');
        const canvas = await html2canvas(props.target, { backgroundColor: null, scale: 2 });

        canvas.toBlob((blob) => {
            if (blob) downloadBlob(blob, `analytics-${new Date().toISOString().slice(0, 10)}.png`);
        });
    } catch {
        toast.error(locale.t('analytics.exportError'));
    } finally {
        exporting.value = false;
    }
}

function csvEscape(value: string): string {
    return `"${value.replace(/"/g, '""')}"`;
}

function exportCsv(): void {
    const header = ['ID', 'Subject', 'Status', 'Priority', 'Channel', 'Last message'];
    const rows = props.conversations.map((conversation) => [
        String(conversation.id),
        conversation.subject,
        conversation.status,
        conversation.priority,
        conversation.channel?.provider ?? '',
        conversation.last_message_at ?? '',
    ]);
    const csv = [header, ...rows].map((row) => row.map(csvEscape).join(',')).join('\r\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    downloadBlob(blob, `analytics-${new Date().toISOString().slice(0, 10)}.csv`);
}
</script>

<template>
    <div class="flex gap-2">
        <Button size="sm" variant="outline" type="button" :disabled="exporting || !target" @click="exportImage">
            <FileImage class="h-4 w-4" />{{ locale.t('analytics.exportImage') }}
        </Button>
        <Button size="sm" variant="outline" type="button" :disabled="!conversations.length" @click="exportCsv">
            <FileSpreadsheet class="h-4 w-4" />{{ locale.t('analytics.exportCsv') }}
        </Button>
    </div>
</template>
