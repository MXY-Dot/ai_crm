<script setup lang="ts">
import { ref } from 'vue';
import { MailWarning, Send } from '@lucide/vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '@/lib/apiClient';
import { Button } from '@/components/ui/button';

const busy = ref(false);

async function sendLink(): Promise<void> {
    busy.value = true;
    try {
        await apiRequest('/verify-email/send-link', { method: 'POST' });
        toast.success('Ссылка отправлена на почту');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось отправить ссылку');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary/30 bg-primary/10 px-4 py-3 text-sm">
        <div class="flex items-center gap-3 text-primary">
            <MailWarning class="size-4 shrink-0" />
            <span>Ограниченный доступ: вы можете смотреть CRM, но создавать и изменять данные нельзя, пока почта не подтверждена по ссылке.</span>
        </div>
        <Button variant="primary" size="sm" :disabled="busy" @click="sendLink">
            <Send class="h-4 w-4" />Подтвердить почту
        </Button>
    </div>
</template>
